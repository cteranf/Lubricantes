<template>
    <article class="group flex h-full min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl focus-within:border-blue-400">
        <router-link :to="productRoute" class="focus-ring relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-slate-100 sm:aspect-square" :aria-label="`Ver ${product.name}`">
            <img v-if="primaryImage && !imageFailed" :src="primaryImage" :alt="product.name" class="h-full w-full object-contain p-4 transition duration-300 group-hover:scale-105" loading="lazy" @error="imageFailed = true">
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
                <button type="button" class="flex min-h-11 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-blue-700 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600" :disabled="!hasStock" :aria-label="hasStock ? `Agregar ${product.name} al carrito` : `${product.name} sin stock`" @click="addToCart">
                    <i :class="hasStock ? 'pi pi-cart-plus' : 'pi pi-ban'" aria-hidden="true"></i>{{ hasStock ? 'Agregar' : 'No disponible' }}
                </button>
            </div>
        </div>
    </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useCartStore } from '@/stores/cart';
import { discountPercentage, formatPen, hasValidSale, productPrice, resolveProductImages } from '@/utils/productPresentation';

const props = defineProps({
    product: { type: Object, required: true },
    variant: { type: String, default: 'default' },
});
const cartStore = useCartStore();
const toast = useToast();
const imageFailed = ref(false);
const productRoute = computed(() => `/product/${encodeURIComponent(props.product.slug)}`);
const primaryImage = computed(() => resolveProductImages(props.product)[0] || null);
const stock = computed(() => Number(props.product.stock || 0));
const hasStock = computed(() => stock.value > 0);
const hasDiscount = computed(() => hasValidSale(props.product));
const currentPrice = computed(() => productPrice(props.product));
const discount = computed(() => discountPercentage(props.product));
const attributes = computed(() => [props.product.viscosity, props.product.type, props.product.presentation].filter(Boolean).slice(0, props.variant === 'catalog' ? 3 : 2));

watch(primaryImage, () => { imageFailed.value = false; });

const addToCart = () => {
    if (!hasStock.value) return;
    const currentQtyInCart = cartStore.items.find(item => item.product_id === props.product.id)?.quantity || 0;
    if (currentQtyInCart >= stock.value) {
        toast.add({ severity: 'warn', summary: 'Stock limitado', detail: `Ya tienes el máximo disponible de “${props.product.name}” en tu carrito.`, life: 3000 });
        return;
    }
    cartStore.addItem(props.product);
    toast.add({ severity: 'success', summary: 'Agregado al carrito', detail: `“${props.product.name}” se agregó correctamente.`, life: 2500 });
};
</script>
