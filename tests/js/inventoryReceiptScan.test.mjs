import test from 'node:test';
import assert from 'node:assert/strict';
import { accumulateReceiptScan, scannerFormatName } from '../../resources/js/utils/inventoryReceiptScan.mjs';

test('unit scans accumulate an existing product and keep one line per product', () => {
    const lines = [{ product_id: 7, product: { id: 7, name: 'Aceite' }, quantity: 2, sort_order: 0 }];
    const result = accumulateReceiptScan(lines, { id: 7, name: 'Aceite' }, 1);

    assert.equal(result.ok, true);
    assert.equal(result.lines.length, 1);
    assert.equal(result.lines[0].quantity, 3);
    assert.equal(lines[0].quantity, 2, 'helper does not mutate current receipt before acceptance');
});

test('quantity scans add distinct products and enforce line and total limits', () => {
    const oneLine = [{ product_id: 7, product: { id: 7 }, quantity: 2, sort_order: 0 }];
    const second = accumulateReceiptScan(oneLine, { id: 8, name: 'Filtro' }, 4, { maxLines: 2, maxTotalUnits: 6 });
    assert.equal(second.ok, true);
    assert.deepEqual(second.lines.map(line => [line.product_id, line.quantity]), [[7, 2], [8, 4]]);
    assert.deepEqual(accumulateReceiptScan(second.lines, { id: 9 }, 1, { maxLines: 2, maxTotalUnits: 100 }), { ok: false, reason: 'max_lines' });
    assert.deepEqual(accumulateReceiptScan(oneLine, { id: 7 }, 5, { maxLines: 2, maxTotalUnits: 6 }), { ok: false, reason: 'max_units' });
});

test('invalid or incomplete quantities never create a line', () => {
    assert.deepEqual(accumulateReceiptScan([], { id: 1 }, 0), { ok: false, reason: 'invalid' });
    assert.deepEqual(accumulateReceiptScan([], { id: 1 }, 1.5), { ok: false, reason: 'invalid' });
    assert.deepEqual(accumulateReceiptScan([], { id: 1 }, 1, { maxLines: 0 }), { ok: false, reason: 'max_lines' });
});

test('camera format names cover the requested QR and commercial symbologies', () => {
    assert.deepEqual(
        ['QR_CODE', 'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'CODE_128'].map(scannerFormatName),
        ['qr_code', 'ean13', 'ean8', 'upca', 'upce', 'code128'],
    );
    assert.equal(scannerFormatName('DATA_MATRIX'), null);
});

test('QR and commercial scans for one product accumulate by product identity', () => {
    const product = { id: 17, name: 'Aceite', matched_code: 'LS1:PRODUCT:17', matched_format: 'qr_internal' };
    const qrScan = accumulateReceiptScan([], product, 1);
    const barcodeScan = accumulateReceiptScan(qrScan.lines, { ...product, matched_code: '4006381333931', matched_format: 'ean13' }, 1);
    assert.equal(barcodeScan.lines.length, 1);
    assert.equal(barcodeScan.lines[0].product_id, 17);
    assert.equal(barcodeScan.lines[0].quantity, 2);
});
