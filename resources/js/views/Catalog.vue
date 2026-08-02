<template>
    <AppLayout>
        <div class="bg-slate-50 py-8 sm:py-10 lg:py-12">
            <div class="site-container">
                <nav aria-label="Migas de pan" class="mb-5 text-sm text-slate-500">
                    <ol class="flex flex-wrap items-center gap-2">
                        <li><router-link to="/" class="rounded hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700">Inicio</router-link></li>
                        <li aria-hidden="true">/</li>
                        <li aria-current="page" class="font-semibold text-slate-700">Catálogo</li>
                    </ol>
                </nav>

                <header class="mb-8 max-w-3xl">
                    <p class="eyebrow">Encuentra el producto adecuado</p>
                    <h1 class="text-4xl font-black tracking-tight text-slate-950 sm:text-5xl">Catálogo</h1>
                    <p class="mt-3 text-base leading-relaxed text-slate-600 sm:text-lg">Explora lubricantes y productos disponibles para el cuidado de tu vehículo.</p>
                </header>

                <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-label="Buscar y ordenar productos">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_auto]">
                        <form role="search" class="relative" @submit.prevent="submitSearch">
                            <label for="catalog-search" class="mb-2 block text-sm font-bold text-slate-800">Buscar en el catálogo</label>
                            <div class="relative">
                                <input id="catalog-search" v-model="searchDraft" type="search" class="search-input pr-12" placeholder="Nombre o SKU del producto" autocomplete="off">
                                <button type="submit" class="search-button" :disabled="!searchDraft.trim() && !filters.search" aria-label="Buscar en el catálogo"><i class="pi pi-search" aria-hidden="true"></i></button>
                            </div>
                        </form>
                        <div class="md:w-52">
                            <label for="catalog-sort" class="mb-2 block text-sm font-bold text-slate-800">Ordenar por</label>
                            <select id="catalog-sort" :value="filters.sort" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20" @change="setSort($event.target.value)">
                                <option value="newest">Más recientes</option>
                                <option value="price_asc">Menor precio</option>
                                <option value="price_desc">Mayor precio</option>
                            </select>
                        </div>
                    </div>
                </section>

                <div class="mb-6 flex flex-wrap items-center gap-3 lg:hidden">
                    <button type="button" class="btn-outline" :aria-expanded="mobileFiltersOpen" aria-controls="catalog-filters" @click="openMobileFilters">
                        <i class="pi pi-sliders-h" aria-hidden="true"></i> Filtros
                        <span v-if="activeFilterCount" class="flex h-6 min-w-6 items-center justify-center rounded-full bg-blue-700 px-1.5 text-xs font-black text-white">{{ activeFilterCount }}</span>
                    </button>
                    <p class="text-sm font-semibold text-slate-600">{{ resultsSummary }}</p>
                </div>

                <div v-if="activeFilters.length" class="mb-6 flex flex-wrap items-center gap-2" aria-label="Filtros activos">
                    <button v-for="filter in activeFilters" :key="filter.key" type="button" class="inline-flex min-h-10 items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-bold text-blue-800 transition hover:bg-blue-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" :aria-label="`Quitar filtro ${filter.label}`" @click="removeFilter(filter.key)">
                        {{ filter.label }} <i class="pi pi-times text-xs" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="min-h-10 rounded-lg px-3 text-sm font-bold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="clearFilters">Limpiar filtros</button>
                </div>

                <div class="flex items-start gap-8">
                    <div
                        :class="[
                            mobileFiltersOpen ? 'fixed inset-0 z-[70] flex justify-end bg-slate-950/60' : 'hidden',
                            'lg:static lg:z-auto lg:block lg:w-64 lg:shrink-0 lg:bg-transparent'
                        ]"
                        @mousedown.self="closeMobileFilters"
                    >
                        <aside
                            id="catalog-filters"
                            ref="filterPanel"
                            :role="mobileFiltersOpen ? 'dialog' : undefined"
                            :aria-modal="mobileFiltersOpen ? 'true' : undefined"
                            aria-labelledby="catalog-filters-title"
                            class="flex h-full w-[min(90vw,22rem)] flex-col overflow-y-auto bg-white shadow-2xl outline-none lg:sticky lg:top-24 lg:h-auto lg:w-full lg:max-h-[calc(100vh-7rem)] lg:rounded-2xl lg:border lg:border-slate-200 lg:shadow-sm"
                            tabindex="-1"
                            @keydown="handleFilterPanelKeydown"
                        >
                            <div class="flex items-center justify-between border-b border-slate-200 p-5">
                                <h2 id="catalog-filters-title" class="text-xl font-black text-slate-950">Filtros</h2>
                                <button ref="closeFiltersButton" type="button" class="icon-button lg:hidden" aria-label="Cerrar filtros" @click="closeMobileFilters"><i class="pi pi-times" aria-hidden="true"></i></button>
                            </div>

                            <div class="flex-1 space-y-7 p-5">
                                <fieldset>
                                    <legend class="mb-3 text-sm font-black uppercase tracking-wider text-slate-900">Categorías</legend>
                                    <div v-if="optionsState.categoriesLoading" class="space-y-2" aria-busy="true"><Skeleton v-for="index in 4" :key="index" width="100%" height="2.75rem" /></div>
                                    <div v-else-if="optionsState.categoriesError" class="rounded-xl bg-red-50 p-3 text-sm text-red-800"><p>No se pudieron cargar.</p><button type="button" class="mt-2 font-bold underline" @click="loadCategories">Reintentar</button></div>
                                    <div v-else class="space-y-1">
                                        <button type="button" class="filter-option" :class="!filters.category && 'filter-option-active'" :aria-pressed="!filters.category" @click="setFilter('category', undefined)">Todas las categorías</button>
                                        <button v-for="category in categories" :key="category.id" type="button" class="filter-option" :class="filters.category === category.slug && 'filter-option-active'" :aria-pressed="filters.category === category.slug" @click="setFilter('category', category.slug)">{{ category.name }}</button>
                                    </div>
                                </fieldset>

                                <fieldset>
                                    <legend class="mb-3 text-sm font-black uppercase tracking-wider text-slate-900">Marcas</legend>
                                    <div v-if="optionsState.brandsLoading" class="space-y-2" aria-busy="true"><Skeleton v-for="index in 4" :key="index" width="100%" height="2.75rem" /></div>
                                    <div v-else-if="optionsState.brandsError" class="rounded-xl bg-red-50 p-3 text-sm text-red-800"><p>No se pudieron cargar.</p><button type="button" class="mt-2 font-bold underline" @click="loadBrands">Reintentar</button></div>
                                    <div v-else class="space-y-1">
                                        <button type="button" class="filter-option" :class="!filters.brand && 'filter-option-active'" :aria-pressed="!filters.brand" @click="setFilter('brand', undefined)">Todas las marcas</button>
                                        <button v-for="brand in brands" :key="brand.id" type="button" class="filter-option" :class="filters.brand === brand.slug && 'filter-option-active'" :aria-pressed="filters.brand === brand.slug" @click="setFilter('brand', brand.slug)">{{ brand.name }}</button>
                                    </div>
                                </fieldset>

                                <div v-if="filters.viscosity || filters.type" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                                    <p class="font-bold">Filtros recibidos por URL</p>
                                    <p class="mt-1">Puedes retirarlos desde los chips superiores. El sistema no expone todavía una lista completa de estas opciones.</p>
                                </div>
                            </div>

                            <div class="sticky bottom-0 grid grid-cols-2 gap-3 border-t border-slate-200 bg-white p-4 lg:hidden">
                                <button type="button" class="btn-outline" :disabled="!activeFilterCount" @click="clearFilters">Limpiar</button>
                                <button type="button" class="btn-primary" @click="closeMobileFilters">Ver resultados</button>
                            </div>
                        </aside>
                    </div>

                    <section id="catalog-results" class="min-w-0 flex-1 scroll-mt-32" aria-labelledby="results-heading">
                        <h2 id="results-heading" class="sr-only">Resultados del catálogo</h2>
                        <div class="mb-6 hidden items-end justify-between gap-4 lg:flex">
                            <div>
                                <p class="text-2xl font-black text-slate-950" aria-hidden="true">Resultados</p>
                                <p class="mt-1 text-sm font-semibold text-slate-600">{{ resultsSummary }}</p>
                            </div>
                            <span v-if="loading" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-700" role="status"><i class="pi pi-spin pi-spinner" aria-hidden="true"></i> Actualizando</span>
                        </div>
                        <div v-if="loading" class="catalog-product-grid" aria-busy="true" aria-label="Cargando productos">
                            <div v-for="index in 6" :key="index" class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><Skeleton width="100%" height="13rem" /><div class="space-y-3 p-5"><Skeleton width="35%" /><Skeleton width="90%" height="1.5rem" /><Skeleton width="60%" /><Skeleton width="45%" height="2rem" /><Skeleton width="100%" height="2.75rem" /></div></div>
                        </div>

                        <div v-else-if="loadError" class="state-panel" role="alert">
                            <i class="pi pi-exclamation-circle" aria-hidden="true"></i><h2>No pudimos cargar los productos</h2><p>{{ loadError }}</p><button type="button" class="btn-primary" @click="loadProducts">Reintentar</button>
                        </div>

                        <div v-else-if="!products.length" class="state-panel">
                            <i class="pi pi-search" aria-hidden="true"></i><h2>No encontramos productos</h2><p>Prueba con otra búsqueda o elimina algunos filtros.</p><button v-if="activeFilterCount" type="button" class="btn-primary" @click="clearFilters">Limpiar filtros</button>
                        </div>

                        <div v-else class="catalog-product-grid">
                            <ProductCard v-for="product in products" :key="product.id" :product="product" variant="catalog" />
                        </div>

                        <nav v-if="!loading && !loadError && meta.last_page > 1" class="mt-9 flex flex-wrap items-center justify-center gap-2" aria-label="Paginación del catálogo">
                            <button type="button" class="pagination-button" :disabled="meta.current_page <= 1" aria-label="Página anterior" @click="setPage(meta.current_page - 1)"><i class="pi pi-chevron-left" aria-hidden="true"></i><span class="hidden sm:inline">Anterior</span></button>
                            <div class="hidden items-center gap-2 sm:flex">
                                <button v-for="page in pageNumbers" :key="page" type="button" class="pagination-page" :class="page === meta.current_page && 'pagination-page-active'" :aria-current="page === meta.current_page ? 'page' : undefined" :aria-label="`Ir a la página ${page}`" @click="setPage(page)">{{ page }}</button>
                            </div>
                            <span class="min-h-11 px-2 py-3 text-sm font-bold text-slate-700 sm:hidden">{{ meta.current_page }} / {{ meta.last_page }}</span>
                            <button type="button" class="pagination-button" :disabled="meta.current_page >= meta.last_page" aria-label="Página siguiente" @click="setPage(meta.current_page + 1)"><span class="hidden sm:inline">Siguiente</span><i class="pi pi-chevron-right" aria-hidden="true"></i></button>
                        </nav>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import Skeleton from 'primevue/skeleton';
