<template>
    <AppLayout>
        <main class="mx-auto w-full max-w-4xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
            <div v-if="loading" class="rounded-2xl bg-white p-8 text-center text-slate-700 shadow-sm" role="status" aria-live="polite">Cargando artículo…</div>
            <section v-else-if="notFound" class="rounded-2xl border border-slate-200 bg-white px-6 py-14 text-center shadow-sm" role="status">
                <i class="pi pi-file text-4xl text-slate-500" aria-hidden="true"></i>
                <h1 class="mt-4 text-2xl font-black text-slate-950">No encontramos este artículo</h1>
                <p class="mt-2 text-slate-600">Puede que ya no esté disponible.</p>
                <RouterLink :to="{ name: 'GuideIndex' }" class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-blue-700 px-5 font-bold text-white hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Volver a las guías</RouterLink>
            </section>
            <section v-else-if="loadError" class="rounded-2xl border border-rose-200 bg-rose-50 px-6 py-12 text-center text-rose-900" role="alert">
                <h1 class="text-2xl font-black">No pudimos cargar el artículo</h1>
                <p class="mt-2">Inténtalo nuevamente en unos momentos.</p>
                <button type="button" class="mt-5 min-h-11 rounded-xl border border-rose-300 px-5 font-semibold hover:bg-rose-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700" @click="loadArticle(route.params.slug)">Reintentar</button>
            </section>
            <article v-else-if="article" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <nav aria-label="Migas de pan" class="px-5 pt-6 text-sm text-slate-600 sm:px-9 sm:pt-9">
                    <RouterLink to="/" class="rounded font-semibold text-blue-700 hover:text-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Inicio</RouterLink>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <RouterLink :to="{ name: 'GuideIndex' }" class="rounded font-semibold text-blue-700 hover:text-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Guías</RouterLink>
                    <span class="mx-2" aria-hidden="true">/</span><span aria-current="page">{{ article.title }}</span>
                </nav>
                <header class="px-5 pb-7 pt-5 sm:px-9 sm:pb-9">
                    <time v-if="article.published_at" :datetime="article.published_at" class="text-sm font-semibold text-slate-500">{{ formatCalendarDate(article.published_at) }}</time>
                    <h1 class="mt-3 text-3xl font-black leading-tight tracking-tight text-slate-950 sm:text-4xl">{{ article.title }}</h1>
                    <p v-if="article.summary" class="mt-4 max-w-3xl text-lg leading-relaxed text-slate-600">{{ article.summary }}</p>
                </header>
                <div class="aspect-[16/9] w-full overflow-hidden bg-slate-100">
                    <img v-if="hasImage" :src="article.image_url" :alt="article.title" width="1200" height="675" decoding="async" class="h-full w-full object-cover" @error="imageFailed = true">
                    <div v-else class="flex h-full items-center justify-center text-blue-700" aria-hidden="true"><i class="pi pi-book text-5xl"></i></div>
                </div>
                <div class="px-5 py-8 sm:px-9 sm:py-11">
                    <!-- content_html proviene exclusivamente de NewsDetailResource/NewsContentRenderer; el Markdown original nunca se renderiza aquí. -->
                    <div class="news-article-content" v-html="article.content_html"></div>
                    <div class="mt-10 border-t border-slate-200 pt-6">
                        <RouterLink :to="{ name: 'GuideIndex' }" class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 font-bold text-blue-700 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">
                            <i class="pi pi-arrow-left" aria-hidden="true"></i> Volver a las guías
                        </RouterLink>
                    </div>
                </div>
            </article>
        </main>
    </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import api from '@/api';
import AppLayout from '@/layouts/AppLayout.vue';
import { getSiteSeoConfig, useSiteSeo } from '@/composables/useSiteSeo';
import { formatCalendarDate } from '@/utils/dateTime';

const route = useRoute();
const seo = getSiteSeoConfig();
const article = ref(null);
const loading = ref(true);
const notFound = ref(false);
const loadError = ref(false);
const imageFailed = ref(false);
let requestController = null;
let requestSequence = 0;
let mounted = true;

const hasImage = computed(() => typeof article.value?.image_url === 'string' && article.value.image_url !== '' && !imageFailed.value);

