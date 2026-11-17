<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use DOMDocument;
use DOMElement;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Phar;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

class InventoryReceiptImportService
{
    private const HEADERS = ['sku', 'cantidad'];

    public function preview(UploadedFile $file, int $warehouseId, array $existingLines): array
    {
        $warehouse = Warehouse::query()->with('branch:id,is_active')->find($warehouseId);
        if (! $warehouse || ! $warehouse->is_active || ! $warehouse->branch?->is_active) {
            throw new InvalidArgumentException('El almacén seleccionado no está disponible.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        try {
            $rawRows = match ($extension) {
                'csv' => $this->readCsv($file),
                'xlsx' => $this->readXlsx($file),
                default => throw new InvalidArgumentException('El archivo debe tener extensión .csv o .xlsx.'),
            };
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('El archivo no se pudo leer o no tiene un formato válido.');
        }

        if ($rawRows === []) {
            throw new InvalidArgumentException('El archivo no contiene filas para importar.');
        }

        $existingIds = [];
        $existingUnits = 0;
        foreach ($existingLines as $line) {
            $productId = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1) {
                throw new InvalidArgumentException('Corrige las líneas existentes antes de importar.');
            }
            $existingIds[] = (int) $productId;
            $maxUnits = (int) config('inventory_receipts.max_total_units', 100000);
            if ($existingUnits > $maxUnits || (int) $quantity > $maxUnits - $existingUnits) {
                $existingUnits = $maxUnits + 1;
            } else {
                $existingUnits += (int) $quantity;
            }
        }

        $existingProducts = Product::query()->whereIn('id', array_unique($existingIds))->get(['id', 'name', 'sku', 'is_active'])->keyBy('id');
        if (count($existingIds) !== count(array_unique($existingIds))) {
            throw new InvalidArgumentException('El borrador ya contiene productos duplicados. Corrígelo antes de importar.');
        }
        if (count($existingIds) !== $existingProducts->count()) {
            throw new InvalidArgumentException('Una línea existente ya no corresponde a un producto disponible.');
        }

        $existingSkuKeys = [];
        foreach ($existingProducts as $product) {
            $existingSkuKeys[$this->skuKey((string) $product->sku)] = (int) $product->id;
        }

        $prepared = [];
        $seenFileSkus = [];
        $lookupKeys = [];
        foreach ($rawRows as $raw) {
            $sku = trim((string) ($raw['sku'] ?? ''));
            $quantityValue = trim((string) ($raw['quantity'] ?? ''));
            $errors = [];
            $key = $this->skuKey($sku);

            if ($sku === '') {
                $errors[] = 'El SKU está vacío.';
            } elseif (mb_strlen($sku, 'UTF-8') > 64 || preg_match('/[\x00-\x1F\x7F]/u', $sku)) {
                $errors[] = 'El SKU no tiene un formato válido.';
            } elseif (isset($seenFileSkus[$key])) {
                $errors[] = 'El SKU está duplicado en el archivo.';
            } else {
                $seenFileSkus[$key] = true;
                $lookupKeys[$key] = $key;
            }

            $quantity = null;
            if (! preg_match('/\A[0-9]+\z/D', $quantityValue) || $quantityValue === '' || ltrim($quantityValue, '0') === '') {
                $errors[] = 'La cantidad debe ser un entero positivo.';
            } elseif (strlen(ltrim($quantityValue, '0')) > 9 || (int) $quantityValue > (int) config('inventory_receipts.max_total_units', 100000)) {
                $errors[] = 'La cantidad supera el máximo de unidades permitido.';
            } else {
                $quantity = (int) $quantityValue;
            }

            $prepared[] = [
                'row' => (int) $raw['row'],
                'sku' => $sku,
                'sku_key' => $key,
                'quantity' => $quantity,
                'product_id' => null,
                'product_name' => null,
                'errors' => $errors,
            ];
        }

        $productsBySku = Product::query()
            ->whereIn(\Illuminate\Support\Facades\DB::raw('UPPER(sku)'), array_values($lookupKeys))
            ->get(['id', 'name', 'sku', 'is_active'])
            ->keyBy(fn (Product $product) => $this->skuKey((string) $product->sku));

        foreach ($prepared as &$row) {
            if ($row['sku'] === '' || in_array('El SKU está duplicado en el archivo.', $row['errors'], true)) {
                continue;
            }
            $product = $productsBySku->get($row['sku_key']);
            if (! $product || ! $product->is_active) {
                $row['errors'][] = 'No existe un producto activo con este SKU.';

                continue;
            }
            if (isset($existingSkuKeys[$row['sku_key']])) {
                $row['errors'][] = 'El producto ya está en las líneas del borrador.';

                continue;
            }
            $row['product_id'] = (int) $product->id;
            $row['product_name'] = $product->name;
        }
        unset($row);

        $maxLines = (int) config('inventory_receipts.max_lines', 100);
        $maxUnits = (int) config('inventory_receipts.max_total_units', 100000);
        if (count($existingIds) > $maxLines) {
            throw new InvalidArgumentException('El borrador ya supera el máximo de productos permitido.');
        }
        if ($existingUnits > $maxUnits) {
            throw new InvalidArgumentException('El borrador ya supera el máximo de unidades permitido.');
        }

        $acceptedLines = count($existingIds);
        $combinedUnits = $existingUnits;
        foreach ($prepared as &$row) {
            if ($row['errors'] !== []) {
                continue;
            }
            if ($acceptedLines >= $maxLines) {
                $row['errors'][] = 'La importación supera el límite combinado de 100 productos.';

                continue;
            }
            if ($row['quantity'] > $maxUnits - $combinedUnits) {
                $row['errors'][] = 'La importación supera el límite combinado de 100 000 unidades.';

                continue;
            }
            $acceptedLines++;
            $combinedUnits += $row['quantity'];
        }
        unset($row);

        $validRows = array_values(array_filter($prepared, fn (array $row) => $row['errors'] === []));
        $canImport = $validRows !== [] && count($validRows) === count($prepared);

        return [
            'can_import' => $canImport,
            'rows' => array_map(fn (array $row) => [
                'row' => $row['row'],
                'sku' => $row['sku'],
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'errors' => array_values(array_unique($row['errors'])),
            ], $prepared),
            'totals' => [
                'existing_products' => count($existingIds),
                'importable_products' => count($validRows),
                'total_products' => count($existingIds) + count($validRows),
                'total_units' => $existingUnits + array_sum(array_map(fn (array $row) => (int) ($row['quantity'] ?? 0), $validRows)),
                'max_products' => $maxLines,
                'max_units' => $maxUnits,
            ],
        ];
    }

