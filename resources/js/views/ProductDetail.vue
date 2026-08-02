<template>
    <AppLayout>
        <main class="bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <nav class="mb-6 flex min-w-0 items-center gap-2 overflow-hidden text-sm text-slate-600" aria-label="Migas de pan">
                    <router-link to="/" class="shrink-0 hover:text-blue-700">Inicio</router-link><i class="pi pi-angle-right text-xs" aria-hidden="true"></i>
                    <router-link :to="catalogPath" class="shrink-0 hover:text-blue-700">Catálogo</router-link>
                    <template v-if="product"><i class="pi pi-angle-right text-xs" aria-hidden="true"></i><span class="truncate" aria-current="page">{{ product.name }}</span></template>
                </nav>

                <section v-if="loading" class="grid gap-8 lg:grid-cols-2 lg:gap-14" aria-label="Cargando producto" aria-busy="true">
                    <Skeleton height="min(78vw, 34rem)" border-radius="1rem" />
                    <div><Skeleton width="30%" height="1rem" class="mb-5" /><Skeleton width="85%" height="3rem" class="mb-6" /><Skeleton width="36%" height="2.5rem" class="mb-8" /><Skeleton width="100%" height="10rem" class="mb-8" /><Skeleton height="3.5rem" border-radius="0.75rem" /></div>
                </section>

                <section v-else-if="notFound" class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm" role="status">
                    <i class="pi pi-search mb-4 text-4xl text-slate-400" aria-hidden="true"></i><h1 class="text-2xl font-black text-slate-950">Producto no encontrado</h1><p class="mt-3 text-slate-600">El producto solicitado no existe o ya no está disponible.</p><router-link :to="catalogPath" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-blue-700 px-5 py-2.5 font-bold text-white hover:bg-blue-800">Volver al catálogo</router-link>
                </section>

                <section v-else-if="error" class="mx-auto max-w-xl rounded-2xl border border-red-200 bg-white p-8 text-center shadow-sm" role="alert">
                    <i class="pi pi-exclamation-circle mb-4 text-4xl text-red-500" aria-hidden="true"></i><h1 class="text-2xl font-black text-slate-950">No pudimos cargar el producto</h1><p class="mt-3 text-slate-600">{{ error }}</p><div class="mt-6 flex flex-wrap justify-center gap-3"><button type="button" class="min-h-11 rounded-xl bg-blue-700 px-5 py-2.5 font-bold text-white hover:bg-blue-800" @click="loadProduct">Reintentar</button><router-link :to="catalogPath" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 py-2.5 font-bold text-slate-700 hover:bg-slate-100">Ir al catálogo</router-link></div>
                </section>

                <template v-else-if="product">
                    <section class="grid gap-8 lg:grid-cols-2 lg:gap-14">
                        <div class="min-w-0">
                            <div class="flex aspect-square items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <img v-if="selectedImage && !failedImages.has(selectedImage)" :src="selectedImage" :alt="product.name" class="h-full w-full object-contain p-5 sm:p-8" @error="markImageFailed(selectedImage)">
                                <div v-else class="flex flex-col items-center gap-3 text-slate-400"><i class="pi pi-image text-6xl" aria-hidden="true"></i><span class="text-sm font-semibold">Imagen no disponible</span></div>
                            </div>
                            <div v-if="images.length > 1" class="mt-4 flex gap-3 overflow-x-auto pb-2" aria-label="Imágenes del producto">
                                <button v-for="(image, index) in images" :key="`${image}-${index}`" type="button" class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 bg-white p-1 transition" :class="selectedImage === image ? 'border-blue-700' : 'border-slate-200 hover:border-slate-400'" :aria-label="`Ver imagen ${index + 1}`" :aria-pressed="selectedImage === image" @click="selectedImage = image"><img :src="image" :alt="`Vista ${index + 1} de ${product.name}`" class="h-full w-full object-contain" loading="lazy"></button>
                            </div>
                        </div>

                        <div class="min-w-0 lg:py-2">
                            <p v-if="product.brand?.name" class="text-sm font-black uppercase tracking-widest text-blue-700">{{ product.brand.name }}</p>
                            <h1 class="mt-2 text-3xl font-black leading-tight text-slate-950 sm:text-4xl">{{ product.name }}</h1>
                            <p v-if="product.sku" class="mt-3 text-sm font-medium text-slate-500">SKU: {{ product.sku }}</p>
                            <div class="mt-6 flex flex-wrap items-baseline gap-3"><span v-if="hasDiscount" class="text-lg text-slate-500 line-through">{{ formatPen(product.price) }}</span><span class="text-3xl font-black text-slate-950">{{ formatPen(currentPrice) }}</span><span v-if="hasDiscount" class="rounded-full bg-amber-300 px-3 py-1 text-sm font-black text-slate-950">-{{ discount }}%</span></div>

                            <div class="mt-6 rounded-xl border p-4" :class="stock > 0 ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-100'">
                                <p class="font-bold" :class="stock > 0 ? 'text-emerald-800' : 'text-slate-700'"><i :class="stock > 0 ? 'pi pi-check-circle' : 'pi pi-times-circle'" class="mr-2" aria-hidden="true"></i>{{ stock > 0 ? `${stock} unidades disponibles` : 'Producto agotado' }}</p><p v-if="currentInCart" class="mt-1 text-sm text-slate-600">Ya tienes {{ currentInCart }} en el carrito.</p>
                            </div>

                            <dl v-if="attributes.length" class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3"><div v-for="attribute in attributes" :key="attribute.label" class="rounded-xl bg-white p-3 ring-1 ring-slate-200"><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ attribute.label }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ attribute.value }}</dd></div></dl>

                            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                                <div class="flex min-h-12 items-center justify-between rounded-xl border border-slate-300 bg-white sm:w-36" aria-label="Cantidad">
                                    <button type="button" class="min-h-12 min-w-11 rounded-l-xl hover:bg-slate-100 disabled:text-slate-300" :disabled="qty <= 1 || remainingStock <= 0" aria-label="Reducir cantidad" @click="qty--"><i class="pi pi-minus" aria-hidden="true"></i></button><output class="min-w-8 text-center font-black">{{ qty }}</output><button type="button" class="min-h-12 min-w-11 rounded-r-xl hover:bg-slate-100 disabled:text-slate-300" :disabled="qty >= remainingStock" aria-label="Aumentar cantidad" @click="qty++"><i class="pi pi-plus" aria-hidden="true"></i></button>
                                </div>
                                <button type="button" class="min-h-12 flex-1 rounded-xl bg-blue-700 px-6 py-3 font-black text-white shadow-sm transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600" :disabled="remainingStock <= 0" @click="addToCart"><i class="pi pi-cart-plus mr-2" aria-hidden="true"></i>{{ remainingStock > 0 ? 'Agregar al carrito' : 'No disponible' }}</button>
                            </div>
                            <a :href="whatsAppUrl" target="_blank" rel="noopener noreferrer" class="mt-3 flex min-h-12 w-full items-center justify-center rounded-xl border border-emerald-600 px-5 py-3 font-bold text-emerald-700 transition hover:bg-emerald-50"><i class="pi pi-whatsapp mr-2 text-lg" aria-hidden="true"></i>Consultar por WhatsApp</a>

                            <div class="mt-6 grid gap-3 sm:grid-cols-3" aria-label="Información de compra">
                                <div class="rounded-xl border border-slate-200 bg-white p-4"><i class="pi pi-truck text-xl text-blue-700" aria-hidden="true"></i><h2 class="mt-2 text-sm font-black text-slate-900">Envío o recojo</h2><p class="mt-1 text-xs leading-5 text-slate-600">Elige entrega o recojo durante el checkout.</p></div>
                                <div class="rounded-xl border border-slate-200 bg-white p-4"><i class="pi pi-shield text-xl text-blue-700" aria-hidden="true"></i><h2 class="mt-2 text-sm font-black text-slate-900">Compra validada</h2><p class="mt-1 text-xs leading-5 text-slate-600">Precio y disponibilidad se confirman al procesar el pedido.</p></div>
                                <div class="rounded-xl border border-slate-200 bg-white p-4"><i class="pi pi-comments text-xl text-blue-700" aria-hidden="true"></i><h2 class="mt-2 text-sm font-black text-slate-900">Asesoría</h2><p class="mt-1 text-xs leading-5 text-slate-600">Resuelve tus dudas del producto por WhatsApp.</p></div>
                            </div>
                        </div>
                    </section>

                    <section class="mt-10 grid gap-5 lg:grid-cols-2" aria-label="Información del producto">
                        <article class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-xl font-black text-slate-950">Descripción</h2><p class="mt-4 whitespace-pre-line leading-7 text-slate-600">{{ product.description || 'Este producto no tiene una descripción disponible.' }}</p></article>
                        <article class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-xl font-black text-slate-950">Especificaciones técnicas</h2><p class="mt-4 whitespace-pre-line leading-7 text-slate-600">{{ product.specifications || 'No se registraron especificaciones técnicas para este producto.' }}</p></article>
                    </section>
                </template>
            </div>
        </main>
    </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import Skeleton from 'primevue/skeleton';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/layouts/AppLayout.vue';
