const toMinor = value => Math.round((Number(value) || 0) * 100);

export function cartItemPriceBreakdown(item) {
    const appliedPriceMinor = toMinor(item?.price);
    const regularPriceMinor = Math.max(appliedPriceMinor, toMinor(item?.regular_price ?? item?.product?.price ?? item?.price));

    return {
        regularPrice: regularPriceMinor / 100,
        appliedPrice: appliedPriceMinor / 100,
        unitSavings: (regularPriceMinor - appliedPriceMinor) / 100,
        hasDiscount: regularPriceMinor > appliedPriceMinor,
    };
}

export function summarizeCartPrices(items = []) {
    const totals = items.reduce((result, item) => {
        const { regularPrice, appliedPrice } = cartItemPriceBreakdown(item);
        const applied = toMinor(appliedPrice);
        const regular = toMinor(regularPrice);
        const quantity = Math.max(0, Math.trunc(Number(item?.quantity) || 0));
        result.regularSubtotalMinor += regular * quantity;
        result.savingsMinor += (regular - applied) * quantity;
        result.productSubtotalMinor += applied * quantity;
        return result;
    }, { regularSubtotalMinor: 0, savingsMinor: 0, productSubtotalMinor: 0 });

    return {
        regularSubtotal: totals.regularSubtotalMinor / 100,
        savings: totals.savingsMinor / 100,
        productSubtotal: totals.productSubtotalMinor / 100,
        hasSavings: totals.savingsMinor > 0,
    };
}

export function summarizeOrderPriceSnapshots(items = []) {
    return summarizeCartPrices(items.map(item => ({
        regular_price: item?.regular_price ?? item?.price,
        price: item?.price,
        quantity: item?.quantity,
    })));
}

export function applyCartPriceQuotes(items = [], quotes = []) {
    const quotesByProduct = new Map(quotes.map(quote => [Number(quote?.product_id), quote]));

    for (const item of items) {
        const quote = quotesByProduct.get(Number(item.product_id));
        const regular = Number(quote?.regular_price);
        const applied = Number(quote?.applied_price);
        if (!Number.isFinite(regular) || regular <= 0 || !Number.isFinite(applied) || applied <= 0 || applied > regular) continue;

        item.regular_price = regular;
        item.price = applied;
        if (item.product && typeof item.product === 'object') {
            item.product.price = regular;
            item.product.sale_price = applied < regular ? applied : null;
        }
    }

    return items;
}
