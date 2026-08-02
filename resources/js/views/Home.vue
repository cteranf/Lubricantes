<template>
    <AppLayout>
        <section class="hero-section" aria-labelledby="hero-heading">
            <div v-if="heroState.loading" class="hero-panel hero-fallback" aria-busy="true" aria-label="Cargando contenido principal">
                <div class="site-container relative z-10 flex h-full max-w-3xl flex-col justify-center">
                    <Skeleton width="min(34rem, 88%)" height="3.5rem" class="mb-5" />
                    <Skeleton width="min(28rem, 78%)" height="1.35rem" class="mb-3" />
                    <Skeleton width="min(22rem, 68%)" height="1.35rem" class="mb-8" />
                    <Skeleton width="10rem" height="3rem" borderRadius="0.75rem" />
                </div>
            </div>

            <Carousel
                v-else-if="slides.length"
                v-model:page="activeSlide"
                :value="slides"
                :numVisible="1"
                :numScroll="1"
                :circular="slides.length > 1"
                :autoplayInterval="prefersReducedMotion || slides.length === 1 ? 0 : 6000"
                :showNavigators="slides.length > 1"
                :showIndicators="slides.length > 1"
                aria-label="Promociones de LubriStore"
                class="hero-carousel"
            >
                <template #item="slotProps">
                    <div class="hero-panel">
                        <img
                            v-if="slotProps.data.image && !failedSlideImages[slotProps.data.image]"
                            :src="slotProps.data.image"
                            alt=""
                            aria-hidden="true"
                            class="absolute inset-0 h-full w-full object-cover object-center"
                            :loading="slotProps.index === 0 ? 'eager' : 'lazy'"
                            :fetchpriority="slotProps.index === 0 ? 'high' : 'auto'"
                            @error="markSlideImageFailed(slotProps.data.image)"
                        >
                        <div v-else class="hero-technical-bg absolute inset-0" aria-hidden="true"></div>
                        <div class="hero-overlay absolute inset-0" aria-hidden="true"></div>
                        <div class="site-container relative z-10 flex h-full items-center">
                            <div class="max-w-3xl py-12 text-white">
                                <p class="mb-3 text-xs font-bold uppercase tracking-[0.22em] text-amber-300 sm:text-sm">Rendimiento y protección para tu vehículo</p>
                                <component :is="slotProps.index === activeSlide ? 'h1' : 'h2'" :id="slotProps.index === activeSlide ? 'hero-heading' : undefined" class="hero-title max-w-2xl font-black tracking-tight">
                                    {{ slotProps.data.title || defaultHero.title }}
                                </component>
                                <p v-if="slotProps.data.description" class="mt-4 max-w-2xl text-base leading-relaxed text-slate-100 sm:text-lg lg:text-xl">{{ slotProps.data.description }}</p>
                                <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                                    <router-link to="/catalog" class="btn-primary">Ver catálogo <i class="pi pi-arrow-right" aria-hidden="true"></i></router-link>
                                    <a :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="btn-secondary-on-dark"><i class="pi pi-whatsapp" aria-hidden="true"></i> Solicitar asesoría</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Carousel>

            <div v-else class="hero-panel hero-fallback">
                <div class="hero-technical-bg absolute inset-0" aria-hidden="true"></div>
                <div class="hero-overlay absolute inset-0" aria-hidden="true"></div>
                <div class="site-container relative z-10 flex h-full items-center">
                    <div class="max-w-3xl py-12 text-white">
                        <p class="mb-3 text-xs font-bold uppercase tracking-[0.22em] text-amber-300 sm:text-sm">LubriStore</p>
                        <h1 id="hero-heading" class="hero-title max-w-2xl font-black tracking-tight">{{ defaultHero.title }}</h1>
                        <p class="mt-4 max-w-2xl text-base leading-relaxed text-slate-100 sm:text-lg lg:text-xl">{{ defaultHero.description }}</p>
                        <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                            <router-link to="/catalog" class="btn-primary">Ver catálogo <i class="pi pi-arrow-right" aria-hidden="true"></i></router-link>
                            <a :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="btn-secondary-on-dark"><i class="pi pi-whatsapp" aria-hidden="true"></i> Solicitar asesoría</a>
                        </div>
                        <button v-if="heroState.error" type="button" class="mt-5 min-h-11 text-sm font-semibold text-white underline decoration-white/50 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4" @click="loadSliders">Reintentar contenido principal</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-y border-slate-200 bg-white" aria-label="Beneficios de comprar en LubriStore">
            <div class="site-container grid grid-cols-1 divide-y divide-slate-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4">
                <div v-for="benefit in benefits" :key="benefit.title" class="flex items-start gap-3 px-1 py-5 sm:px-5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700" aria-hidden="true"><i :class="benefit.icon"></i></span>
                    <div><h2 class="text-sm font-bold text-slate-900">{{ benefit.title }}</h2><p class="mt-1 text-sm leading-5 text-slate-600">{{ benefit.description }}</p></div>
                </div>
            </div>
        </section>

        <section id="categories" class="section-shell bg-slate-50" aria-labelledby="categories-heading">
            <div class="site-container">
                <div class="section-heading text-center"><p class="eyebrow">Encuentra lo que necesitas</p><h2 id="categories-heading">Compra por categoría</h2><p>Explora las líneas disponibles para el cuidado y mantenimiento de tu vehículo.</p></div>
                <div v-if="categoriesState.loading" class="category-grid mt-9" aria-busy="true">
                    <div v-for="index in 5" :key="index" class="category-card surface-card items-center text-center"><Skeleton shape="circle" size="5rem" class="mb-4" /><Skeleton width="70%" height="1.25rem" /></div>
                </div>
                <div v-else-if="categoriesState.error" class="state-panel mt-9" role="alert">
                    <i class="pi pi-exclamation-circle" aria-hidden="true"></i><h3>No pudimos cargar las categorías</h3><p>Puedes reintentar o continuar directamente al catálogo.</p><div class="flex flex-wrap justify-center gap-3"><button type="button" class="btn-outline" @click="loadCategories">Reintentar</button><router-link to="/catalog" class="btn-primary">Ver catálogo</router-link></div>
                </div>
                <div v-else-if="categories.length" class="category-grid mt-9">
                    <router-link v-for="category in categories" :key="category.id" :to="{ path: '/catalog', query: { category: category.slug } }" class="category-card surface-card group">
                        <span class="mb-4 flex h-20 w-20 items-center justify-center overflow-hidden rounded-2xl bg-slate-100 sm:h-24 sm:w-24"><img v-if="category.image" :src="category.image" :alt="category.name" class="h-full w-full object-cover" loading="lazy"><i v-else class="pi pi-box text-3xl text-slate-400 transition-colors group-hover:text-blue-700" aria-hidden="true"></i></span>
                        <h3 class="text-base font-bold leading-snug text-slate-900 sm:text-lg">{{ category.name }}</h3><span class="mt-2 text-sm font-semibold text-blue-700">Explorar <i class="pi pi-arrow-right ml-1 text-xs" aria-hidden="true"></i></span>
                    </router-link>
                </div>
                <div v-else class="state-panel mt-9"><i class="pi pi-folder-open" aria-hidden="true"></i><h3>Aún no hay categorías disponibles</h3><p>Mientras tanto, puedes revisar todos los productos del catálogo.</p><router-link to="/catalog" class="btn-primary">Ver catálogo</router-link></div>
            </div>
        </section>

        <section class="section-shell bg-white" aria-labelledby="featured-heading">
            <div class="site-container">
                <div class="flex items-end justify-between gap-5">
                    <div class="section-heading max-w-2xl"><p class="eyebrow">Selección LubriStore</p><h2 id="featured-heading">Productos destacados</h2><p>Opciones reales del catálogo, con disponibilidad actualizada.</p></div>
                    <router-link to="/catalog" class="hidden min-h-11 shrink-0 items-center gap-2 font-bold text-blue-700 hover:text-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 sm:flex">Ver todos <i class="pi pi-arrow-right" aria-hidden="true"></i></router-link>
                </div>
                <div v-if="productsState.loading" class="product-grid mt-9" aria-busy="true">
                    <div v-for="index in 4" :key="index" class="surface-card overflow-hidden"><Skeleton width="100%" height="13rem" /><div class="space-y-3 p-5"><Skeleton width="35%" height="1rem" /><Skeleton width="90%" height="1.5rem" /><Skeleton width="60%" height="1.5rem" /><Skeleton width="100%" height="2.75rem" /></div></div>
                </div>
                <div v-else-if="productsState.error" class="state-panel mt-9" role="alert"><i class="pi pi-exclamation-circle" aria-hidden="true"></i><h3>No pudimos cargar los productos</h3><p>Reintenta la consulta o visita el catálogo completo.</p><div class="flex flex-wrap justify-center gap-3"><button type="button" class="btn-outline" @click="loadProducts">Reintentar</button><router-link to="/catalog" class="btn-primary">Ver catálogo</router-link></div></div>
                <div v-else-if="featuredProducts.length" class="product-grid mt-9"><ProductCard v-for="product in featuredProducts" :key="product.id" :product="product" /></div>
                <div v-else class="state-panel mt-9"><i class="pi pi-shopping-bag" aria-hidden="true"></i><h3>Aún no hay productos disponibles</h3><p>Vuelve pronto para conocer las novedades del catálogo.</p><router-link to="/catalog" class="btn-primary">Explorar catálogo</router-link></div>
                <router-link to="/catalog" class="btn-outline mt-7 w-full sm:hidden">Ver todos los productos</router-link>
            </div>
        </section>

        <section class="section-shell bg-slate-950 text-white" aria-labelledby="advice-heading">
            <div class="site-container"><div class="advice-panel">
                <div class="max-w-2xl"><p class="eyebrow text-amber-300">Asesoría antes de comprar</p><h2 id="advice-heading" class="text-3xl font-black tracking-tight sm:text-4xl">¿No sabes qué lubricante necesita tu vehículo?</h2><p class="mt-4 text-base leading-relaxed text-slate-300 sm:text-lg">Cuéntanos qué vehículo tienes y recibe orientación antes de elegir un producto.</p></div>
                <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row xl:flex-col"><router-link to="/catalog" class="btn-primary">Ver catálogo</router-link><a :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="btn-secondary-on-dark"><i class="pi pi-whatsapp" aria-hidden="true"></i> Consultar por WhatsApp</a></div>
            </div></div>
        </section>

        <section v-if="newsState.loading || newsState.error || newsList.length" class="section-shell bg-slate-50" aria-labelledby="news-heading">
            <div class="site-container">
                <div class="section-heading text-center"><p class="eyebrow">Consejos y novedades</p><h2 id="news-heading">Noticias y guías</h2></div>
                <div v-if="newsState.loading" class="mt-9 grid grid-cols-1 gap-6 md:grid-cols-3" aria-busy="true"><div v-for="index in 3" :key="index" class="surface-card overflow-hidden"><Skeleton width="100%" height="12rem" /><div class="space-y-3 p-5"><Skeleton width="80%" height="1.5rem" /><Skeleton width="100%" /><Skeleton width="70%" /></div></div></div>
                <div v-else-if="newsState.error" class="state-panel mt-9" role="alert"><i class="pi pi-exclamation-circle" aria-hidden="true"></i><h3>No pudimos cargar las noticias</h3><p>El resto de la tienda continúa disponible.</p><button type="button" class="btn-outline" @click="loadNews">Reintentar</button></div>
                <div v-else class="mt-9 grid grid-cols-1 gap-6 md:grid-cols-3"><article v-for="news in newsList" :key="news.id" class="surface-card overflow-hidden"><div class="flex aspect-[16/9] items-center justify-center overflow-hidden bg-slate-200"><img v-if="news.image_path" :src="news.image_path" :alt="news.title" class="h-full w-full object-cover" loading="lazy"><i v-else class="pi pi-book text-4xl text-slate-400" aria-hidden="true"></i></div><div class="p-5"><h3 class="text-xl font-bold text-slate-900">{{ news.title }}</h3><p v-if="news.summary" class="mt-3 line-clamp-3 leading-relaxed text-slate-600">{{ news.summary }}</p></div></article></div>
            </div>
        </section>
    </AppLayout>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Carousel from 'primevue/carousel';