useSiteSeo(() => {
    if (loading.value || notFound.value || loadError.value || !article.value) {
        return {
            title: `Artículo no disponible | ${seo.name}`,
            description: 'El artículo solicitado no está disponible.',
            robots: 'noindex,nofollow',
            canonicalPath: null,
            image: null,
        };
    }

    const canonicalPath = `/guias/${encodeURIComponent(article.value.slug)}`;
    return {
        title: `${article.value.title} | ${seo.name}`,
        description: article.value.summary || `Lee ${article.value.title} en las guías de ${seo.name}.`,
        robots: 'index,follow',
        canonicalPath,
        image: article.value.image_url || undefined,
        imageAlt: article.value.title,
        breadcrumbs: [
            { name: 'Inicio', path: '/' },
            { name: 'Guías', path: '/guias' },
            { name: article.value.title, path: canonicalPath },
        ],
    };
});

async function loadArticle(slug) {
    const articleSlug = typeof slug === 'string' ? slug : '';
    const sequence = ++requestSequence;
    requestController?.abort();
    requestController = null;
    article.value = null;
    imageFailed.value = false;
    notFound.value = false;
    loadError.value = false;

    if (!articleSlug) {
        notFound.value = true;
        loading.value = false;
        return;
    }

    const controller = new AbortController();
    requestController = controller;
    loading.value = true;

    try {
        const response = await api.get(`/news/${encodeURIComponent(articleSlug)}`, { signal: controller.signal });
        if (!mounted || sequence !== requestSequence || String(route.params.slug || '') !== articleSlug) return;
        const result = response.data?.data;
        if (!result || typeof result !== 'object' || typeof result.title !== 'string' || typeof result.slug !== 'string' || typeof result.content_html !== 'string') {
            loadError.value = true;
            return;
        }
        article.value = result;
    } catch (error) {
        if (!mounted || sequence !== requestSequence || error?.code === 'ERR_CANCELED' || error?.name === 'AbortError') return;
        if (error?.response?.status === 404) notFound.value = true;
        else loadError.value = true;
    } finally {
        if (mounted && sequence === requestSequence) loading.value = false;
        if (requestController === controller) requestController = null;
    }
}

watch(() => route.params.slug, loadArticle, { immediate: true });

onBeforeUnmount(() => {
    mounted = false;
    requestSequence += 1;
    requestController?.abort();
});
</script>

<style scoped>
.news-article-content { color: #334155; font-size: 1.0625rem; line-height: 1.85; overflow-wrap: anywhere; }
.news-article-content :deep(p) { margin: 1.15rem 0; }
.news-article-content :deep(h1), .news-article-content :deep(h2), .news-article-content :deep(h3), .news-article-content :deep(h4) { margin: 2rem 0 .75rem; color: #0f172a; font-weight: 800; line-height: 1.25; }
.news-article-content :deep(h1) { font-size: 2rem; }
.news-article-content :deep(h2) { font-size: 1.65rem; }
.news-article-content :deep(h3), .news-article-content :deep(h4) { font-size: 1.3rem; }
.news-article-content :deep(ul), .news-article-content :deep(ol) { margin: 1rem 0; padding-left: 1.5rem; }
.news-article-content :deep(ul) { list-style: disc; }
.news-article-content :deep(ol) { list-style: decimal; }
.news-article-content :deep(li) { margin: .35rem 0; padding-left: .25rem; }
.news-article-content :deep(a) { color: #1d4ed8; font-weight: 600; text-decoration: underline; text-underline-offset: .2em; overflow-wrap: anywhere; }
.news-article-content :deep(a:focus-visible) { outline: 2px solid #1d4ed8; outline-offset: 3px; border-radius: 2px; }
.news-article-content :deep(blockquote) { margin: 1.5rem 0; border-left: 4px solid #2563eb; border-radius: 0 .75rem .75rem 0; background: #eff6ff; padding: .75rem 1rem; color: #1e3a8a; }
.news-article-content :deep(blockquote p:first-child) { margin-top: 0; }
.news-article-content :deep(blockquote p:last-child) { margin-bottom: 0; }
.news-article-content :deep(strong) { color: #0f172a; font-weight: 750; }
</style>
