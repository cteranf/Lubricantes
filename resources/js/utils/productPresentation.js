const penFormatter = new Intl.NumberFormat('es-PE', {
    style: 'currency',
    currency: 'PEN',
    minimumFractionDigits: 2,
});

export const formatPen = value => penFormatter.format(Number(value || 0));

export const hasValidSale = product => {
    const regular = Number(product?.price || 0);
    const sale = Number(product?.sale_price || 0);
    return regular > 0 && sale > 0 && sale < regular;
};

export const productPrice = product => hasValidSale(product)
    ? Number(product.sale_price)
    : Number(product?.price || 0);

export const discountPercentage = product => hasValidSale(product)
    ? Math.round((1 - (Number(product.sale_price) / Number(product.price))) * 100)
    : 0;

export const normalizeImageUrl = value => {
    if (typeof value !== 'string' || !value.trim()) return null;

    const path = value.trim().replaceAll('\\', '/');
    if (path.includes('../') || /^[A-Z]:\//i.test(path) || path.startsWith('//')) return null;
    if (/^https?:\/\//i.test(path) || path.startsWith('/')) return path;

    const normalized = path
        .replace(/^storage\/app\/public\//, '')
        .replace(/^storage\//, '')
        .replace(/^\/+/, '');

    return normalized ? `/storage/${normalized}` : null;
};

export const resolveProductImages = product => {
    const candidates = [
        product?.image_url,
        product?.image_path,
        ...(Array.isArray(product?.images)
            ? product.images.flatMap(image => [image?.image_url, image?.image_path])
            : []),
    ];

    return [...new Set(candidates.map(normalizeImageUrl).filter(Boolean))];
};