import Skeleton from 'primevue/skeleton';
import api from '@/api';
import ProductCard from '@/components/ProductCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const defaultHero = { title: 'Protección confiable para cada kilómetro', description: 'Encuentra lubricantes y productos para cuidar el rendimiento de tu vehículo.' };
const benefits = [
    { icon: 'pi pi-shield', title: 'Productos de calidad', description: 'Alternativas para el cuidado de tu vehículo.' },
    { icon: 'pi pi-lock', title: 'Compra segura', description: 'Un proceso de compra claro y protegido.' },
    { icon: 'pi pi-truck', title: 'Entrega coordinada', description: 'Consulta las opciones disponibles para tu pedido.' },
    { icon: 'pi pi-whatsapp', title: 'Asesoría por WhatsApp', description: 'Orientación para elegir antes de comprar.' },
];
const slides = ref([]);
const categories = ref([]);
const featuredProducts = ref([]);
const newsList = ref([]);
const activeSlide = ref(0);
const failedSlideImages = reactive({});
const prefersReducedMotion = ref(false);
let motionQuery;
const sectionState = () => reactive({ loading: true, error: false });
const heroState = sectionState();
const categoriesState = sectionState();
const productsState = sectionState();
const newsState = sectionState();
const phone = import.meta.env.VITE_WHATSAPP_PHONE || '51999999999';
const whatsappLink = `https://wa.me/${phone}?text=${encodeURIComponent('Hola, necesito asesoría para elegir un producto de LubriStore.')}`;