import api from '@/api';
import ProductCard from '@/components/ProductCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const route = useRoute();
const router = useRouter();
const products = ref([]);
const categories = ref([]);
const brands = ref([]);
const meta = ref({ current_page: 1, last_page: 1, from: 0, to: 0, total: 0 });
const loading = ref(true);
const loadError = ref('');
const searchDraft = ref('');
const mobileFiltersOpen = ref(false);
const filterPanel = ref(null);
const closeFiltersButton = ref(null);
const optionsState = reactive({ categoriesLoading: true, categoriesError: false, brandsLoading: true, brandsError: false });
let productRequestController;
let productRequestId = 0;
let previouslyFocused;
let previousBodyOverflow = '';

const firstString = value => Array.isArray(value) ? String(value[0] || '') : String(value || '');
const filters = computed(() => {
    const sort = firstString(route.query.sort);
    const page = Number.parseInt(firstString(route.query.page), 10);
    return {
        search: firstString(route.query.search).trim(),
        category: firstString(route.query.category).trim(),
        brand: firstString(route.query.brand).trim(),
        viscosity: firstString(route.query.viscosity).trim(),
        type: firstString(route.query.type).trim(),
        sort: ['newest', 'price_asc', 'price_desc'].includes(sort) ? sort : 'newest',
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };
});
const activeFilterCount = computed(() => ['search', 'category', 'brand', 'viscosity', 'type'].filter(key => filters.value[key]).length);
const categoryName = slug => categories.value.find(item => item.slug === slug)?.name || slug;
const brandName = slug => brands.value.find(item => item.slug === slug)?.name || slug;
const activeFilters = computed(() => [
    filters.value.search && { key: 'search', label: `Búsqueda: ${filters.value.search}` },
    filters.value.category && { key: 'category', label: `Categoría: ${categoryName(filters.value.category)}` },
    filters.value.brand && { key: 'brand', label: `Marca: ${brandName(filters.value.brand)}` },
    filters.value.viscosity && { key: 'viscosity', label: `Viscosidad: ${filters.value.viscosity}` },
    filters.value.type && { key: 'type', label: `Tipo: ${filters.value.type}` },
].filter(Boolean));
const resultsSummary = computed(() => {
    if (loading.value && !meta.value.total) return 'Buscando productos…';
    if (!meta.value.total) return '0 productos';
    return `${meta.value.total} productos · ${meta.value.from}-${meta.value.to}`;
});
const pageNumbers = computed(() => {
    const last = Number(meta.value.last_page || 1);
    const current = Number(meta.value.current_page || 1);
    const start = Math.max(1, Math.min(current - 2, last - 4));
    return Array.from({ length: Math.min(5, last) }, (_, index) => start + index);
});
const requestSignature = computed(() => JSON.stringify(filters.value));

