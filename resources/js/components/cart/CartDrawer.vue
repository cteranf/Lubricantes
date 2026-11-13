<template>
    <Drawer
        :visible="cartStore.drawerOpen"
        position="right"
        :modal="true"
        :block-scroll="true"
        :close-on-escape="true"
        :dismissable="true"
        :show-close-icon="false"
        :pt="drawerPassThrough"
        id="cart-drawer"
        aria-labelledby="cart-drawer-title"
        @update:visible="handleVisibilityChange"
        @after-hide="cartStore.restoreDrawerFocus()"
    >
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700" aria-hidden="true"><i class="pi pi-shopping-cart text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <h2 id="cart-drawer-title" class="text-lg font-black text-slate-950">Tu carrito</h2>
                    <p class="text-sm text-slate-600">{{ cartStore.count }} {{ cartStore.count === 1 ? 'unidad' : 'unidades' }} · {{ cartStore.items.length }} {{ cartStore.items.length === 1 ? 'producto' : 'productos' }}</p>
                </div>
                <button ref="closeButton" type="button" autofocus class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" aria-label="Cerrar carrito" @click="cartStore.closeDrawer()"><i class="pi pi-times text-lg" aria-hidden="true"></i></button>
            </div>
        </template>

        <div class="flex min-h-0 flex-1 flex-col">
            <p v-if="cartStore.drawerNotice" class="mx-5 mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 sm:mx-6" role="status" aria-live="polite">{{ cartStore.drawerNotice.message }}</p>

            <div v-if="cartStore.items.length" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6" aria-label="Productos en el carrito">
                <ul class="divide-y divide-slate-200">
                    <li v-for="item in cartStore.items" :key="item.product_id" class="flex gap-3 py-4 first:pt-1">
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200">
                            <img v-if="item.image && !failedImages.has(item.product_id)" :src="item.image" :srcset="item.image_srcset" :sizes="item.image_sizes || '160px'" :width="item.image_dimensions?.width" :height="item.image_dimensions?.height" :alt="item.name" loading="lazy" class="h-full w-full object-contain p-1" @error="markImageFailed(item.product_id)">
                            <i v-else class="pi pi-image text-2xl text-slate-400" aria-hidden="true"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p v-if="item.product?.brand?.name" class="text-xs font-bold uppercase tracking-wide text-blue-700">{{ item.product.brand.name }}</p>
                            <p class="line-clamp-2 font-bold leading-5 text-slate-900">{{ item.name }}</p>
                            <p v-if="item.product?.presentation" class="mt-1 text-xs text-slate-500">{{ item.product.presentation }}</p>
                            <p class="mt-2 text-sm font-semibold text-slate-700">{{ formatPen(item.price) }} <span class="font-normal text-slate-500">cada uno</span></p>
                            <p class="mt-1 text-sm font-black text-slate-950">Subtotal: {{ formatPen(lineSubtotal(item)) }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <div class="inline-flex items-center rounded-lg border border-slate-300" :aria-label="`Cantidad de ${item.name}`">
                                    <button type="button" class="h-11 w-11 rounded-l-lg text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="isLineBusy(item) || item.quantity <= 1" :aria-label="`Disminuir cantidad de ${item.name}`" @click="changeQuantity(item, item.quantity - 1)"><i class="pi pi-minus" aria-hidden="true"></i></button>
                                    <output class="min-w-9 text-center text-sm font-bold" :aria-label="`Cantidad: ${item.quantity}`">{{ item.quantity }}</output>
                                    <button type="button" class="h-11 w-11 rounded-r-lg text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="isLineBusy(item) || item.quantity >= cartQuantityLimit(item)" :aria-label="`Aumentar cantidad de ${item.name}`" @click="changeQuantity(item, item.quantity + 1)"><i class="pi pi-plus" aria-hidden="true"></i></button>
                                </div>
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="isLineBusy(item)" :aria-label="`Quitar ${item.name} del carrito`" @click="removeItem(item)"><i class="pi pi-trash" aria-hidden="true"></i><span>Quitar</span></button>
                                <span v-if="isLineBusy(item)" class="sr-only" role="status">Actualizando {{ item.name }}…</span>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <div v-else class="flex min-h-0 flex-1 flex-col items-center justify-center px-7 py-10 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-slate-400" aria-hidden="true"><i class="pi pi-shopping-cart text-3xl"></i></span>
                <h3 class="mt-5 text-xl font-black text-slate-950">Tu carrito está vacío</h3>
                <p class="mt-2 max-w-xs text-sm leading-6 text-slate-600">Explora el catálogo y agrega los productos que necesitas.</p>
                <button type="button" class="mt-6 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="goToCatalog"><i class="pi pi-search" aria-hidden="true"></i>Explorar productos</button>
            </div>

            <footer v-if="cartStore.items.length" class="shrink-0 border-t border-slate-200 bg-white px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4 sm:px-6">
                <div class="mb-4 flex items-center justify-between gap-3"><span class="text-sm font-semibold text-slate-600">Subtotal</span><strong class="text-xl font-black text-slate-950">{{ formatPen(cartStore.total) }}</strong></div>
                <p class="mb-4 text-xs leading-5 text-slate-500">El costo de envío se calcula durante el checkout.</p>
                <button type="button" class="min-h-12 w-full rounded-xl bg-blue-700 px-4 py-3 text-sm font-black text-white transition hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="goToCart">Ver carrito</button>
                <button type="button" class="mt-2 min-h-11 w-full rounded-xl px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="cartStore.closeDrawer()">Seguir comprando</button>
            </footer>
        </div>
    </Drawer>
</template>

<script setup>
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Drawer from 'primevue/drawer';
import { useToast } from 'primevue/usetoast';
import { useCartStore } from '@/stores/cart';
import { cartQuantityLimit } from '@/utils/cartQuantity';
import { formatPen } from '@/utils/productPresentation';

const cartStore = useCartStore();
const router = useRouter();
const toast = useToast();
const closeButton = ref(null);
const failedImages = reactive(new Set());
let noticeTimer;
const drawerPassThrough = {
    root: { class: 'w-full max-w-full sm:!w-[27rem] sm:max-w-[27rem]' },
    content: { class: '!flex min-h-0 flex-1 flex-col !overflow-hidden !p-0' },
};

const lineSubtotal = item => Number(item.price || 0) * Number(item.quantity || 0);
const isLineBusy = item => Boolean(cartStore.lineOperations[item.product_id]);
const markImageFailed = productId => failedImages.add(productId);

function changeQuantity(item, quantity) {
    if (isLineBusy(item)) return;
    try {
        if (!cartStore.updateQuantity(item.product_id, quantity)) throw new Error('cart-update-failed');
    } catch {
        toast.add({ severity: 'error', summary: 'No se pudo actualizar', detail: 'No se pudo actualizar el carrito. Inténtalo nuevamente.', life: 3500 });
    }
}

function removeItem(item) {
    if (isLineBusy(item)) return;
    try {
        if (!cartStore.removeItem(item.product_id)) throw new Error('cart-remove-failed');
        failedImages.delete(item.product_id);
    } catch {
        toast.add({ severity: 'error', summary: 'No se pudo quitar', detail: 'No se pudo actualizar el carrito. Inténtalo nuevamente.', life: 3500 });
    }
}

function goToCart() {
    cartStore.closeDrawer({ restoreFocus: false });
    router.push('/cart');
}

function goToCatalog() {
    cartStore.closeDrawer({ restoreFocus: false });
    router.push('/catalog');
}

function handleVisibilityChange(visible) {
    if (!visible) cartStore.closeDrawer();
}

watch(() => cartStore.drawerNotice?.id, id => {
    clearTimeout(noticeTimer);
    if (!id) return;
    noticeTimer = setTimeout(() => {
        if (cartStore.drawerNotice?.id === id) cartStore.drawerNotice = null;
    }, 3500);
});

watch(() => cartStore.drawerOpen, open => {
    if (!open) clearTimeout(noticeTimer);
});

onBeforeUnmount(() => clearTimeout(noticeTimer));
</script>