const runLoad = async (state, request) => {
    state.loading = true;
    state.error = false;
    try { await request(); } catch (error) { state.error = true; if (import.meta.env.DEV) console.error(error); } finally { state.loading = false; }
};
const isPlaceholderSlide = slide => {
    const title = slide.title?.trim().toLowerCase() || '';
    const description = slide.description?.trim() || '';
    return ['prueba', 'test', 'demo'].includes(title) || (title.length < 8 && description.length < 8);
};
const loadSliders = () => runLoad(heroState, async () => {
    const response = await api.get('/sliders');
    slides.value = (Array.isArray(response.data) ? response.data : []).map(slide => {
        const placeholder = isPlaceholderSlide(slide);
        return {
            id: slide.id,
            title: placeholder ? defaultHero.title : (slide.title?.trim() || defaultHero.title),
            description: placeholder ? defaultHero.description : (slide.description?.trim() || ''),
            image: placeholder ? null : (slide.image_path || null),
        };
    });
    activeSlide.value = 0;
});
const loadCategories = () => runLoad(categoriesState, async () => {
    const response = await api.get('/categories');
    categories.value = (Array.isArray(response.data) ? response.data : []).filter(category => ![false, 0, '0'].includes(category.is_active));
});
const loadProducts = () => runLoad(productsState, async () => {
    const response = await api.get('/products');
    featuredProducts.value = (Array.isArray(response.data?.data) ? response.data.data : []).slice(0, 4);
});
const loadNews = () => runLoad(newsState, async () => {
    const response = await api.get('/news');
    newsList.value = Array.isArray(response.data) ? response.data : [];
});
const markSlideImageFailed = image => { if (image) failedSlideImages[image] = true; };
const syncMotionPreference = event => { prefersReducedMotion.value = event.matches; };
onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    prefersReducedMotion.value = motionQuery.matches;
    motionQuery.addEventListener?.('change', syncMotionPreference);
    Promise.allSettled([loadSliders(), loadCategories(), loadProducts(), loadNews()]);
});
onBeforeUnmount(() => motionQuery?.removeEventListener?.('change', syncMotionPreference));
</script>

