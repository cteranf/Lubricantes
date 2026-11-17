<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryReceiptsFrontendTest extends TestCase
{
    public function test_inventory_exposes_receipt_workflow_using_current_api_contracts(): void
    {
        $inventory = file_get_contents(resource_path('js/views/admin/Inventory.vue'));
        $component = file_get_contents(resource_path('js/components/admin/InventoryReceipts.vue'));

        $this->assertIsString($inventory);
        $this->assertIsString($component);
        $this->assertStringContainsString('Recepciones masivas', $inventory);
        $this->assertStringContainsString('<InventoryReceipts v-else', $inventory);
        $this->assertStringContainsString('@confirmed="refreshAfterReceipt"', $inventory);
        $this->assertStringContainsString('const refreshAfterReceipt=()=>{loadStock();loadMovements()}', $inventory);
        $this->assertStringContainsString("const showReceiptHistory=()=>{tab.value='history';loadMovements()}", $inventory);

        $this->assertStringContainsString("api.get('/admin/inventory-receipts'", $component);
        $this->assertStringContainsString("api.get('/admin/inventory-receipts/import-template'", $component);
        $this->assertStringContainsString("api.post('/admin/inventory-receipts/import-preview', formData", $component);
        $this->assertStringContainsString('api.get(`/admin/inventory-receipts/${id}`)', $component);
        $this->assertStringContainsString("api.post('/admin/inventory-receipts', requestPayload)", $component);
        $this->assertStringContainsString('api.put(`/admin/inventory-receipts/${receiptId}`, { ...requestPayload, version: receipt.value.version })', $component);
        $this->assertStringContainsString('api.post(`/admin/inventory-receipts/${receiptId}/confirm`, { version: receipt.value.version })', $component);
        $this->assertStringContainsString('api.post(`/admin/inventory-receipts/${receipt.value.id}/cancel`, { version: receipt.value.version })', $component);
        $this->assertStringContainsString("api.get('/admin/warehouses/options')", $component);
        $this->assertStringContainsString("api.get('/admin/products'", $component);
        $this->assertStringContainsString("is_active: '1', per_page: '15'", $component);

        $this->assertStringContainsString('const MAX_LINES = 100;', $component);
        $this->assertStringContainsString('const MAX_TOTAL_UNITS = 100000;', $component);
        $this->assertStringContainsString('function isDuplicate(productId)', $component);
        $this->assertStringContainsString("isDuplicate(product.id) ? 'Ya agregado' : 'Agregar'", $component);
        $this->assertStringContainsString('Number.isSafeInteger(Number(value)) && Number(value) > 0', $component);
        $this->assertStringContainsString('lines: lines.value.map(line => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) }))', $component);

        $this->assertStringContainsString('error?.response?.status === 409 && !isNew', $component);
        $this->assertStringContainsString('Cargar versión vigente', $component);
        $this->assertStringContainsString('no se sobrescribieron tus cambios', $component);
        $this->assertStringContainsString("recoverReceiptState('confirm', null, receiptId)", $component);
        $this->assertStringContainsString('api.get(`/admin/inventory-receipts/${receiptId}`)', $component);
        $this->assertStringContainsString('retryConfirmAvailable.value = true', $component);
        $this->assertStringContainsString('ambiguousCreate.value = true', $component);
        $this->assertStringContainsString('if (ambiguousCreate.value && Number(page) === 1) ambiguousCreateListChecked.value = true', $component);
        $this->assertStringContainsString('no se enviará otro borrador automáticamente', $component);
        $this->assertStringContainsString('No aparece: conservar formulario y permitir reintento', $component);
        $this->assertStringContainsString('function allowCreateRetry()', $component);
        $this->assertStringContainsString('if (!ambiguousCreate.value || !ambiguousCreateListChecked.value || listLoading.value) return;', $component);
        $this->assertStringContainsString('El formulario conserva el almacén, las líneas y las cantidades.', $component);
        $this->assertStringContainsString('Actualiza la lista para localizarlo', $component);

        $this->assertStringContainsString('label="Importar Excel o CSV"', $component);
        $this->assertStringContainsString('accept=".xlsx,.csv"', $component);
        $this->assertStringContainsString('label="Descargar plantilla"', $component);
        $this->assertStringContainsString('formData.append(\'existing_lines\'', $component);
        $this->assertStringContainsString('label="Validar y previsualizar"', $component);
        $this->assertStringContainsString('Fila</th><th scope="col" class="px-3 py-2">SKU</th>', $component);
        $this->assertStringContainsString('label="Incorporar filas al borrador"', $component);
        $this->assertStringContainsString('if (!preview?.can_import || busy.value || importBusy.value) return;', $component);
        $this->assertStringContainsString('preview.rows.map((row, index)', $component);
        $this->assertStringContainsString('Revisa las líneas y guarda el borrador', $component);
        $previewStart = strpos($component, 'async function previewImport()');
        $appendStart = strpos($component, 'function appendImportedRows()', $previewStart);
        $this->assertNotFalse($previewStart);
        $this->assertNotFalse($appendStart);
        $previewBody = substr($component, $previewStart, $appendStart - $previewStart);
        $this->assertStringContainsString("api.post('/admin/inventory-receipts/import-preview'", $previewBody);
        $this->assertStringNotContainsString("api.post('/admin/inventory-receipts',", $previewBody);
        $this->assertStringNotContainsString('/confirm', $previewBody);

        $this->assertStringContainsString("receipt?.status === 'draft'", $component);
        $this->assertStringContainsString('receipt.confirmed_by', $component);
        $this->assertStringContainsString('receipt.confirmed_at', $component);
        $this->assertStringContainsString('Confirmar recepción masiva', $component);
        $this->assertStringContainsString('role="status"', $component);
        $this->assertStringContainsString('Recepción masiva', file_get_contents(resource_path('js/views/admin/Inventory.vue')));
    }

    public function test_qr_labels_and_receipt_scanning_use_safe_identification_without_inventory_side_effects(): void
    {
        $products = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $label = file_get_contents(resource_path('js/components/admin/ProductQrLabel.vue'));
        $receipts = file_get_contents(resource_path('js/components/admin/InventoryReceipts.vue'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/InventoryReceiptController.php'));
        $routes = file_get_contents(base_path('routes/api.php'));
        $scanHelper = file_get_contents(resource_path('js/utils/inventoryReceiptScan.mjs'));

        $this->assertStringContainsString('@click="openQrLabel(row)"', $products);
        $this->assertStringContainsString("{ label: 'Etiqueta QR', icon: 'pi pi-qrcode', command: () => openQrLabel(product) }", $products);
        $this->assertStringContainsString('`LS1:PRODUCT:${Number(productId)}`', $label);
        $this->assertStringContainsString('Código interno LubriStore · no es GS1', $label);
        $this->assertStringContainsString('LS1:PRODUCT:', $label);
        $this->assertStringContainsString('QRCode.toDataURL', $label);
        $this->assertStringContainsString('label="Regenerar QR"', $label);
        $this->assertStringContainsString('generateQr(true, product.id)', $label);
        $this->assertStringContainsString('window.print()', $label);
        $this->assertStringContainsString(':download="`producto-${product?.id || \'qr\'}.png`"', $label);
        $this->assertStringNotContainsString('v-html', $label);

        $this->assertStringContainsString("import('@zxing/browser')", $receipts);
        $this->assertStringContainsString("import('@zxing/library')", $receipts);
        $this->assertStringContainsString('zxingBarcodeFormat?.[result.getBarcodeFormat()]', $receipts);
        $this->assertStringContainsString('openingSequence !== cameraOpenSequence || !cameraOpen.value || !qrScanner', $receipts);
        $this->assertStringContainsString('BarcodeFormat.QR_CODE', $receipts);
        $this->assertStringContainsString('BarcodeFormat.EAN_13', $receipts);
        $this->assertStringContainsString('BarcodeFormat.EAN_8', $receipts);
        $this->assertStringContainsString('BarcodeFormat.UPC_A', $receipts);
        $this->assertStringContainsString('BarcodeFormat.UPC_E', $receipts);
        $this->assertStringContainsString('BarcodeFormat.CODE_128', $receipts);
        $this->assertStringContainsString('decodeFromVideoDevice', $receipts);
        $this->assertStringContainsString('scannerControls?.stop()', $receipts);
        $this->assertStringContainsString('stopVideoTracks()', $receipts);
        $this->assertStringContainsString("error?.name === 'NotAllowedError'", $receipts);
        $this->assertStringContainsString('No se concedió permiso para usar la cámara', $receipts);
        $this->assertStringContainsString('no tiene una cámara disponible', $receipts);
        $this->assertStringContainsString('cameraPaused.value = true', $receipts);
        $this->assertStringContainsString('label="Reanudar escaneo"', $receipts);
        $this->assertStringContainsString('label="Pausar lectura"', $receipts);
        $this->assertStringContainsString('selectedCameraId.value || undefined', $receipts);
        $this->assertStringContainsString('pointer-events-none absolute inset-0', $receipts);
        $this->assertStringContainsString('aria-labelledby="receipt-scan-title"', $receipts);
        $this->assertStringContainsString('Acumulado: {{ scannedQuantity(scanCandidate.id) }} unidades', $receipts);
        $this->assertStringContainsString('scanCandidate.image_url', $receipts);
        $this->assertStringContainsString('maxlength="120"', $receipts);
        $this->assertStringContainsString('@keydown.enter.prevent="submitScanCode"', $receipts);
        $this->assertStringContainsString("api.post('/admin/inventory-receipts/resolve-product-code', { code, format })", $receipts);
        $this->assertStringContainsString('Recepción por QR o código de barras', $receipts);
        $this->assertStringContainsString('scanCandidate.matched_code', $receipts);
        $this->assertStringContainsString('barcodeFormatLabel(scanCandidate.matched_format)', $receipts);
        $this->assertStringContainsString('countModeOptions', $receipts);
        $this->assertStringContainsString("value: 'quantity'", $receipts);
        $this->assertStringContainsString("value: 'unit'", $receipts);
        $this->assertStringContainsString('accumulateReceiptScan(lines.value, product, quantity', $receipts);
        $this->assertStringContainsString('stopCameraReader();', $receipts);
        $this->assertStringContainsString('onBeforeUnmount(() => {', $receipts);
        $this->assertStringContainsString('$request->validate([', $controller);
        $this->assertStringContainsString('LS1:PRODUCT:', $controller);
        $this->assertStringContainsString("Product::query()->with('images')->where('is_active', true)", $controller);
        $this->assertStringContainsString('inferredKeys($code)', $controller);
        $this->assertStringContainsString('normalize($code, $format)', $controller);
        $this->assertStringContainsString('La identificación es ambigua', $controller);
        $this->assertStringContainsString('$product->image_url', $controller);
        $this->assertStringContainsString("'max:120'", $controller);
        $this->assertStringContainsString("'throttle:60,1'", $routes);

        $resolutionStart = strpos($controller, 'public function resolveProductCode');
        $resolutionEnd = strpos($controller, 'public function index', $resolutionStart);
        $this->assertNotFalse($resolutionStart);
        $this->assertNotFalse($resolutionEnd);
        $resolutionBody = substr($controller, $resolutionStart, $resolutionEnd - $resolutionStart);
        $this->assertStringNotContainsString('InventoryService', $resolutionBody);
        $this->assertStringNotContainsString('receiveInventory', $resolutionBody);
        $this->assertStringNotContainsString('InventoryMovement', $resolutionBody);
        $this->assertStringNotContainsString('->update(', $resolutionBody);
        $this->assertStringNotContainsString('->create(', $resolutionBody);

        $this->assertIsString($scanHelper);
        $this->assertStringContainsString('Number.isSafeInteger(units)', $scanHelper);
        $this->assertStringContainsString('existingIndex >= 0', $scanHelper);
        $this->assertStringContainsString('maxLines', $scanHelper);
        $this->assertStringContainsString('maxTotalUnits', $scanHelper);
    }
}