    private function readCsv(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents) || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('El CSV debe estar codificado en UTF-8.');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $firstLine = strtok($contents, "\r\n");
        if (! is_string($firstLine)) {
            throw new InvalidArgumentException('El CSV está vacío.');
        }

        $delimiter = null;
        $headerIndexes = null;
        foreach ([',', ';'] as $candidate) {
            $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), str_getcsv($firstLine, $candidate, '"', ''));
            if (count($headers) === 2 && in_array(self::HEADERS[0], $headers, true) && in_array(self::HEADERS[1], $headers, true)) {
                $delimiter = $candidate;
                $headerIndexes = array_flip($headers);
                break;
            }
        }
        if ($delimiter === null || $headerIndexes === null) {
            throw new InvalidArgumentException('La primera fila debe contener únicamente las columnas sku y cantidad.');
        }

        $stream = fopen('php://temp', 'r+');
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('No se pudo leer el archivo CSV.');
        }
        try {
            fwrite($stream, $contents);
            rewind($stream);
            fgetcsv($stream, null, $delimiter, '"', '');
            $rows = [];
            $rowNumber = 1;
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $rowNumber++;
                if ($values === [null] || $values === []) {
                    continue;
                }
                $sku = (string) ($values[$headerIndexes['sku']] ?? '');
                $quantity = (string) ($values[$headerIndexes['cantidad']] ?? '');
                if (trim($sku) === '' && trim($quantity) === '') {
                    continue;
                }
                $rows[] = ['row' => $rowNumber, 'sku' => $sku, 'quantity' => $quantity];
                if (count($rows) > (int) config('inventory_receipts.max_import_rows', 500)) {
                    throw new InvalidArgumentException('El archivo supera el máximo de 500 filas de importación.');
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function readXlsx(UploadedFile $file): array
    {
        if (! extension_loaded('phar') || ! class_exists(PharData::class) || ! class_exists(DOMDocument::class)) {
            throw new InvalidArgumentException('El servidor no tiene habilitado el lector XLSX requerido.');
        }

        try {
            $archive = new PharData($file->getRealPath(), 0, null, Phar::ZIP);
        } catch (Throwable) {
            throw new InvalidArgumentException('El archivo XLSX no es un libro válido.');
        }

        $entryCount = 0;
        $expandedBytes = 0;
        $maxEntries = (int) config('inventory_receipts.max_import_archive_entries', 500);
        $maxBytes = (int) config('inventory_receipts.max_import_expanded_bytes', 10485760);
        foreach (new RecursiveIteratorIterator($archive) as $entryName => $entry) {
            $entryCount++;
            $normalizedName = str_replace('\\', '/', (string) $entryName);
            if ($entryCount > $maxEntries || str_starts_with($normalizedName, '/')
                || in_array('..', explode('/', $normalizedName), true) || $entry->isLink()) {
                throw new InvalidArgumentException('El XLSX contiene una estructura no permitida.');
            }
            $expandedBytes += (int) $entry->getSize();
            if ($expandedBytes > $maxBytes) {
                throw new InvalidArgumentException('El contenido expandido del XLSX supera el límite permitido.');
            }
        }

        $workbook = $this->readArchiveXml($archive, 'xl/workbook.xml');
        $relationships = $this->readArchiveXml($archive, 'xl/_rels/workbook.xml.rels');
        $sheets = $workbook->getElementsByTagNameNS('*', 'sheet');
        if ($sheets->length < 1 || ! $sheets->item(0) instanceof DOMElement) {
            throw new InvalidArgumentException('El XLSX no contiene una hoja de cálculo.');
        }
        $relationId = $sheets->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $target = null;
        foreach ($relationships->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
            if ($relationship instanceof DOMElement && $relationship->getAttribute('Id') === $relationId) {
                $target = $relationship->getAttribute('Target');
                break;
            }
        }
        if (! is_string($target) || $target === '' || str_contains($target, '..') || str_contains($target, '\\')) {
            throw new InvalidArgumentException('La primera hoja del XLSX tiene una referencia no válida.');
        }
        $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        if (! str_starts_with($sheetPath, 'xl/')) {
            throw new InvalidArgumentException('La primera hoja del XLSX está fuera de la carpeta de documentos.');
        }
        $sheet = $this->readArchiveXml($archive, $sheetPath);
        $sharedStrings = $this->readSharedStrings($archive);
        $sheetRows = $sheet->getElementsByTagNameNS('*', 'row');
        if ($sheetRows->length < 1 || ! $sheetRows->item(0) instanceof DOMElement) {
            throw new InvalidArgumentException('La primera hoja no contiene encabezados.');
        }

        $firstRow = $sheetRows->item(0);
        $header = [];
        foreach ($firstRow->getElementsByTagNameNS('*', 'c') as $cell) {
            if (! $cell instanceof DOMElement) {
                continue;
            }
            $column = $this->columnIndex($cell->getAttribute('r'));
            $header[$column] = $this->normalizeHeader($this->xlsxCellValue($cell, $sharedStrings));
        }
        ksort($header);
        if (count($header) !== 2 || array_diff(self::HEADERS, array_values($header)) !== []) {
            throw new InvalidArgumentException('La primera fila debe contener únicamente las columnas sku y cantidad.');
        }
        $columnIndexes = array_flip($header);

        $rows = [];
        foreach ($sheetRows as $sheetRow) {
            if (! $sheetRow instanceof DOMElement || $sheetRow->isSameNode($firstRow)) {
                continue;
            }
            $rowNumber = (int) $sheetRow->getAttribute('r');
            $values = [];
            foreach ($sheetRow->getElementsByTagNameNS('*', 'c') as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $column = $this->columnIndex($cell->getAttribute('r'));
                $values[$column] = $this->xlsxCellValue($cell, $sharedStrings);
            }
            $sku = (string) ($values[$columnIndexes['sku']] ?? '');
            $quantity = (string) ($values[$columnIndexes['cantidad']] ?? '');
            if (trim($sku) === '' && trim($quantity) === '') {
                continue;
            }
            $rows[] = ['row' => $rowNumber ?: count($rows) + 2, 'sku' => $sku, 'quantity' => $quantity];
            if (count($rows) > (int) config('inventory_receipts.max_import_rows', 500)) {
                throw new InvalidArgumentException('El archivo supera el máximo de 500 filas de importación.');
            }
        }

        return $rows;
    }

    private function readSharedStrings(PharData $archive): array
    {
        if (! isset($archive['xl/sharedStrings.xml'])) {
            return [];
        }
        $xml = $this->readArchiveXml($archive, 'xl/sharedStrings.xml');
        $strings = [];
        foreach ($xml->getElementsByTagNameNS('*', 'si') as $item) {
            $value = '';
            foreach ($item->getElementsByTagNameNS('*', 't') as $text) {
                $value .= $text->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private function readArchiveXml(PharData $archive, string $path): DOMDocument
    {
        if (! isset($archive[$path])) {
            throw new InvalidArgumentException('El XLSX está incompleto o no contiene una hoja válida.');
        }
        $contents = $archive[$path]->getContent();
        if (! is_string($contents) || strlen($contents) > (int) config('inventory_receipts.max_import_expanded_bytes', 10485760)
            || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $contents)) {
            throw new InvalidArgumentException('El XML del XLSX no es válido.');
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new InvalidArgumentException('El XML del XLSX no es válido.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function xlsxCellValue(DOMElement $cell, array $sharedStrings): string
    {
        if ($cell->getElementsByTagNameNS('*', 'f')->length > 0) {
            throw new InvalidArgumentException('No se aceptan fórmulas en las columnas importadas.');
        }
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $value = '';
            foreach ($cell->getElementsByTagNameNS('*', 't') as $text) {
                $value .= $text->textContent;
            }

            return $value;
        }
        $valueNode = $cell->getElementsByTagNameNS('*', 'v')->item(0);
        $value = $valueNode?->textContent ?? '';
        if ($type === 's') {
            $index = filter_var($value, FILTER_VALIDATE_INT);
            if ($index === false || ! array_key_exists($index, $sharedStrings)) {
                throw new InvalidArgumentException('El XLSX referencia un texto compartido inválido.');
            }

            return $sharedStrings[$index];
        }

        return $value;
    }

    private function columnIndex(string $cellReference): int
    {
        if (! preg_match('/\A([A-Z]+)[0-9]+\z/i', $cellReference, $matches)) {
            throw new InvalidArgumentException('El XLSX contiene una referencia de celda inválida.');
        }
        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower(trim($header), 'UTF-8');
    }

    private function skuKey(string $sku): string
    {
        return mb_strtoupper(trim($sku), 'UTF-8');
    }
}
