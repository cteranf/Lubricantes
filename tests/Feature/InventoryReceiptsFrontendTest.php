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

        $this->assertStringContainsString("error?.response?.status === 409 && !isNew", $component);
        $this->assertStringContainsString('Cargar versión vigente', $component);
        $this->assertStringContainsString('no se sobrescribieron tus cambios', $component);
        $this->assertStringContainsString("recoverReceiptState('confirm', null, receiptId)", $component);
        $this->assertStringContainsString('api.get(`/admin/inventory-receipts/${receiptId}`)', $component);
        $this->assertStringContainsString("retryConfirmAvailable.value = true", $component);
        $this->assertStringContainsString("ambiguousCreate.value = true", $component);
        $this->assertStringContainsString("if (ambiguousCreate.value && Number(page) === 1) ambiguousCreateListChecked.value = true", $component);
        $this->assertStringContainsString('no se enviará otro borrador automáticamente', $component);
        $this->assertStringContainsString('No aparece: conservar formulario y permitir reintento', $component);
        $this->assertStringContainsString('function allowCreateRetry()', $component);
        $this->assertStringContainsString('if (!ambiguousCreate.value || !ambiguousCreateListChecked.value || listLoading.value) return;', $component);
        $this->assertStringContainsString('El formulario conserva el almacén, las líneas y las cantidades.', $component);
        $this->assertStringContainsString('Actualiza la lista para localizarlo', $component);

        $this->assertStringContainsString("receipt?.status === 'draft'", $component);
        $this->assertStringContainsString('receipt.confirmed_by', $component);
        $this->assertStringContainsString('receipt.confirmed_at', $component);
        $this->assertStringContainsString('Confirmar recepción masiva', $component);
        $this->assertStringContainsString('role="status"', $component);
        $this->assertStringContainsString('Recepción masiva', file_get_contents(resource_path('js/views/admin/Inventory.vue')));
    }
}
