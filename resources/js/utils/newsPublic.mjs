export const NEWS_HOME_LIMIT = 3;
export const NEWS_PAGE_SIZE = 9;

export function newsLayoutVariant(count) {
    if (!Number.isInteger(count) || count <= 0) return 'none';
    if (count === 1) return 'featured';
    if (count === 2) return 'two';
    return 'grid';
}

export function normalizeNewsQuery(query = {}) {
    const rawSearch = typeof query.q === 'string' ? query.q : '';
    const q = rawSearch.trim().slice(0, 100);
    const rawPage = typeof query.page === 'string' || typeof query.page === 'number' ? Number(query.page) : 1;
    const page = Number.isSafeInteger(rawPage) && rawPage > 0 ? rawPage : 1;

    return { q, page };
}

export function newsRouteQuery(search, page) {
    const q = typeof search === 'string' ? search.trim().slice(0, 100) : '';
    const currentPage = Number.isSafeInteger(Number(page)) && Number(page) > 1 ? Number(page) : 1;

    return {
        ...(q ? { q } : {}),
        ...(currentPage > 1 ? { page: String(currentPage) } : {}),
    };
}

export function newsResultSummary({ total, currentPage, perPage, itemCount }) {
    if (total === 0) return 'No hay publicaciones para mostrar.';

    const first = (currentPage - 1) * perPage + 1;
    const last = Math.min(total, first + itemCount - 1);
    const noun = total === 1 ? 'guía' : 'guías';

    return `Mostrando ${first}–${last} de ${total} ${noun}`;
}

export function normalizeNewsPage(payload) {
    const items = Array.isArray(payload?.data) ? payload.data.filter(item => item && typeof item === 'object') : [];
    const meta = payload?.meta && typeof payload.meta === 'object' ? payload.meta : {};
    const positiveInteger = (value, fallback) => {
        const parsed = Number(value);
        return Number.isSafeInteger(parsed) && parsed >= 0 ? parsed : fallback;
    };

    return {
        items,
        currentPage: Math.max(1, positiveInteger(meta.current_page, 1)),
        lastPage: Math.max(1, positiveInteger(meta.last_page, 1)),
        perPage: Math.max(1, positiveInteger(meta.per_page, NEWS_PAGE_SIZE)),
        total: positiveInteger(meta.total, items.length),
    };
}
