export const MAX_CART_QUANTITY = 999;

export const cartQuantityLimit = item => {
    const stock = Number(item?.product?.stock);

    return Number.isFinite(stock) && stock >= 0
        ? Math.min(Math.floor(stock), MAX_CART_QUANTITY)
        : MAX_CART_QUANTITY;
};

export const normalizeCartQuantity = (rawValue, item) => {
    const limit = cartQuantityLimit(item);
    const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue;
    const numeric = value === '' ? Number.NaN : Number(value);
    let quantity = Number.isFinite(numeric) ? Math.trunc(numeric) : 1;
    let reason = Number.isFinite(numeric) ? null : 'invalid';

    if (quantity < 1) {
        quantity = 1;
        reason = 'minimum';
    } else if (numeric !== quantity) {
        reason = 'decimal';
    }

    if (quantity > limit) {
        quantity = limit;
        reason = 'stock';
    }

    return { quantity, reason, limit };
};
