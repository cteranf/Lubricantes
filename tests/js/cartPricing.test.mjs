import test from 'node:test';
import assert from 'node:assert/strict';
import { applyCartPriceQuotes, cartItemPriceBreakdown, summarizeCartPrices, summarizeOrderPriceSnapshots } from '../../resources/js/utils/cartPricing.mjs';

test('summarizes mixed discounted and regular cart lines using quantity-weighted savings', () => {
    const items = [
        { product_id: 1, regular_price: '109.00', price: '100.00', quantity: 6 },
        { product_id: 2, regular_price: '40.00', price: '40.00', quantity: 2 },
    ];

    assert.deepEqual(summarizeCartPrices(items), {
        regularSubtotal: 734,
        savings: 54,
        productSubtotal: 680,
        hasSavings: true,
    });
    assert.deepEqual(cartItemPriceBreakdown(items[0]), {
        regularPrice: 109,
        appliedPrice: 100,
        unitSavings: 9,
        hasDiscount: true,
    });
});

test('six units at S/ 109.00 applied at S/ 100.00 save S/ 54.00 and subtotal S/ 600.00', () => {
    const summary = summarizeCartPrices([{ regular_price: '109.00', price: '100.00', quantity: 6 }]);

    assert.deepEqual(summary, {
        regularSubtotal: 654,
        savings: 54,
        productSubtotal: 600,
        hasSavings: true,
    });
});

test('checkout adds S/ 15.00 shipping to the discounted S/ 600.00 product subtotal once', () => {
    const summary = summarizeCartPrices([{ regular_price: '109.00', price: '100.00', quantity: 6 }]);
    const total = Math.round(summary.productSubtotal * 100) + 1500;

    assert.equal(summary.savings, 54);
    assert.equal(summary.productSubtotal, 600);
    assert.equal(total / 100, 615);
});

test('order confirmation calculates savings only from saved snapshots, independent of current catalog price', () => {
    const summary = summarizeOrderPriceSnapshots([
        { regular_price: '109.00', price: '100.00', quantity: 6, product: { price: '130.00' } },
    ]);

    assert.deepEqual(summary, {
        regularSubtotal: 654,
        savings: 54,
        productSubtotal: 600,
        hasSavings: true,
    });
});

test('legacy order lines without snapshots do not infer savings from current product price', () => {
    const summary = summarizeOrderPriceSnapshots([
        { regular_price: null, price: '100.00', quantity: 2, product: { price: '130.00' } },
    ]);

    assert.deepEqual(summary, {
        regularSubtotal: 200,
        savings: 0,
        productSubtotal: 200,
        hasSavings: false,
    });
});

test('order without an offer has no savings row even when normal price is snapshotted', () => {
    const summary = summarizeOrderPriceSnapshots([
        { regular_price: '50.00', price: '50.00', quantity: 1 },
    ]);

    assert.equal(summary.regularSubtotal, 50);
    assert.equal(summary.savings, 0);
    assert.equal(summary.productSubtotal, 50);
    assert.equal(summary.hasSavings, false);
});

test('an expired offer quote replaces the applied price and removes displayed savings', () => {
    const items = [{ product_id: 7, regular_price: 109, price: 100, quantity: 6, product: { price: 109, sale_price: 100 } }];

    applyCartPriceQuotes(items, [{ product_id: 7, regular_price: '109.00', applied_price: '109.00' }]);

    assert.equal(items[0].regular_price, 109);
    assert.equal(items[0].price, 109);
    assert.equal(items[0].product.sale_price, null);
    assert.deepEqual(summarizeCartPrices(items), {
        regularSubtotal: 654,
        savings: 0,
        productSubtotal: 654,
        hasSavings: false,
    });
});