const cleanQuery = query => Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined && value !== null && String(value).trim() !== ''));
const updateQuery = (changes, replace = false) => {
    const next = cleanQuery({ ...route.query, ...changes });
    if (next.page === '1' || next.page === 1) delete next.page;
    if (next.sort === 'newest') delete next.sort;
    return router[replace ? 'replace' : 'push']({ path: '/catalog', query: next });
};
const setFilter = (key, value) => updateQuery({ [key]: value, page: undefined });
const removeFilter = key => updateQuery({ [key]: undefined, page: undefined });
const clearFilters = () => updateQuery({ search: undefined, category: undefined, brand: undefined, viscosity: undefined, type: undefined, sort: undefined, page: undefined });
const setSort = value => updateQuery({ sort: value === 'newest' ? undefined : value, page: undefined });
const setPage = page => {
    if (page < 1 || page > Number(meta.value.last_page || 1) || page === Number(meta.value.current_page)) return;
    updateQuery({ page: page === 1 ? undefined : page });
};
const submitSearch = () => updateQuery({ search: searchDraft.value.trim() || undefined, page: undefined }, true);

const loadProducts = async () => {
    productRequestController?.abort();
    const controller = new AbortController();
    productRequestController = controller;
    const requestId = ++productRequestId;
    loading.value = true;
    loadError.value = '';
    products.value = [];
    try {
        const response = await api.get('/products', { params: cleanQuery(filters.value), signal: controller.signal });
        if (requestId !== productRequestId) return;
        products.value = Array.isArray(response.data?.data) ? response.data.data : [];
        meta.value = response.data;
    } catch (error) {
        if (error.code === 'ERR_CANCELED' || requestId !== productRequestId) return;
        loadError.value = 'Revisa tu conexión e inténtalo nuevamente.';
    } finally {
        if (requestId === productRequestId) loading.value = false;
    }
};
const loadCategories = async () => {
    optionsState.categoriesLoading = true; optionsState.categoriesError = false;
    try { const { data } = await api.get('/categories'); categories.value = (Array.isArray(data) ? data : []).filter(item => item.is_active !== false); }
    catch { optionsState.categoriesError = true; }
    finally { optionsState.categoriesLoading = false; }
};
const loadBrands = async () => {
    optionsState.brandsLoading = true; optionsState.brandsError = false;
    try { const { data } = await api.get('/brands'); brands.value = (Array.isArray(data) ? data : []).filter(item => item.is_active !== false); }
    catch { optionsState.brandsError = true; }
    finally { optionsState.brandsLoading = false; }
};