import api from '@/api';
import { useCartStore } from '@/stores/cart';
import { discountPercentage, formatPen, hasValidSale, productPrice, resolveProductImages } from '@/utils/productPresentation';

const route = useRoute();
const cartStore = useCartStore();
const toast = useToast();
const product = ref(null);
const loading = ref(true);
const error = ref('');
const notFound = ref(false);
const qty = ref(1);
const selectedImage = ref(null);
const failedImages = ref(new Set());
let controller;
let requestId = 0;

const catalogPath = computed(() => sessionStorage.getItem('catalog:last-path') || '/catalog');
const images = computed(() => resolveProductImages(product.value));
const stock = computed(() => Math.max(0, Number(product.value?.stock || 0)));
const currentInCart = computed(() => cartStore.items.find(item => item.product_id === product.value?.id)?.quantity || 0);
const remainingStock = computed(() => Math.max(0, stock.value - currentInCart.value));
const hasDiscount = computed(() => hasValidSale(product.value));
const currentPrice = computed(() => productPrice(product.value));
const discount = computed(() => discountPercentage(product.value));
const attributes = computed(() => [
    { label: 'Presentación', value: product.value?.presentation },
    { label: 'Viscosidad', value: product.value?.viscosity },
    { label: 'Tipo', value: product.value?.type },
    { label: 'Categoría', value: product.value?.category?.name },
].filter(item => item.value));
const whatsAppUrl = computed(() => {
    const phone = import.meta.env.VITE_WHATSAPP_PHONE || '51999999999';
    const parts = [`Hola, quisiera consultar por ${product.value?.name || 'este producto'}.`];
    if (product.value?.presentation) parts.push(`Presentación: ${product.value.presentation}.`);
    parts.push(`Enlace: ${window.location.href}`);
    return `https://wa.me/${phone}?text=${encodeURIComponent(parts.join(' '))}`;
});

