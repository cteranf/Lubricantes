<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use App\Services\TerritoryNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OfficialTerritorySeeder extends Seeder
{
    private const EXPECTED_HASH = '3e05506c50da1982c13bb4b48cde1bb40c01c349e0151d84a878865561eeb41a';

    private const EXPECTED_HEADERS = ['department_code', 'department_name', 'province_code', 'province_name', 'district_code', 'district_name', 'ubigeo'];

    private const EXPECTED_ROWS = 1891;

    public function run(): void
    {
        $path = database_path('data/territories/inei_ubigeo_2022_1891.csv');
        $rows = $this->readAndValidate($path);
        $normalizer = app(TerritoryNormalizer::class);
        $summary = ['departments_created' => 0, 'departments_existing' => 0, 'provinces_created' => 0, 'provinces_existing' => 0, 'districts_created' => 0, 'districts_existing' => 0];

        DB::transaction(function () use ($rows, $normalizer, &$summary): void {
            $departments = Department::lockForUpdate()->get()->keyBy('code')->all();
            $provinces = Province::lockForUpdate()->get()->keyBy(fn (Province $province) => $province->department_id.'|'.$province->code)->all();
            $districts = District::lockForUpdate()->get()->keyBy(fn (District $district) => $district->province_id.'|'.$district->code)->all();
            $districtsByUbigeo = District::whereNotNull('ubigeo')->lockForUpdate()->get()->keyBy('ubigeo')->all();
            foreach ($rows as $row) {
                $department = $departments[$row['department_code']] ??= $this->resolveDepartment($row, $normalizer, $summary, $departments);
                $provinceKey = $department->id.'|'.$row['province_code'];
                $province = $provinces[$provinceKey] ??= $this->resolveProvince($department, $row, $normalizer, $summary, $provinces);
                $districtKey = $province->id.'|'.$row['district_code'];
                $this->resolveDistrict($province, $row, $normalizer, $summary, $districts, $districtsByUbigeo, $districtKey);
            }
        });

        $this->command?->info(json_encode($summary, JSON_UNESCAPED_UNICODE));
    }

    private function readAndValidate(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('No existe el CSV territorial oficial: '.$path);
        }
        if (! hash_equals(self::EXPECTED_HASH, hash_file('sha256', $path))) {
            throw new RuntimeException('El checksum SHA-256 del CSV territorial no coincide con el catálogo validado.');
        }
        $handle = fopen($path, 'rb');
        $headers = fgetcsv($handle);
        $headers[0] = (string) ($headers[0] ?? '');
        if (strncmp($headers[0], "\xEF\xBB\xBF", 3) === 0) {
            $headers[0] = substr($headers[0], 3);
        }
        if ($headers !== self::EXPECTED_HEADERS) {
            throw new RuntimeException('Las cabeceras del CSV territorial no coinciden exactamente con el contrato.');
        }
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count(self::EXPECTED_HEADERS)) {
                throw new RuntimeException('El CSV territorial contiene una fila con columnas inválidas.');
            }
            $rows[] = array_combine(self::EXPECTED_HEADERS, $values);
        }
        fclose($handle);
        if (count($rows) !== self::EXPECTED_ROWS) {
            throw new RuntimeException('El CSV territorial debe contener exactamente '.self::EXPECTED_ROWS.' filas.');
        }

        $seen = [];
        foreach ($rows as $row) {
            foreach (['department_code' => 2, 'province_code' => 4, 'district_code' => 6, 'ubigeo' => 6] as $field => $length) {
                if (! preg_match('/^\d{'.$length.'}$/', (string) $row[$field])) {
                    throw new RuntimeException("Código inválido en {$field}: {$row[$field]}");
                }
            }
            if (substr($row['province_code'], 0, 2) !== $row['department_code'] || substr($row['district_code'], 0, 4) !== $row['province_code'] || $row['district_code'] !== $row['ubigeo']) {
                throw new RuntimeException('Jerarquía de códigos incompatible en UBIGEO '.$row['ubigeo']);
            }
            foreach (['department_name', 'province_name', 'district_name'] as $field) {
                if (trim($row[$field]) === '') {
                    throw new RuntimeException("Nombre vacío en {$field} para {$row['ubigeo']}");
                }
            }
            if (isset($seen[$row['ubigeo']])) {
                throw new RuntimeException('UBIGEO duplicado: '.$row['ubigeo']);
            }
            $seen[$row['ubigeo']] = true;
        }

        return $rows;
    }

    private function resolveDepartment(array $row, TerritoryNormalizer $normalizer, array &$summary, array $departments): Department
    {
        $department = $departments[$row['department_code']] ?? null;
        if ($department) {
            $this->assertIdentity($department->name, $row['department_name'], $normalizer, 'departamento '.$row['department_code']);
            $summary['departments_existing']++;

            return $department;
        }
        if (collect($departments)->contains(fn (Department $item) => $item->normalized_name === $normalizer->normalize($row['department_name']))) {
            throw new RuntimeException('Conflicto: nombre de departamento con código diferente: '.$row['department_name']);
        }
        $summary['departments_created']++;

        return Department::create(['code' => $row['department_code'], 'name' => $row['department_name'], 'is_active' => true]);
    }

    private function resolveProvince(Department $department, array $row, TerritoryNormalizer $normalizer, array &$summary, array $provinces): Province
    {
        $province = $provinces[$department->id.'|'.$row['province_code']] ?? null;
        if ($province) {
            $this->assertIdentity($province->name, $row['province_name'], $normalizer, 'provincia '.$row['province_code']);
            $summary['provinces_existing']++;

            return $province;
        }
        if (collect($provinces)->contains(fn (Province $item) => (int) $item->department_id === (int) $department->id && $item->normalized_name === $normalizer->normalize($row['province_name']))) {
            throw new RuntimeException('Conflicto: nombre de provincia con código diferente: '.$row['province_name']);
        }
        $summary['provinces_created']++;

        return Province::create(['department_id' => $department->id, 'code' => $row['province_code'], 'name' => $row['province_name'], 'is_active' => true]);
    }

    private function resolveDistrict(Province $province, array $row, TerritoryNormalizer $normalizer, array &$summary, array &$districts, array &$districtsByUbigeo, string $districtKey): District
    {
        $district = $districts[$districtKey] ?? $districtsByUbigeo[$row['ubigeo']] ?? null;
        if ($district) {
            if ((int) $district->province_id !== (int) $province->id || $district->code !== $row['district_code'] || $district->ubigeo !== $row['ubigeo']) {
                throw new RuntimeException('Conflicto de jerarquía o UBIGEO: '.$row['ubigeo']);
            }
            $this->assertIdentity($district->name, $row['district_name'], $normalizer, 'distrito '.$row['district_code']);
            $summary['districts_existing']++;

            return $district;
        }
        if (collect($districts)->contains(fn (District $item) => (int) $item->province_id === (int) $province->id && $item->normalized_name === $normalizer->normalize($row['district_name']))) {
            throw new RuntimeException('Conflicto: nombre de distrito con código diferente: '.$row['district_name']);
        }
        $summary['districts_created']++;

        $created = District::create(['province_id' => $province->id, 'code' => $row['district_code'], 'name' => $row['district_name'], 'ubigeo' => $row['ubigeo'], 'is_active' => true]);
        $districts[$districtKey] = $created;
        $districtsByUbigeo[$row['ubigeo']] = $created;

        return $created;
    }

    private function assertIdentity(string $existing, string $incoming, TerritoryNormalizer $normalizer, string $label): void
    {
        if ($normalizer->normalize($existing) !== $normalizer->normalize($incoming)) {
            throw new RuntimeException('Conflicto de nombre en '.$label.': catálogo existente no coincide.');
        }
    }
}