const openMobileFilters = async () => {
    previouslyFocused = document.activeElement;
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    mobileFiltersOpen.value = true;
    await nextTick();
    closeFiltersButton.value?.focus();
};
const closeMobileFilters = () => {
    mobileFiltersOpen.value = false;
    document.body.style.overflow = previousBodyOverflow;
    nextTick(() => previouslyFocused?.focus?.());
};
const handleFilterPanelKeydown = event => {
    if (event.key === 'Escape') { event.preventDefault(); closeMobileFilters(); return; }
    if (event.key !== 'Tab') return;
    const focusable = [...filterPanel.value.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), a[href]')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
};

watch(requestSignature, loadProducts, { immediate: true });
watch(() => filters.value.search, value => { searchDraft.value = value; }, { immediate: true });
watch(() => route.fullPath, path => { if (route.path === '/catalog') sessionStorage.setItem('catalog:last-path', path); }, { immediate: true });
onMounted(() => Promise.allSettled([loadCategories(), loadBrands()]));
onBeforeRouteLeave(() => {
    sessionStorage.setItem(`catalog:scroll:${route.fullPath}`, String(window.scrollY));
    if (mobileFiltersOpen.value) closeMobileFilters();
});
onBeforeUnmount(() => {
    productRequestController?.abort();
    document.body.style.overflow = previousBodyOverflow;
});
</script>

