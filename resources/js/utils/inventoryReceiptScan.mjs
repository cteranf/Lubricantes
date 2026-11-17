export function accumulateReceiptScan(lines, product, quantity, { maxLines = 100, maxTotalUnits = 100000 } = {}) {
    const productId = Number(product?.id);
    const units = Number(quantity);
    if (!Array.isArray(lines) || !Number.isSafeInteger(productId) || productId < 1 || !Number.isSafeInteger(units) || units < 1) {
        return { ok: false, reason: 'invalid' };
    }

    const quantities = lines.map(line => Number(line.quantity));
    if (quantities.some(value => !Number.isSafeInteger(value) || value < 1)) return { ok: false, reason: 'invalid' };
    const total = quantities.reduce((sum, value) => sum + value, 0);
    if (!Number.isSafeInteger(total) || total + units > maxTotalUnits) return { ok: false, reason: 'max_units' };

    const existingIndex = lines.findIndex(line => Number(line.product_id) === productId);
    const nextLines = lines.map(line => ({ ...line }));
    if (existingIndex >= 0) {
        const accumulated = Number(nextLines[existingIndex].quantity) + units;
        if (!Number.isSafeInteger(accumulated) || accumulated > maxTotalUnits) return { ok: false, reason: 'max_units' };
        nextLines[existingIndex].quantity = accumulated;
        return { ok: true, lines: nextLines };
    }

    if (lines.length >= maxLines) return { ok: false, reason: 'max_lines' };
    nextLines.push({ product_id: productId, product, quantity: units, sort_order: nextLines.length });
    return { ok: true, lines: nextLines };
}
