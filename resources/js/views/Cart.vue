<template>
    <AppLayout>
        <section class="site-container py-7 sm:py-10" aria-labelledby="cart-page-title">
            <header class="mb-7 flex flex-wrap items-end justify-between gap-3 sm:mb-9">
                <div>
                    <p class="eyebrow">LubriStore</p>
                    <h1 id="cart-page-title" class="text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Tu carrito</h1>
                    <p class="mt-2 text-sm text-slate-600" aria-live="polite">
                        {{ cartStore.count }} {{ cartStore.count === 1 ? 'unidad' : 'unidades' }}
                        <span aria-hidden="true">·</span>
                        {{ cartStore.items.length }} {{ cartStore.items.length === 1 ? 'producto' : 'productos' }}
                    </p>
                </div>
                <router-link to="/catalog" class="hidden min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-bold text-blue-800 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 sm:inline-flex">
                    <i class="pi pi-arrow-left" aria-hidden="true"></i> Seguir comprando
                </router-link>
            </header>

            <div v-if="cartStore.items.length" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.65fr)_minmax(18rem,0.85fr)] lg:gap-8">
                <section class="min-w-0" aria-labelledby="cart-items-heading" aria-live="polite">
                    <h2 id="cart-items-heading" class="sr-only">Productos de tu carrito</h2>
                    <p class="sr-only" role="status">{{ announcement }}</p>
                    <ul class="space-y-3 sm:space-y-4">
                        <li v-for="item in cartStore.items" :key="item.product_id" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                            <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                                <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200 sm:h-24 sm:w-24">
                                    <img v-if="item.image && !failedImages.has(item.product_id)" :src="item.image" :srcset="item.image_srcset" :sizes="item.image_sizes || '160px'" :width="item.image_dimensions?.width" :height="item.image_dimensions?.height" :alt="item.name" loading="lazy" class="h-full w-full object-contain p-1" @error="markImageFailed(item.product_id)">
                                    <span v-else class="flex h-full w-full items-center justify-center text-slate-400" role="img" :aria-label="`Imagen no disponible para ${item.name}`"><i class="pi pi-image text-2xl" aria-hidden="true"></i></span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-start gap-2">
                                        <div class="min-w-0 flex-1">
                                            <p v-if="item.product?.brand?.name" class="mb-1 text-[11px] font-black uppercase tracking-[0.14em] text-blue-700">{{ item.product.brand.name }}</p>
                                            <h3 class="break-words text-base font-black leading-5 text-slate-950 sm:text-lg">
                                                <router-link v-if="productPath(item)" :to="productPath(item)" class="rounded-sm underline-offset-4 hover:text-blue-800 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">{{ item.name }}</router-link>
                                                <span v-else>{{ item.name }}</span>
                                            </h3>
                                            <p v-if="commercialDetails(item).length" class="mt-1 break-words text-xs leading-5 text-slate-600">{{ commercialDetails(item).join(' · ') }}</p>
                                        </div>
                                        <button type="button" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-slate-500 hover:bg-red-50 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-40 sm:px-3 sm:text-sm" :disabled="isLineBusy(item)" :aria-label="`Quitar ${item.name} del carrito`" @click="removeItem(item)">
                                            <i class="pi pi-trash" aria-hidden="true"></i><span class="hidden sm:inline">Quitar</span>
                                        </button>
                                    </div>

                                    <div class="mt-4 grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end sm:gap-5">
                                        <div class="min-w-0">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Precio unitario</p>
                                            <p class="mt-1 text-sm font-bold text-slate-800">{{ formatPen(item.price) }}</p>
                                        </div>
                                        <div class="col-span-2 sm:col-span-1">
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500" :for="`cart-quantity-${item.product_id}`">Cantidad</label>
                                            <div class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white" :aria-label="`Controles de cantidad para ${item.name}`">
                                                <button type="button" class="h-11 w-11 rounded-l-lg text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="isLineBusy(item) || item.quantity <= 1 || unavailable(item)" :aria-label="`Disminuir cantidad de ${item.name}`" @click="decrementQuantity(item)"><i class="pi pi-minus" aria-hidden="true"></i></button>
                                                <input :id="`cart-quantity-${item.product_id}`" type="number" inputmode="numeric" min="1" step="1" :max="cartQuantityLimit(item)" :value="item.quantity" :disabled="isLineBusy(item) || unavailable(item)" class="h-11 w-14 border-x border-slate-200 bg-transparent text-center text-sm font-bold text-slate-950 outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-700 disabled:bg-slate-50 disabled:text-slate-500" :aria-label="`Cantidad de ${item.name}`" @input="validateQuantity(item, $event)">
                                                <button type="button" class="h-11 w-11 rounded-r-lg text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="isLineBusy(item) || item.quantity >= cartQuantityLimit(item) || unavailable(item)" :aria-label="`Aumentar cantidad de ${item.name}`" @click="incrementQuantity(item)"><i class="pi pi-plus" aria-hidden="true"></i></button>
                                            </div>
                                        </div>
                                        <div class="text-right sm:min-w-28">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Subtotal</p>
                                            <p class="mt-1 text-base font-black text-slate-950">{{ formatPen(lineSubtotal(item)) }}</p>
                                        </div>
                                    </div>

                                    <p v-if="hasStockSnapshot(item)" class="mt-3 flex items-start gap-2 text-xs leading-5" :class="unavailable(item) ? 'text-amber-800' : 'text-slate-500'">
                                        <i :class="unavailable(item) ? 'pi pi-exclamation-triangle' : 'pi pi-info-circle'" class="mt-0.5 shrink-0" aria-hidden="true"></i>
                                        <span>{{ unavailable(item) ? 'Sin disponibilidad reportada en este momento.' : `Disponibilidad registrada: ${item.product.stock} unidades.` }} Se confirmará nuevamente durante el checkout.</span>
                                    </p>
                                    <p v-if="isLineBusy(item)" class="mt-2 text-xs text-slate-500" role="status" aria-live="polite">Actualizando {{ item.name }}…</p>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>

                <aside class="min-w-0 lg:sticky lg:top-24" aria-labelledby="cart-summary-heading">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <h2 id="cart-summary-heading" class="text-xl font-black text-slate-950">Resumen del pedido</h2>
                        <dl class="mt-5 space-y-4">
                            <div class="flex items-start justify-between gap-4 text-sm">
                                <dt class="text-slate-600">Productos</dt>
                                <dd class="text-right font-semibold text-slate-900">{{ cartStore.count }} {{ cartStore.count === 1 ? 'unidad' : 'unidades' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4 border-t border-slate-100 pt-4 text-sm">
                                <dt class="text-slate-600">Subtotal actual</dt>
                                <dd class="text-right text-lg font-black text-slate-950">{{ formatPen(cartStore.total) }}</dd>
                            </div>
                        </dl>
                        <div class="mt-5 rounded-xl bg-slate-50 p-4">
                            <p class="flex items-start gap-2 text-sm font-semibold text-slate-800"><i class="pi pi-truck mt-0.5 text-blue-700" aria-hidden="true"></i><span>Envío o recojo se define durante el checkout.</span></p>
                            <p class="mt-2 text-xs leading-5 text-slate-600">El servidor confirmará los precios, la disponibilidad y los cargos definitivos antes de crear el pedido.</p>
                        </div>
                        <router-link to="/checkout" class="btn-primary mt-5 w-full" aria-label="Continuar al checkout">Continuar al checkout <i class="pi pi-arrow-right" aria-hidden="true"></i></router-link>
                        <router-link to="/catalog" class="mt-2 inline-flex min-h-11 w-full items-center justify-center rounded-xl px-4 py-2 text-sm font-bold text-blue-800 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Seguir comprando</router-link>
                    </div>
                </aside>
            </div>

            <section v-else class="mx-auto flex max-w-xl flex-col items-center rounded-2xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm sm:px-10 sm:py-16" aria-labelledby="cart-empty-heading">
                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-700" aria-hidden="true"><i class="pi pi-shopping-cart text-3xl"></i></span>
                <h2 id="cart-empty-heading" class="mt-5 text-2xl font-black text-slate-950">Tu carrito está vacío</h2>
                <p class="mt-2 max-w-sm text-sm leading-6 text-slate-600">Explora nuestros productos y agrega lo que necesitas. Aquí aparecerán para revisar cantidades y subtotal.</p>
                <router-link to="/catalog" class="btn-primary mt-6">Explorar productos <i class="pi pi-arrow-right" aria-hidden="true"></i></router-link>
            </section>
        </section>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useCartStore } from '@/stores/cart';
import { cartQuantityLimit, normalizeCartQuantity } from '@/utils/cartQuantity';
import { formatPen } from '@/utils/productPresentation';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/layouts/AppLayout.vue';

const cartStore = useCartStore();
const toast = useToast();
const failedImages = ref(new Set());
const announcement = ref('');

const lineSubtotal = item => Number(item.price || 0) * Number(item.quantity || 0);
const productPath = item => item.product?.slug ? `/product/${encodeURIComponent(item.product.slug)}` : null;
const hasStockSnapshot = item => item.product?.stock !== null && item.product?.stock !== undefined && item.product?.stock !== '' && Number.isFinite(Number(item.product.stock));
const unavailable = item => hasStockSnapshot(item) && cartQuantityLimit(item) < 1;
const isLineBusy = item => Boolean(cartStore.lineOperations[item.product_id] || cartStore.addingProducts[item.product_id]);
const markImageFailed = productId => { failedImages.value = new Set([...failedImages.value, productId]); };
const commercialDetails = item => [item.product?.presentation, item.product?.viscosity, item.product?.type]
    .filter(value => typeof value === 'string' && value.trim())
    .filter((value, index, values) => values.findIndex(candidate => candidate.trim().toLocaleLowerCase() === value.trim().toLocaleLowerCase()) === index)
    .slice(0, 3);

function updateQuantity(item, rawValue) {
    if (isLineBusy(item) || unavailable(item)) return;

    const input = rawValue?.currentTarget || null;
    const value = input ? input.value : rawValue;
    const { quantity, reason, limit } = normalizeCartQuantity(value, item);
    if (reason === 'minimum') {
        toast.add({ severity: 'warn', summary: 'Cantidad mínima', detail: 'La cantidad mínima es 1 unidad.', life: 2500 });
    } else if (reason === 'stock') {
        toast.add({ severity: 'warn', summary: 'Cantidad ajustada', detail: `La cantidad se ajustó al límite registrado de ${limit} unidades. El stock se confirmará en checkout.`, life: 3500 });
    } else if (reason === 'invalid' || reason === 'decimal') {
        toast.add({ severity: 'warn', summary: 'Cantidad no válida', detail: reason === 'decimal' ? 'Ingresa un número entero de unidades.' : 'Ingresa una cantidad numérica válida.', life: 2500 });
    }

    if (input) input.value = String(quantity);
    if (item.quantity === quantity) return;

    try {
        if (!cartStore.updateQuantity(item.product_id, quantity)) return;
        announcement.value = `Cantidad de ${item.name} actualizada.`;
    } catch {
        if (input) input.value = String(item.quantity);
        toast.add({ severity: 'error', summary: 'No se pudo actualizar', detail: 'No se pudo actualizar el carrito. Inténtalo nuevamente.', life: 3500 });
    }
}

const validateQuantity = (item, valueOrEvent) => updateQuantity(item, valueOrEvent);

function incrementQuantity(item) {
    if (item.quantity >= cartQuantityLimit(item)) {
        toast.add({ severity: 'warn', summary: 'Límite alcanzado', detail: 'La disponibilidad registrada no permite añadir más unidades. Se confirmará en checkout.', life: 3500 });
        return;
    }
    updateQuantity(item, item.quantity + 1);
}

function decrementQuantity(item) {
    if (item.quantity > 1) updateQuantity(item, item.quantity - 1);
}

function removeItem(item) {
    if (isLineBusy(item)) return;
    try {
        if (!cartStore.removeItem(item.product_id)) return;
        failedImages.value.delete(item.product_id);
        announcement.value = `${item.name} se quitó del carrito.`;
        toast.add({ severity: 'success', summary: 'Producto eliminado', detail: 'Se quitó el producto del carrito.', life: 2500 });
    } catch {
        toast.add({ severity: 'error', summary: 'No se pudo quitar', detail: 'No se pudo actualizar el carrito. Inténtalo nuevamente.', life: 3500 });
    }
}
</script>
