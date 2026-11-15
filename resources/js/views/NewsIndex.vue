<template>
    <AppLayout>
        <section class="bg-white">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
                <nav aria-label="Migas de pan" class="mb-6 text-sm text-slate-600">
                    <RouterLink to="/" class="rounded font-semibold text-blue-700 hover:text-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Inicio</RouterLink>
                    <span class="mx-2" aria-hidden="true">/</span><span aria-current="page">Guías</span>
                </nav>
                <div class="flex flex-col gap-6 border-b border-slate-200 pb-8 md:flex-row md:items-end md:justify-between">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wider text-blue-700">LubriStore</p>
                        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Consejos y guías</h1>
                        <p class="mt-3 max-w-2xl text-lg leading-relaxed text-slate-600">Información útil sobre lubricación y mantenimiento automotriz</p>
                    </div>
                    <form class="w-full md:max-w-sm" role="search" @submit.prevent="applySearch">
                        <label for="guide-search" class="mb-2 block text-sm font-semibold text-slate-800">Buscar en las guías</label>
                        <div class="flex gap-2">
                            <input
                                id="guide-search"
                                v-model="searchText"
                                type="search"
                                maxlength="100"
                                autocomplete="off"
                                placeholder="Título o contenido"
                                class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 px-3 text-slate-900 outline-none focus-visible:border-blue-700 focus-visible:ring-2 focus-visible:ring-blue-200"
                                @input="scheduleSearch"
                            >
                            <button type="submit" class="min-h-11 rounded-xl bg-blue-700 px-4 font-bold text-white hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Buscar</button>
                        </div>
                    </form>
                </div>

                <p v-if="loading" class="mt-8 rounded-xl bg-slate-50 p-5 text-slate-700" role="status" aria-live="polite">Cargando guías…</p>
                <div v-else-if="loadError" class="mt-8 rounded-2xl border border-rose-200 bg-rose-50 p-6 text-rose-900" role="alert">
                    <h2 class="font-bold">No pudimos cargar las guías</h2>
                    <p class="mt-1">Inténtalo nuevamente en unos momentos.</p>
                    <button type="button" class="mt-4 rounded-lg border border-rose-300 px-4 py-2 font-semibold hover:bg-rose-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700" @click="loadPage">Reintentar</button>
                </div>
                <template v-else>
                    <p class="mt-8 text-sm text-slate-600" aria-live="polite">
                        {{ resultSummary }}
                    </p>
                    <div v-if="items.length" class="mt-5 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <NewsCard v-for="article in items" :key="article.slug" :article="article" heading-level="h2" />
                    </div>
                    <div v-else class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-6 py-12 text-center">
                        <i class="pi pi-search text-3xl text-slate-500" aria-hidden="true"></i>
                        <h2 class="mt-3 text-xl font-bold text-slate-900">{{ searchQuery ? 'No encontramos guías con esa búsqueda' : 'Aún no hay guías publicadas' }}</h2>
                        <p class="mt-2 text-slate-600">{{ searchQuery ? 'Prueba con otras palabras.' : 'Vuelve pronto para leer nuevas publicaciones.' }}</p>
                        <button v-if="searchQuery" type="button" class="mt-4 rounded-lg px-4 py-2 font-semibold text-blue-700 underline underline-offset-4 hover:text-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="clearSearch">Limpiar búsqueda</button>
                    </div>

                    <nav v-if="lastPage > 1" class="mt-10 flex flex-wrap items-center justify-center gap-3" aria-label="Paginación de guías">
                        <button type="button" class="min-h-11 rounded-xl border border-slate-300 px-4 font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">Anterior</button>
                        <span class="text-sm font-medium text-slate-700" aria-live="polite">Página {{ currentPage }} de {{ lastPage }}</span>
                        <button type="button" class="min-h-11 rounded-xl border border-slate-300 px-4 font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="currentPage >= lastPage" @click="goToPage(currentPage + 1)">Siguiente</button>
                    </nav>
                </template>
            </div>
        </section>
    </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '@/api';
import AppLayout from '@/layouts/AppLayout.vue';
import NewsCard from '@/components/NewsCard.vue';
import { getSiteSeoConfig, useSiteSeo } from '@/composables/useSiteSeo';
import { NEWS_PAGE_SIZE, normalizeNewsPage, normalizeNewsQuery, newsResultSummary, newsRouteQuery } from '@/utils/newsPublic.mjs';

const route = useRoute();
const router = useRouter();
const seo = getSiteSeoConfig();
const items = ref([]);
const total = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);
const perPage = ref(NEWS_PAGE_SIZE);
const loading = ref(true);
const loadError = ref(false);
const searchText = ref('');
const searchQuery = ref('');
let requestController = null;
let requestSequence = 0;
let searchTimer = null;
let mounted = true;

const resultSummary = computed(() => newsResultSummary({
    total: total.value,
    currentPage: currentPage.value,
    perPage: perPage.value,
    itemCount: items.value.length,
}));

useSiteSeo(() => ({
    title: `Consejos y guías | ${seo.name}`,
    description: 'Información útil sobre lubricación y mantenimiento automotriz.',
    robots: !loading.value && !loadError.value ? 'index,follow' : 'noindex,nofollow',
    canonicalPath: !loading.value && !loadError.value ? '/guias' : null,
}));

function routeValues() {
    return normalizeNewsQuery(route.query);
}

async function loadPage() {
    const query = routeValues();
    const sequence = ++requestSequence;
    requestController?.abort();
    requestController = new AbortController();
    const controller = requestController;
    searchQuery.value = query.q;
    searchText.value = query.q;
    loading.value = true;
    loadError.value = false;

    try {
        const params = { page: query.page, per_page: NEWS_PAGE_SIZE };
        if (query.q) params.q = query.q;
        const response = await api.get('/news', { params, signal: controller.signal });
        if (!mounted || sequence !== requestSequence) return;
        const page = normalizeNewsPage(response.data);
        items.value = page.items;
        total.value = page.total;
        currentPage.value = page.currentPage;
        lastPage.value = page.lastPage;
        perPage.value = page.perPage;
    } catch (error) {
        if (!mounted || sequence !== requestSequence || error?.code === 'ERR_CANCELED' || error?.name === 'AbortError') return;
        items.value = [];
        total.value = 0;
        loadError.value = true;
    } finally {
        if (mounted && sequence === requestSequence) loading.value = false;
        if (requestController === controller) requestController = null;
    }
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applySearch, 350);
}

function applySearch() {
    clearTimeout(searchTimer);
    const q = searchText.value.trim().slice(0, 100);
    const query = newsRouteQuery(q, 1);
    const current = routeValues();
    if (current.q === q && current.page === 1) return;
    router.replace({ name: 'GuideIndex', query });
}

function clearSearch() {
    searchText.value = '';
    router.replace({ name: 'GuideIndex', query: {} });
}

function goToPage(page) {
    if (!Number.isSafeInteger(page) || page < 1 || page > lastPage.value || page === currentPage.value) return;
    router.push({ name: 'GuideIndex', query: newsRouteQuery(searchQuery.value, page) });
}

watch(() => [route.query.q, route.query.page], loadPage, { immediate: true });

onBeforeUnmount(() => {
    mounted = false;
    requestSequence += 1;
    requestController?.abort();
    clearTimeout(searchTimer);
});
</script>