<style scoped>
.hero-panel { position: relative; height: 27rem; overflow: hidden; background: #07152f; }
.hero-overlay { background: linear-gradient(90deg, rgba(3, 12, 31, .94) 0%, rgba(6, 25, 55, .8) 48%, rgba(6, 25, 55, .34) 100%); }
.hero-technical-bg { background-color: #081a38; background-image: radial-gradient(circle at 78% 35%, rgba(37, 99, 235, .55), transparent 24%), radial-gradient(circle at 85% 70%, rgba(245, 158, 11, .25), transparent 18%), linear-gradient(135deg, transparent 0 48%, rgba(255,255,255,.045) 48% 50%, transparent 50% 100%); background-size: auto, auto, 52px 52px; }
.hero-title { font-size: clamp(2.1rem, 6vw, 4.5rem); line-height: 1.02; text-wrap: balance; }
.category-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: .9rem; }
.category-card { display: flex; min-height: 12rem; flex: 0 1 calc(50% - .45rem); flex-direction: column; align-items: center; justify-content: center; padding: 1.1rem .75rem; text-align: center; }
.product-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; }
.advice-panel { display: flex; flex-direction: column; gap: 2rem; align-items: flex-start; justify-content: space-between; border: 1px solid rgba(148, 163, 184, .22); border-radius: 1.5rem; background: linear-gradient(135deg, rgba(30, 64, 175, .28), rgba(15, 23, 42, .4)); padding: clamp(1.5rem, 5vw, 3.5rem); }
:deep(.hero-carousel .p-carousel-content) { position: relative; }
:deep(.hero-carousel .p-carousel-prev), :deep(.hero-carousel .p-carousel-next) { position: absolute; z-index: 20; top: 50%; min-width: 44px; min-height: 44px; transform: translateY(-50%); border: 1px solid rgba(255,255,255,.45); background: rgba(3,12,31,.58); color: white; }
:deep(.hero-carousel .p-carousel-prev) { left: .75rem; }
:deep(.hero-carousel .p-carousel-next) { right: .75rem; }
:deep(.hero-carousel .p-carousel-indicator-list) { position: absolute; z-index: 20; bottom: 1rem; left: 50%; margin: 0; transform: translateX(-50%); }
:deep(.hero-carousel .p-carousel-indicator-button) { min-width: 2.25rem; height: .35rem; background: rgba(255,255,255,.55); }
:deep(.hero-carousel .p-carousel-indicator-active .p-carousel-indicator-button) { background: #fbbf24; }
@media (min-width: 480px) { .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 640px) { .category-card { flex-basis: calc(33.333% - .6rem); padding: 1.4rem 1rem; } }
@media (min-width: 768px) { .hero-panel { height: 28rem; } .product-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.5rem; } }
@media (min-width: 1024px) { .category-card { flex-basis: calc(20% - .75rem); } }
@media (min-width: 1280px) { .category-card { flex-basis: calc(20% - .75rem); } .product-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } .advice-panel { flex-direction: row; align-items: center; } }
@media (min-width: 1280px) { .hero-panel { height: 30rem; } }
@media (max-width: 639px) { .hero-overlay { background: linear-gradient(90deg, rgba(3, 12, 31, .92), rgba(6, 25, 55, .7)); } :deep(.hero-carousel .p-carousel-prev) { left: .25rem; } :deep(.hero-carousel .p-carousel-next) { right: .25rem; } }
@media (prefers-reduced-motion: reduce) { :deep(.hero-carousel *) { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; } }
</style>
