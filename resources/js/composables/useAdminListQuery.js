import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const PAGE_SIZES = [15, 25, 50, 100];
const BASE_QUERY_KEYS = ['page', 'per_page', 'sort', 'direction'];
const TEXT_FILTER_KEYS = new Set(['search', 'order_id', 'customer']);
const DANGEROUS_QUERY_KEYS = new Set(['__proto__', 'prototype', 'constructor']);

function validPage(value) {
    return typeof value === 'string' && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value) : 1;
}

function validPageSize(value) {
    return typeof value === 'string' && PAGE_SIZES.some((pageSize) => String(pageSize) === value)
        ? Number(value) : 15;
}

export function useAdminListQuery({ route, router, fetchPage, filters = [], sorts = ['created_at', 'id'], debounceMs = 350 }) {
    if (!Array.isArray(filters) || filters.some((key) => typeof key !== 'string'
        || !/^[A-Za-z][A-Za-z0-9_]*$/.test(key)
        || BASE_QUERY_KEYS.includes(key)
        || DANGEROUS_QUERY_KEYS.has(key))) {
        throw new TypeError('Invalid admin list filter allowlist.');
    }

    const filterKeys = [...new Set(filters)];
    const page = ref(validPage(route.query.page));
    const perPage = ref(validPageSize(route.query.per_page));
    const sort = ref(sorts.includes(route.query.sort) ? route.query.sort : sorts[0]);
    const direction = ref(route.query.direction === 'asc' ? 'asc' : 'desc');
    const filterValues = reactive(Object.fromEntries(filterKeys.map((key) => [key, typeof route.query[key] === 'string' ? route.query[key] : ''])));
    const rows = ref([]);
    const meta = ref({ current_page: page.value, per_page: perPage.value, total: 0, last_page: 1, from: null, to: null });
    const links = ref({ first: null, previous: null, next: null, last: null });
    const loading = ref(false);
    const error = ref(null);
    const allowedQueryKeys = computed(() => [...BASE_QUERY_KEYS, ...filterKeys]);
    let requestSequence = 0;
    let debounceTimer = null;
    let debounceSequence = 0;
    let disposed = false;

    function normalizedQuery(source) {
        const result = {
            page: String(validPage(source.page)),
            per_page: String(validPageSize(source.per_page)),
            sort: sorts.includes(source.sort) ? source.sort : sorts[0],
            direction: source.direction === 'asc' ? 'asc' : 'desc',
        };
        for (const key of filterKeys) {
            if (typeof source[key] === 'string' && source[key] !== '') result[key] = source[key];
        }
        return result;
    }

    function unrelatedQuery(source) {
        return Object.fromEntries(Object.entries(source).filter(([key]) =>
            !allowedQueryKeys.value.includes(key) && !DANGEROUS_QUERY_KEYS.has(key)));
    }

    function routeQuery(source) {
        return { ...unrelatedQuery(source), ...normalizedQuery(source) };
    }

    function equalQueryValue(left, right) {
        if (Array.isArray(left) || Array.isArray(right)) return JSON.stringify(left) === JSON.stringify(right);
        return left === right;
    }

    function isCanonicalQuery(source, canonical) {
        const sourceKeys = Object.keys(source);
        const canonicalKeys = Object.keys(canonical);
        return sourceKeys.length === canonicalKeys.length
            && canonicalKeys.every((key) => Object.prototype.hasOwnProperty.call(source, key)
                && equalQueryValue(source[key], canonical[key]));
    }

    function cancelDebounce() {
        clearTimeout(debounceTimer);
        debounceTimer = null;
        debounceSequence++;
    }

    function params() {
        return { page: page.value, per_page: perPage.value, sort: sort.value, direction: direction.value,
            ...Object.fromEntries(filterKeys.filter((key) => filterValues[key] !== '').map((key) => [key, filterValues[key]])) };
    }

    async function load() {
        const sequence = ++requestSequence;
        loading.value = true;
        error.value = null;
        try {
            const result = await fetchPage(params());
            if (disposed || sequence !== requestSequence) return;
            rows.value = Array.isArray(result.data) ? result.data : [];
            meta.value = result.meta || meta.value;
            links.value = result.links || links.value;
            if (meta.value.current_page !== page.value) {
                await updateQuery({ page: String(meta.value.current_page) }, false);
            }
        } catch (failure) {
            if (!disposed && sequence === requestSequence) error.value = failure;
        } finally {
            if (!disposed && sequence === requestSequence) loading.value = false;
        }
    }

    async function updateQuery(changes, resetPage = false) {
        cancelDebounce();
        const next = { ...params(), ...changes };
        if (resetPage) next.page = '1';
        const query = { ...unrelatedQuery(route.query), ...normalizedQuery(next) };
        if (isCanonicalQuery(route.query, query)) return;
        await router.push({ query });
    }

    function setFilters(values) {
        const changed = [];
        for (const key of filterKeys) {
            if (!Object.prototype.hasOwnProperty.call(values, key)) continue;
            if (values[key] !== null && typeof values[key] !== 'string') continue;
            const value = values[key] ?? '';
            if (filterValues[key] !== value) changed.push(key);
            filterValues[key] = value;
        }
        if (changed.length === 0) return;

        cancelDebounce();
        const textualOnly = changed.every((key) => TEXT_FILTER_KEYS.has(key));
        if (!textualOnly) return updateQuery({}, true);

        const token = debounceSequence;
        debounceTimer = setTimeout(() => {
            if (disposed || token !== debounceSequence) return;
            debounceTimer = null;
            updateQuery({}, true);
        }, debounceMs);
    }

    function setFilter(key, value, debounce = TEXT_FILTER_KEYS.has(key)) {
        if (!filterKeys.includes(key)) return;
        if (value !== null && typeof value !== 'string') return;
        if (!debounce) return setFilters({ [key]: value });

        const next = value ?? '';
        if (filterValues[key] === next) return;
        filterValues[key] = next;
        cancelDebounce();
        const token = debounceSequence;
        debounceTimer = setTimeout(() => {
            if (disposed || token !== debounceSequence) return;
            debounceTimer = null;
            updateQuery({}, true);
        }, debounceMs);
    }

    function clearFilters() {
        cancelDebounce();
        for (const key of filterKeys) filterValues[key] = '';
        return updateQuery(Object.fromEntries(filterKeys.map((key) => [key, undefined])), true);
    }

    function applyFilters() {
        cancelDebounce();
        return updateQuery({}, true);
    }

    function setPage(value) {
        const target = Number(value);
        if (!Number.isInteger(target) || target < 1 || target > (meta.value.last_page || 1) || loading.value) return;
        return updateQuery({ page: String(target) });
    }

    function setPerPage(value) {
        const target = Number(value);
        if (!PAGE_SIZES.includes(target) || loading.value) return;
        return updateQuery({ per_page: String(target) }, true);
    }

    function setSort(value, nextDirection = 'desc') {
        if (!sorts.includes(value) || !['asc', 'desc'].includes(nextDirection)) return;
        return updateQuery({ sort: value, direction: nextDirection }, true);
    }

    watch(() => route.query, (query) => {
        cancelDebounce();
        const normalized = normalizedQuery(query);
        const canonical = { ...unrelatedQuery(query), ...normalized };
        if (!isCanonicalQuery(query, canonical)) {
            router.replace({ query: canonical });
            return;
        }
        page.value = Number(normalized.page);
        perPage.value = Number(normalized.per_page);
        sort.value = normalized.sort;
        direction.value = normalized.direction;
        for (const key of filterKeys) filterValues[key] = normalized[key] || '';
        load();
    }, { deep: true });

    watch(() => route.path, cancelDebounce);

    onMounted(async () => {
        const query = routeQuery(route.query);
        if (!isCanonicalQuery(route.query, query)) {
            await router.replace({ query });
        } else {
            await load();
        }
    });

    onBeforeUnmount(() => {
        disposed = true;
        requestSequence++;
        cancelDebounce();
    });

    return { page, perPage, sort, direction, filters: filterValues, rows, meta, links, loading, error,
        load, retry: load, setFilter, setFilters, applyFilters, clearFilters, setPage, setPerPage, setSort };
}
