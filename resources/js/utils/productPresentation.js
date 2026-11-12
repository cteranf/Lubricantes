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

export const productImageVariants = product => {
    const variants = product?.variants || product?.image_variants;
    if (!variants || typeof variants !== 'object' || Array.isArray(variants)) return [];

    return Object.entries(variants)
        .map(([key, item]) => ({
            key,
            url: normalizeImageUrl(item?.url),
            width: Number(item?.width),
            height: Number(item?.height),
        }))
        .filter(item => item.url && Number.isInteger(item.width) && item.width > 0 && Number.isInteger(item.height) && item.height > 0)
        .sort((left, right) => left.width - right.width);
};

export const productImageSrcset = (product, preferredKeys = null) => {
    const variants = productImageVariants(product);
    const entries = (preferredKeys ? variants.filter(item => preferredKeys.includes(item.key)) : variants)
        .map(item => `${item.url} ${item.width}w`);
    return entries.length ? entries.join(', ') : undefined;
};

export const productImageDimensions = (product, preferred = ['card', 'thumbnail', 'medium', 'detail_source', 'detail', 'zoom']) => {
    if (Number.isInteger(Number(product?.width)) && Number(product?.width) > 0
        && Number.isInteger(Number(product?.height)) && Number(product?.height) > 0) {
        return { width: Number(product.width), height: Number(product.height) };
    }
    if (product?.image_dimensions && Number.isInteger(Number(product.image_dimensions.width))
        && Number.isInteger(Number(product.image_dimensions.height))) {
        return { width: Number(product.image_dimensions.width), height: Number(product.image_dimensions.height) };
    }
    const variants = product?.variants || product?.image_variants;
    if (!variants || typeof variants !== 'object' || Array.isArray(variants)) return null;
    for (const key of preferred) {
        const item = variants[key];
        const width = Number(item?.width);
        const height = Number(item?.height);
        if (Number.isInteger(width) && width > 0 && Number.isInteger(height) && height > 0) return { width, height };
    }
    return null;
};

export const resolveProductImages = product => {
    const logical = Array.isArray(product?.images)
        ? product.images.map(image => normalizeImageUrl(image?.url || image?.image_url || image?.image_path)).filter(Boolean)
        : [];
    const candidates = [
        ...logical,
        ...(!logical.length ? productImageVariants(product).map(item => item.url) : []),
        product?.image_url,
        product?.image_path,
    ];

    return [...new Set(candidates.map(normalizeImageUrl).filter(Boolean))];
};

export const resolveProductGallery = product => {
    const legacyGallery = () => {
        const url = resolveProductImages(product)[0] || null;
        return url ? [{
            id: 'legacy-primary',
            url,
            variants: product?.image_variants || {},
            srcset: product?.image_srcset,
            width: product?.image_dimensions?.width,
            height: product?.image_dimensions?.height,
            is_primary: true,
        }] : [];
    };
    if (!Array.isArray(product?.images)) return legacyGallery();

    const gallery = product.images
        .filter(image => image && typeof image === 'object')
        .map(image => ({
            id: image.id,
            url: normalizeImageUrl(image.url),
            variants: image.variants || {},
            srcset: typeof image.srcset === 'string' ? image.srcset : undefined,
            width: image.width,
            height: image.height,
            sort_order: image.sort_order,
            is_primary: image.is_primary === true,
        }))
        .filter(image => image.url)
        .sort((left, right) => Number(left.sort_order || 0) - Number(right.sort_order || 0));

    return gallery.length ? gallery : legacyGallery();
};
