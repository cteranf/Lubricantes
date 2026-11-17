<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductBarcodeFrontendTest extends TestCase
{
    public function test_admin_editor_exposes_commercial_codes_without_replacing_the_internal_qr(): void
    {
        $products = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $receipts = file_get_contents(resource_path('js/components/admin/InventoryReceipts.vue'));
        $publicResource = file_get_contents(app_path('Http/Resources/ProductResource.php'));

        $this->assertStringContainsString('Códigos de barras comerciales', $products);
        $this->assertStringContainsString('barcodeFormatOptions', $products);
        $this->assertStringContainsString('setPrimaryBarcode(index)', $products);
        $this->assertStringContainsString('removeBarcode(index)', $products);
        $this->assertStringContainsString("data.append('barcodes', JSON.stringify(", $products);
        $this->assertStringContainsString('form.value.barcodes = barcodeRows(data.barcodes)', $products);
        $this->assertStringContainsString('LS1:PRODUCT:', file_get_contents(resource_path('js/components/admin/ProductQrLabel.vue')));
        $this->assertStringContainsString('matched_code', $receipts);
        $this->assertStringContainsString('matched_format', $receipts);
        $this->assertStringNotContainsString("'barcodes'", $publicResource);
        $this->assertStringNotContainsString('public_barcodes', $publicResource);
    }

    public function test_scanner_uses_one_multiformat_camera_loop_and_stops_streams_when_paused(): void
    {
        $receipts = file_get_contents(resource_path('js/components/admin/InventoryReceipts.vue'));

        $this->assertStringContainsString('new BrowserMultiFormatReader(hints', $receipts);
        $this->assertStringContainsString('DecodeHintType.POSSIBLE_FORMATS', $receipts);
        $this->assertStringContainsString('cameraPaused.value = true;', $receipts);
        $this->assertStringContainsString('stopCameraReader();', $receipts);
        $this->assertStringContainsString('track.stop()', $receipts);
        $this->assertStringContainsString('Reanudar escaneo', $receipts);
        $this->assertStringNotContainsString('qr-scanner', $receipts);
    }
}