<style scoped>
.catalog-product-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; }
.filter-option { display: flex; min-height: 44px; width: 100%; align-items: center; border-radius: .75rem; padding: .55rem .75rem; text-align: left; font-size: .875rem; font-weight: 600; color: #475569; transition: background-color .15s ease, color .15s ease; }
.filter-option:hover { background: #f1f5f9; color: #1d4ed8; }
.filter-option:focus-visible { outline: 2px solid #1d4ed8; outline-offset: 2px; }
.filter-option-active { background: #eff6ff; color: #1e40af; font-weight: 800; }
.pagination-button, .pagination-page { display: inline-flex; min-height: 44px; min-width: 44px; align-items: center; justify-content: center; gap: .4rem; border: 1px solid #cbd5e1; border-radius: .75rem; background: white; padding: .6rem .8rem; color: #334155; font-size: .875rem; font-weight: 800; transition: background-color .15s ease, border-color .15s ease; }
.pagination-button:hover:not(:disabled), .pagination-page:hover { border-color: #93c5fd; background: #eff6ff; color: #1d4ed8; }
.pagination-button:focus-visible, .pagination-page:focus-visible { outline: 2px solid #1d4ed8; outline-offset: 2px; }
.pagination-button:disabled { cursor: not-allowed; opacity: .4; }
.pagination-page-active { border-color: #1d4ed8; background: #1d4ed8; color: white; }
@media (min-width: 640px) { .catalog-product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; } }
@media (min-width: 1024px) { .catalog-product-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (prefers-reduced-motion: reduce) { .filter-option, .pagination-button, .pagination-page { transition: none; } }
</style>
