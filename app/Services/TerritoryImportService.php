<?php

namespace App\Services;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use App\Models\TerritoryImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TerritoryImportService
{
    public const HEADERS = [
        'department_code', 'department_name', 'province_code', 'province_name',
        'district_code', 'district_name', 'ubigeo',
    ];

    public function __construct(private TerritoryNormalizer $normalizer) {}

    public function preview(UploadedFile $file, User $user): array
    {
        $this->cleanupExpiredPreviews();
        $this->validateFile($file);
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => ['El archivo debe estar codificado en UTF-8.']]);
        }

        $rows = $this->parseCsv($contents);
        $analysis = $this->analyze($rows);
        $id = (string) Str::uuid();
        $checksum = hash('sha256', $contents);
        $payload = [
            'user_id' => $user->id,
            'original_filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
            'checksum' => $checksum,
            'created_at' => now()->toIso8601String(),
            'rows' => $rows,
        ];
        Storage::disk('local')->put($this->previewPath($id), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $this->publicAnalysis($analysis) + [
            'preview_token' => $this->token($id, $user->id),
            'checksum' => $checksum,
            'original_filename' => $payload['original_filename'],
            'can_confirm' => ! $analysis['has_blockers'],
        ];
    }

    public function confirm(string $token, User $user): TerritoryImport
    {
        [$id, $payload] = $this->loadPreview($token, $user);

        try {
            return Cache::lock('territory-catalog-import', 60)->block(10, function () use ($payload, $user) {
                return DB::transaction(function () use ($payload, $user) {
                    $analysis = $this->analyze($payload['rows'], true);
                    if ($analysis['has_blockers']) {
                        throw ValidationException::withMessages(['preview_token' => ['El catálogo cambió o la vista previa contiene conflictos. Genere una nueva vista previa.']]);
                    }

                    $startedAt = now();
                    $createdDepartments = 0;
                    $createdProvinces = 0;
                    $createdDistricts = 0;
                    $seenDepartments = [];
                    $seenProvinces = [];
                    $seenDistricts = [];

                    foreach ($payload['rows'] as $row) {
                        $department = Department::where('code', $row['department_code'])->lockForUpdate()->first();
                        if (! $department) {
                            $department = Department::create(['code' => $row['department_code'], 'name' => $row['department_name'], 'is_active' => true]);
                            $createdDepartments++;
                        }
                        $seenDepartments[$department->id] = true;

                        $province = Province::where('department_id', $department->id)->where('code', $row['province_code'])->lockForUpdate()->first();
                        if (! $province) {
                            $province = Province::create(['department_id' => $department->id, 'code' => $row['province_code'], 'name' => $row['province_name'], 'is_active' => true]);
                            $createdProvinces++;
                        }
                        $seenProvinces[$province->id] = true;

                        $district = $row['ubigeo'] ? District::where('ubigeo', $row['ubigeo'])->lockForUpdate()->first() : null;
                        $district ??= District::where('province_id', $province->id)->where('code', $row['district_code'])->lockForUpdate()->first();
                        if (! $district) {
                            $district = District::create([
                                'province_id' => $province->id, 'code' => $row['district_code'],
                                'name' => $row['district_name'], 'ubigeo' => $row['ubigeo'], 'is_active' => true,
                            ]);
                            $createdDistricts++;
                        } elseif (! $district->ubigeo && $row['ubigeo']) {
                            $district->update(['ubigeo' => $row['ubigeo']]);
                        }
                        $seenDistricts[$district->id] = true;
                    }

                    $unchangedRows = collect($analysis['rows'])->where('status', 'unchanged')->count();

                    return TerritoryImport::create([
                        'user_id' => $user->id,
                        'original_filename' => $payload['original_filename'],
                        'checksum' => $payload['checksum'],
                        'status' => TerritoryImport::STATUS_COMPLETED,
                        'total_rows' => count($payload['rows']),
                        'created_departments' => $createdDepartments,
                        'created_provinces' => $createdProvinces,
                        'created_districts' => $createdDistricts,
                        'unchanged_rows' => $unchangedRows,
                        'conflict_rows' => 0,
                        'error_rows' => 0,
                        'summary' => [
                            'departments_detected' => count($seenDepartments),
                            'provinces_detected' => count($seenProvinces),
                            'districts_detected' => count($seenDistricts),
                            'message' => $createdDepartments + $createdProvinces + $createdDistricts === 0 ? 'Sin cambios' : 'Importación completada',
                        ],
                        'started_at' => $startedAt,
                        'completed_at' => now(),
                    ]);
                });
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['preview_token' => ['Existe otra importación territorial en proceso. Intente nuevamente en unos segundos.']]);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['preview_token' => ['Otra importación modificó el catálogo. Genere una nueva vista previa.']]);
            }
            throw $exception;
        } finally {
            Storage::disk('local')->delete($this->previewPath($id));
        }
    }

    public function report(string $token, User $user): string
    {
        [, $payload] = $this->loadPreview($token, $user);
        $analysis = $this->analyze($payload['rows']);
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['row', 'status', 'message', ...self::HEADERS]);
        foreach ($analysis['rows'] as $row) {
            if (! in_array($row['status'], ['conflict', 'error', 'duplicate'], true)) {
                continue;
            }
            fputcsv($stream, [$row['row'], $row['status'], implode(' | ', $row['messages']), ...array_map(fn ($header) => $row['data'][$header] ?? '', self::HEADERS)]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }

    private function validateFile(UploadedFile $file): void
    {
        $allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => ['Solo se admite un archivo CSV UTF-8. XLSX está pendiente de una dependencia especializada.']]);
        }
        if (! in_array((string) $file->getMimeType(), $allowedMimes, true)) {
            throw ValidationException::withMessages(['file' => ['El tipo MIME del archivo no corresponde a un CSV permitido.']]);
        }
        if ($file->getSize() > config('territory_import.max_kilobytes') * 1024) {
            throw ValidationException::withMessages(['file' => ['El archivo supera el tamaño máximo permitido.']]);
        }
    }

    private function parseCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (! is_array($headers)) {
            throw ValidationException::withMessages(['file' => ['El archivo CSV está vacío.']]);
        }
        $headers = array_map(fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $headers);
        if (count($headers) !== count(array_unique($headers))) {
            throw ValidationException::withMessages(['file' => ['El archivo contiene cabeceras duplicadas.']]);
        }
        $missing = array_values(array_diff(self::HEADERS, $headers));
        if ($missing) {
            throw ValidationException::withMessages(['file' => ['Faltan las cabeceras requeridas: '.implode(', ', $missing).'.']]);
        }
        $indexes = array_flip($headers);
        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($stream)) !== false) {
            $rowNumber++;
            if (count($rows) >= config('territory_import.max_rows')) {
                throw ValidationException::withMessages(['file' => ['El archivo supera la cantidad máxima de filas permitida.']]);
            }
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $row = ['_row' => $rowNumber];
            foreach (self::HEADERS as $header) {
                $value = $this->cleanCell((string) ($values[$indexes[$header]] ?? ''));
                $row[$header] = str_ends_with($header, '_code') ? $this->normalizer->normalizeCode($value) : ($header === 'ubigeo' ? preg_replace('/\s+/u', '', $value) : $this->normalizer->cleanName($value));
            }
            $rows[] = $row;
        }
        fclose($stream);
        if (! $rows) {
            throw ValidationException::withMessages(['file' => ['El archivo no contiene filas territoriales.']]);
        }

        return $rows;
    }

    private function analyze(array $rows, bool $lock = false): array
    {
        $departments = $this->queryMap(Department::query(), 'code', $lock);
        $provinces = $this->queryMap(
            Province::query()->with('department'),
            fn (Province $province) => $province->department?->code.'|'.$province->code,
            $lock
        );
        $districtsByCode = $this->queryMap(
            District::query()->with('province.department'),
            fn (District $district) => $district->province?->department?->code.'|'.$district->province?->code.'|'.$district->code,
            $lock
        );
        $districtsByUbigeo = $this->queryMap(District::query()->with('province.department')->whereNotNull('ubigeo'), 'ubigeo', $lock);
        $departmentsByName = collect($departments)->keyBy('normalized_name');
        $provincesByName = collect($provinces)->keyBy(fn (Province $province) => $province->department?->code.'|'.$province->normalized_name);
        $districtsByName = collect($districtsByCode)->keyBy(fn (District $district) => $district->province?->department?->code.'|'.$district->province?->code.'|'.$district->normalized_name);
        $seenRows = [];
        $fileDepartments = [];
        $fileProvinces = [];
        $fileDistricts = [];
        $fileDistrictUbigeos = [];
        $fileUbigeos = [];
        $resultRows = [];
        $newDepartments = [];
        $newProvinces = [];
        $newDistricts = [];

        foreach ($rows as $row) {
            $messages = [];
            $status = 'unchanged';
            foreach (array_slice(self::HEADERS, 0, 6) as $required) {
                if ($row[$required] === '') {
                    $messages[] = "El campo {$required} es obligatorio.";
                    $status = 'error';
                }
            }
            foreach (['department_code', 'province_code', 'district_code'] as $codeField) {
                if ($row[$codeField] !== '' && ! preg_match('/^[A-Z0-9_-]{1,50}$/', $row[$codeField])) {
                    $messages[] = "El campo {$codeField} tiene un formato inválido.";
                    $status = 'error';
                }
            }
            foreach (['department_name', 'province_name', 'district_name'] as $nameField) {
                if (mb_strlen($row[$nameField]) > 100) {
                    $messages[] = "El campo {$nameField} supera los 100 caracteres.";
                    $status = 'error';
                }
            }
            if ($row['ubigeo'] !== '' && ! preg_match('/^\d{6}$/', $row['ubigeo'])) {
                $messages[] = 'El UBIGEO debe contener exactamente 6 dígitos.';
                $status = 'error';
            }

            $rowKey = implode('|', array_map(fn ($header) => $row[$header], self::HEADERS));
            if (isset($seenRows[$rowKey])) {
                $messages[] = "Fila duplicada; coincide con la fila {$seenRows[$rowKey]}.";
                $status = 'duplicate';
            }
            $seenRows[$rowKey] ??= $row['_row'];

            $departmentIdentity = $this->normalizer->normalize($row['department_name']);
            $knownDepartment = $fileDepartments[$row['department_code']] ?? null;
            if ($knownDepartment !== null && $knownDepartment !== $departmentIdentity) {
                $messages[] = 'El código de departamento tiene nombres diferentes dentro del archivo.';
                $status = 'conflict';
            }
            $fileDepartments[$row['department_code']] ??= $departmentIdentity;

            $provinceIdentity = $row['department_code'].'|'.$this->normalizer->normalize($row['province_name']);
            $knownProvince = $fileProvinces[$row['province_code']] ?? null;
            if ($knownProvince !== null && $knownProvince !== $provinceIdentity) {
                $messages[] = 'El código de provincia tiene otro departamento o nombre dentro del archivo.';
                $status = 'conflict';
            }
            $fileProvinces[$row['province_code']] ??= $provinceIdentity;

            $districtIdentity = $row['department_code'].'|'.$row['province_code'].'|'.$this->normalizer->normalize($row['district_name']);
            $knownDistrict = $fileDistricts[$row['district_code']] ?? null;
            if ($knownDistrict !== null && $knownDistrict !== $districtIdentity) {
                $messages[] = 'El código de distrito tiene otra provincia o nombre dentro del archivo.';
                $status = 'conflict';
            }
            $fileDistricts[$row['district_code']] ??= $districtIdentity;
            if (array_key_exists($row['district_code'], $fileDistrictUbigeos) && $fileDistrictUbigeos[$row['district_code']] !== $row['ubigeo']) {
                $messages[] = 'El código de distrito tiene UBIGEO diferentes dentro del archivo.';
                $status = 'conflict';
            }
            $fileDistrictUbigeos[$row['district_code']] ??= $row['ubigeo'];
            if ($row['ubigeo'] !== '') {
                $ubigeoIdentity = $row['district_code'].'|'.$districtIdentity;
                $knownUbigeo = $fileUbigeos[$row['ubigeo']] ?? null;
                if ($knownUbigeo !== null && $knownUbigeo !== $ubigeoIdentity) {
                    $messages[] = 'El UBIGEO identifica distritos diferentes dentro del archivo.';
                    $status = 'conflict';
                }
                $fileUbigeos[$row['ubigeo']] ??= $ubigeoIdentity;
            }

            $newEntity = false;
            $department = $departments[$row['department_code']] ?? null;
            if ($department && $department->normalized_name !== $departmentIdentity) {
                $messages[] = 'El código de departamento existente tiene un nombre sustancialmente diferente.';
                $status = 'conflict';
            } elseif (! $department) {
                $newEntity = true;
                $sameName = $departmentsByName->get($departmentIdentity);
                if ($sameName && $sameName->code !== $row['department_code']) {
                    $messages[] = 'Ya existe un departamento con el mismo nombre normalizado y otro código.';
                    $status = 'conflict';
                }
            }
            $provinceKey = $row['department_code'].'|'.$row['province_code'];
            $province = $provinces[$provinceKey] ?? null;
            if ($province && ($province->department?->code !== $row['department_code'] || $province->normalized_name !== $this->normalizer->normalize($row['province_name']))) {
                $messages[] = 'La provincia existente pertenece a otro departamento o tiene otro nombre.';
                $status = 'conflict';
            } elseif (! $province) {
                $newEntity = true;
                $sameName = $provincesByName->get($provinceIdentity);
                if ($sameName && $sameName->code !== $row['province_code']) {
                    $messages[] = 'Ya existe una provincia con el mismo nombre normalizado en el departamento y otro código.';
                    $status = 'conflict';
                }
            }
            $districtKey = $row['department_code'].'|'.$row['province_code'].'|'.$row['district_code'];
            $districtByCode = $districtsByCode[$districtKey] ?? null;
            $districtByUbigeo = $row['ubigeo'] !== '' ? ($districtsByUbigeo[$row['ubigeo']] ?? null) : null;
            if ($districtByCode && $districtByUbigeo && $districtByCode->id !== $districtByUbigeo->id) {
                $messages[] = 'El código de distrito y el UBIGEO corresponden a registros existentes distintos.';
                $status = 'conflict';
            }
            $district = $districtByUbigeo ?: $districtByCode;
            if ($district && ($district->province?->code !== $row['province_code'] || $district->code !== $row['district_code'] || $district->normalized_name !== $this->normalizer->normalize($row['district_name']))) {
                $messages[] = 'El distrito existente pertenece a otra provincia o tiene identidad diferente.';
                $status = 'conflict';
            } elseif ($district && $district->ubigeo && $row['ubigeo'] !== '' && $district->ubigeo !== $row['ubigeo']) {
                $messages[] = 'El distrito existente ya tiene un UBIGEO diferente.';
                $status = 'conflict';
            } elseif (! $district) {
                $newEntity = true;
                $sameName = $districtsByName->get($districtIdentity);
                if ($sameName && $sameName->code !== $row['district_code']) {
                    $messages[] = 'Ya existe un distrito con el mismo nombre normalizado en la provincia y otro código.';
                    $status = 'conflict';
                }
            }
            if ($status === 'unchanged' && $newEntity) {
                $status = 'new';
            } elseif ($status === 'unchanged' && $district && ! $district->ubigeo && $row['ubigeo'] !== '') {
                $status = 'update_ubigeo';
            }

            if ($status === 'new') {
                if (! $department) {
                    $newDepartments[$row['department_code']] = true;
                }
                if (! $province) {
                    $newProvinces[$row['province_code']] = true;
                }
                if (! $district) {
                    $newDistricts[$row['district_code']] = true;
                }
            }

            $resultRows[] = ['row' => $row['_row'], 'status' => $status, 'messages' => $messages, 'data' => array_diff_key($row, ['_row' => true])];
        }

        $statuses = collect($resultRows)->countBy('status');

        return [
            'rows' => $resultRows,
            'total_rows' => count($rows),
            'departments_detected' => count(array_unique(array_column($rows, 'department_code'))),
            'provinces_detected' => count(array_unique(array_column($rows, 'province_code'))),
            'districts_detected' => count(array_unique(array_column($rows, 'district_code'))),
            'new_departments' => count($newDepartments),
            'new_provinces' => count($newProvinces),
            'new_districts' => count($newDistricts),
            'name_updates' => 0,
            'new_rows' => $statuses->get('new', 0),
            'unchanged_rows' => $statuses->get('unchanged', 0),
            'updated_ubigeos' => $statuses->get('update_ubigeo', 0),
            'duplicate_rows' => $statuses->get('duplicate', 0),
            'conflict_rows' => $statuses->get('conflict', 0),
            'error_rows' => $statuses->get('error', 0),
            'has_blockers' => $statuses->get('duplicate', 0) + $statuses->get('conflict', 0) + $statuses->get('error', 0) > 0,
        ];
    }

    private function publicAnalysis(array $analysis): array
    {
        $sample = array_slice($analysis['rows'], 0, config('territory_import.preview_rows'));
        unset($analysis['rows'], $analysis['has_blockers']);

        return $analysis + ['sample' => $sample];
    }

    private function queryMap($query, string|callable $key, bool $lock): array
    {
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->keyBy($key)->all();
    }

    private function cleanCell(string $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value);
        $value = trim($value);
        if ($value !== '' && preg_match('/^[=+\-@]/u', $value)) {
            throw ValidationException::withMessages(['file' => ['El archivo contiene una celda que podría ejecutarse como fórmula.']]);
        }

        return $value;
    }

    private function token(string $id, int $userId): string
    {
        return $id.'.'.hash_hmac('sha256', $id.'|'.$userId, (string) config('app.key'));
    }

    private function loadPreview(string $token, User $user): array
    {
        [$id, $signature] = array_pad(explode('.', $token, 2), 2, null);
        if (! Str::isUuid((string) $id) || ! is_string($signature) || ! hash_equals($this->token($id, $user->id), $token)) {
            throw ValidationException::withMessages(['preview_token' => ['La vista previa no es válida o no pertenece al administrador autenticado.']]);
        }
        $path = $this->previewPath($id);
        if (! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['preview_token' => ['La vista previa expiró. Cargue el archivo nuevamente.']]);
        }
        $payload = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        $createdAt = CarbonImmutable::parse($payload['created_at']);
        if ((int) $payload['user_id'] !== $user->id || $createdAt->lt(now()->subMinutes(config('territory_import.preview_ttl_minutes')))) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['preview_token' => ['La vista previa expiró. Cargue el archivo nuevamente.']]);
        }

        return [$id, $payload];
    }

    private function previewPath(string $id): string
    {
        return 'territory-import-previews/'.$id.'.json';
    }

    private function cleanupExpiredPreviews(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files('territory-import-previews') as $path) {
            if ($disk->lastModified($path) < now()->subMinutes(config('territory_import.preview_ttl_minutes'))->getTimestamp()) {
                $disk->delete($path);
            }
        }
    }
}
