export const MAX_PATH_DECODE_STEPS = 4;

const hasMalformedEscape = value => /%(?![a-f\d]{2})/i.test(value);
const hasEncodedByte = value => /%[a-f\d]{2}/i.test(value);
const hasControlsOrBackslash = value => /[\u0000-\u001f\u007f\\]/.test(value);

export function decodePathForValidation(path) {
    if (typeof path !== 'string') return null;

    let current = path;
    for (let step = 0; step <= MAX_PATH_DECODE_STEPS; step += 1) {
        if (hasMalformedEscape(current) || hasControlsOrBackslash(current) || current.startsWith('//')) return null;
        if (current.split('/').some(segment => segment === '.' || segment === '..')) return null;

        if (!hasEncodedByte(current)) return current;
        if (step === MAX_PATH_DECODE_STEPS) return null;

        try {
            const decoded = decodeURIComponent(current);
            if (decoded === current) return current;
            current = decoded;
        } catch {
            return null;
        }
    }

    return null;
}

function parseSafeHttpUrl(value, { allowQuery = false, allowFragment = false } = {}) {
    if (typeof value !== 'string' || !value || /[\u0000-\u0020\u007f\\]/.test(value) || value.startsWith('//')) return null;

    const rawPathMatch = value.match(/^https?:\/\/[^/?#]*(\/[^?#]*)?/i);
    const rawPath = rawPathMatch?.[1] || '/';
    if (decodePathForValidation(rawPath) === null) return null;

    try {
        const url = new URL(value);
        if (!['http:', 'https:'].includes(url.protocol)
            || !url.hostname
            || url.username
            || url.password
            || (!allowQuery && url.search)
            || (!allowFragment && url.hash)
            || decodePathForValidation(url.pathname) === null) return null;
        return url;
    } catch {
        return null;
    }
}

function safeBaseUrl(baseValue) {
    const base = parseSafeHttpUrl(baseValue);
    if (!base) return null;
    base.pathname = `${base.pathname.replace(/\/+$/, '')}/`;
    return base;
}

function canonicalCategoryQuery(rawQuery) {
    if (rawQuery === null) return '';
    const params = new URLSearchParams(rawQuery);
    const entries = [...params.entries()];
    if (entries.length !== 1 || entries[0][0] !== 'category' || params.getAll('category').length !== 1) return '';
    if (hasControlsOrBackslash(entries[0][1])) return '';
    return `?category=${encodeURIComponent(entries[0][1])}`;
}

export function resolveSiteCanonicalUrl(path, baseValue) {
    const base = safeBaseUrl(baseValue);
    if (!base || typeof path !== 'string' || !path) return null;

    if (path.startsWith('//')) return null;
    if (/^[a-z][a-z\d+.-]*:/i.test(path)) {
        if (!/^https?:\/\//i.test(path)) return null;
        const absolute = parseSafeHttpUrl(path, { allowQuery: true, allowFragment: true });
        if (!absolute) return null;
        absolute.search = '';
        absolute.hash = '';
        return absolute.href;
    }

    const hashIndex = path.indexOf('#');
    const beforeFragment = hashIndex === -1 ? path : path.slice(0, hashIndex);
    const queryIndex = beforeFragment.indexOf('?');
    const rawPath = queryIndex === -1 ? beforeFragment : beforeFragment.slice(0, queryIndex);
    const rawQuery = queryIndex === -1 ? null : beforeFragment.slice(queryIndex + 1);
    if (decodePathForValidation(rawPath) === null) return null;

    const basePath = base.pathname.replace(/\/+$/, '');
    const rootedInput = `/${rawPath.replace(/^\/+/, '')}`;
    const alreadyPrefixed = basePath !== '' && (rootedInput === basePath || rootedInput.startsWith(`${basePath}/`));
    let resolved;
    try {
        resolved = alreadyPrefixed
            ? new URL(rootedInput, base.origin)
            : new URL(rawPath.replace(/^\/+/, ''), base);
    } catch {
        return null;
    }

    if (resolved.origin !== base.origin || decodePathForValidation(resolved.pathname) === null) return null;
    resolved.search = canonicalCategoryQuery(rawQuery);
    resolved.hash = '';
    return resolved.href;
}

export function resolveSiteImageUrl(value, baseValue, allowExternal = false) {
    const base = safeBaseUrl(baseValue);
    if (!base || typeof value !== 'string' || !value) return null;

    if (value.startsWith('//')) return null;
    if (/^[a-z][a-z\d+.-]*:/i.test(value)) {
        if (!/^https?:\/\//i.test(value)) return null;
        const absolute = parseSafeHttpUrl(value);
        if (!absolute || (!allowExternal && absolute.origin !== base.origin)) return null;
        return absolute.href;
    }

    if (!value.startsWith('/') || value.includes('?') || value.includes('#') || decodePathForValidation(value) === null) return null;
    return resolveSiteCanonicalUrl(value, base.href);
}