const loadProduct = async () => {
    const slug = Array.isArray(route.params.slug) ? route.params.slug[0] : route.params.slug;
    controller?.abort();
    const id = ++requestId;
    product.value = null; error.value = ''; notFound.value = false; loading.value = true;
    if (typeof slug !== 'string' || !slug.trim() || slug === 'undefined') { notFound.value = true; loading.value = false; return; }
    controller = new AbortController();
    try {
        const response = await api.get(`/products/${encodeURIComponent(slug)}`, { signal: controller.signal });
        if (id !== requestId) return;
        product.value = response.data;
        const firstImage = resolveProductImages(response.data)[0] || null;
        selectedImage.value = firstImage; failedImages.value = new Set(); qty.value = 1;
    } catch (exception) {
        if (exception?.code === 'ERR_CANCELED' || id !== requestId) return;
        if (exception.response?.status === 404) notFound.value = true;
        else error.value = 'Ocurrió un problema al consultar el producto. Inténtalo nuevamente.';
    } finally { if (id === requestId) loading.value = false; }
};

const markImageFailed = url => { failedImages.value = new Set([...failedImages.value, url]); };
const addToCart = () => {
    if (!product.value || remainingStock.value <= 0) return;
    const amount = Math.min(qty.value, remainingStock.value);
    for (let index = 0; index < amount; index++) cartStore.addItem(product.value);
    toast.add({ severity: 'success', summary: 'Producto agregado', detail: `${amount} unidad(es) de “${product.value.name}” agregadas al carrito.`, life: 3000 });
    qty.value = 1;
};

watch(() => route.params.slug, loadProduct, { immediate: true });
watch(remainingStock, value => { if (value > 0 && qty.value > value) qty.value = value; });
onBeforeUnmount(() => controller?.abort());
</script>
