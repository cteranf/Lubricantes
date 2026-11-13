<template>
    <article class="group flex h-full min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl focus-within:border-blue-400">
        <router-link :to="productRoute" class="focus-ring relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-slate-100 sm:aspect-square" :aria-label="`Ver ${product.name}`">
            <img v-if="primaryImage && !imageFailed" :src="primaryImage" :srcset="productImageSrcset(product, ['card', 'medium'])" :sizes="imageSizes" :width="imageDimensions?.width" :height="imageDimensions?.height" :alt="product.name" class="h-full w-full object-contain p-4 transition duration-300 group-hover:scale-105" loading="lazy" @error="imageFailed = true">
            <span v-else class="flex h-20 w-20 items-center justify-center rounded-2xl bg-white text-slate-400 shadow-sm" aria-hidden="true"><i class="pi pi-image text-3xl"></i></span>
            <span v-if="hasDiscount" class="absolute left-3 top-3 rounded-full bg-amber-400 px-2.5 py-1 text-xs font-black text-slate-950">-{{ discount }}%</span>
            <span v-if="!hasStock" class="absolute inset-x-3 bottom-3 rounded-lg bg-slate-950/85 px-3 py-2 text-center text-xs font-bold uppercase tracking-wide text-white">Agotado</span>
        </router-link>

        <div class="flex flex-1 flex-col p-4 sm:p-5">
            <p v-if="product.brand?.name" class="mb-2 text-xs font-bold uppercase tracking-wider text-blue-700">{{ product.brand.name }}</p>
            <h3 class="line-clamp-2 min-h-12 text-base font-bold leading-6 text-slate-900 sm:text-lg">
                <router-link :to="productRoute" class="rounded-sm hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-600">{{ product.name }}</router-link>
            </h3>
            <div v-if="attributes.length" class="mt-3 flex flex-wrap gap-1.5" aria-label="Características">
                <span v-for="attribute in attributes" :key="attribute" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ attribute }}</span>
            </div>
            <p class="mt-3 text-sm font-semibold" :class="hasStock ? 'text-emerald-700' : 'text-slate-500'">
                <i :class="hasStock ? 'pi pi-check-circle' : 'pi pi-times-circle'" class="mr-1" aria-hidden="true"></i>{{ hasStock ? 'Disponible' : 'Sin stock' }}
            </p>

            <div class="mt-auto pt-5">
                <div class="mb-4 flex min-h-12 flex-wrap items-baseline gap-x-2">
                    <span v-if="hasDiscount" class="text-sm text-slate-500 line-through">{{ formatPen(product.price) }}</span>
                    <span class="text-xl font-black tracking-tight text-slate-950 sm:text-2xl">{{ formatPen(currentPrice) }}</span>
                </div>
                <button type="button" class="flex min-h-11 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-blue-700 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600" :disabled="!hasStock || adding || justAdded" :aria-label="buttonLabel" @click="addToCart($event)">
                    <i :class="buttonIcon" aria-hidden="true"></i>{{ buttonText }}
                </button>
            </div>
        </div>
    </article>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useCartStore } from '@/stores/cart';
import { discountPercentage, formatPen, hasValidSale, productImageDimensions, productImageSrcset, productImageVariants, productPrice, resolveProductImages } from '@/utils/productPresentation';

const props = defineProps({
    product: { type: Object, required: true },
    variant: { type: String, default: 'default' },
});
const cartStore = useCartStore();
const toast = useToast();
const imageFailed = ref(false);
const adding = ref(false);
const justAdded = ref(false);
let addedTimer;
const productRoute = computed(() => `/product/${encodeURIComponent(props.product.slug)}`);
const preferredImage = computed(() => productImageVariants(props.product).find(image => image.key === 'card')
    || productImageVariants(props.product).find(image => image.key === 'medium')
    || productImageVariants(props.product).find(image => image.key === 'detail_source')
    || productImageVariants(props.product).find(image => image.key === 'detail'));
const primaryImage = computed(() => preferredImage.value?.url || resolveProductImages(props.product)[0] || null);
const imageDimensions = computed(() => preferredImage.value || productImageDimensions(props.product));
const imageSizes = computed(() => props.variant === 'catalog'
    ? '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw'
    : '(max-width: 640px) 85vw, 360px');
const stock = computed(() => Number(props.product.stock || 0));
const hasStock = computed(() => stock.value > 0);
const hasDiscount = computed(() => hasValidSale(props.product));
const currentPrice = computed(() => productPrice(props.product));
const discount = computed(() => discountPercentage(props.product));
const attributes = computed(() => [props.product.viscosity, props.product.type, props.product.presentation].filter(Boolean).slice(0, props.variant === 'catalog' ? 3 : 2));
const buttonText = computed(() => !hasStock.value ? 'Sin stock' : adding.value ? 'Agregando…' : justAdded.value ? 'Agregado' : 'Agregar al carrito');
const buttonIcon = computed(() => !hasStock.value ? 'pi pi-ban' : adding.value ? 'pi pi-spin pi-spinner' : justAdded.value ? 'pi pi-check' : 'pi pi-cart-plus');
const buttonLabel = computed(() => !hasStock.value ? `${props.product.name} sin stock` : `${buttonText.value}: ${props.product.name}`);

watch(primaryImage, () => { imageFailed.value = false; });

const addToCart = async event => {
    if (!hasStock.value || adding.value || justAdded.value) return;
    const currentQtyInCart = cartStore.items.find(item => item.product_id === props.product.id)?.quantity || 0;
    if (currentQtyInCart >= stock.value) {
        toast.add({ severity: 'warn', summary: 'Stock limitado', detail: `Ya tienes el máximo disponible de “${props.product.name}” en tu carrito.`, life: 3000 });
        return;
    }
    const trigger = event?.currentTarget || null;
    adding.value = true;
    try {
        await nextTick();
        const added = cartStore.addItem(props.product, 1, trigger);
        if (!added) {
            toast.add({ severity: 'warn', summary: 'Stock limitado', detail: 'No se pudo agregar otra unidad disponible.', life: 3000 });
            return;
        }
        justAdded.value = true;
        clearTimeout(addedTimer);
        addedTimer = setTimeout(() => { justAdded.value = false; }, 1200);
    } catch {
        toast.add({ severity: 'error', summary: 'No se pudo agregar', detail: 'No se pudo actualizar el carrito. Inténtalo nuevamente.', life: 3500 });
    } finally {
        adding.value = false;
    }
};

onBeforeUnmount(() => clearTimeout(addedTimer));
</script>
