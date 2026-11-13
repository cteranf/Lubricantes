<template>
    <AppLayout>
        <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10" aria-live="polite">
            <section v-if="loading" class="rounded-2xl bg-white px-6 py-16 text-center shadow-sm" role="status">
                <i class="pi pi-spin pi-spinner text-3xl text-blue-700" aria-hidden="true"></i>
                <p class="mt-4 font-medium text-slate-600">Cargando información de seguimiento…</p>
            </section>

            <section v-else-if="error" class="rounded-2xl border border-amber-200 bg-white px-6 py-12 text-center shadow-sm" role="alert">
                <i class="pi pi-exclamation-circle text-4xl text-amber-600" aria-hidden="true"></i>
                <h1 class="mt-4 text-xl font-bold text-slate-900">No pudimos cargar el seguimiento</h1>
                <p class="mx-auto mt-2 max-w-lg text-slate-600">{{ error }}</p>
                <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <Button label="Reintentar" icon="pi pi-refresh" @click="fetchTracking" />
                    <router-link to="/orders" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-5 font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">
                        Volver a mis pedidos
                    </router-link>
                </div>
            </section>

            <div v-else-if="order" class="space-y-6 sm:space-y-8">
                <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-7">
                    <div>
                        <router-link to="/orders" class="inline-flex min-h-9 items-center gap-2 text-sm font-semibold text-blue-800 hover:text-blue-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">
                            <i class="pi pi-arrow-left" aria-hidden="true"></i> Mis pedidos
                        </router-link>
                        <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl">Seguimiento de pedido</h1>
                        <p class="mt-1 text-sm text-slate-600">Pedido {{ order.order_number }} <span aria-hidden="true">·</span> Realizado el {{ formatDateTime(order.created_at) }}</p>
                    </div>
                    <Tag :value="currentStatusLabel" :severity="currentStatusSeverity" class="self-start !px-3 !py-1.5 text-sm sm:self-center" />
                </header>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="timeline-title">
                    <div class="mb-6 flex items-start gap-3 border-b border-slate-100 pb-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-800"><i class="pi pi-map" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="timeline-title" class="text-lg font-bold text-slate-950">Estado del pedido</h2>
                            <p class="mt-1 text-sm text-slate-600">Consulta el avance y las fechas registradas.</p>
                        </div>
                    </div>
                    <ol class="tracking-timeline" aria-label="Etapas del pedido">
                        <li v-for="(step, index) in timeline" :key="`${step.status}-${index}`" class="tracking-step" :aria-current="step.active ? 'step' : undefined">
                            <span v-if="index < timeline.length - 1" class="tracking-connector" :class="connectorClass(index)" aria-hidden="true"></span>
                            <span class="tracking-step-icon" :class="stepCircleClass(step)">
                                <i :class="step.icon" aria-hidden="true"></i>
                            </span>
                            <span class="tracking-step-copy">
                                <span class="block text-sm font-bold" :class="step.status === 'canceled' ? 'text-rose-800' : (step.completed || step.active ? 'text-slate-950' : 'text-slate-500')">{{ step.label }}</span>
                                <time v-if="step.date" class="mt-1 block text-xs text-slate-600" :datetime="step.date">{{ formatDateTime(step.date) }}</time>
                                <span v-else class="sr-only">Sin fecha registrada</span>
                                <span v-if="step.active" class="sr-only">Etapa actual</span>
                                <span v-else-if="step.completed" class="sr-only">Etapa completada</span>
                            </span>
                        </li>
                    </ol>
                    <div v-if="order.estimated_delivery_date" class="mt-6 flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4">
                        <i class="pi pi-calendar mt-0.5 text-blue-800" aria-hidden="true"></i>
                        <div><p class="text-sm font-semibold text-blue-950">Fecha estimada de {{ order.delivery_type === 'delivery' ? 'entrega' : 'recojo' }}</p><p class="mt-0.5 text-sm text-blue-900">{{ formatCalendarDate(order.estimated_delivery_date) }}</p></div>
                    </div>
                </section>

                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                    <section class="space-y-4" aria-label="Información de pago">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-800"><i class="pi pi-wallet" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <h2 class="font-bold text-slate-950">Pago</h2>
                                    <p class="mt-1 text-sm text-slate-600">{{ paymentMethodLabel }}</p>
                                    <p class="mt-2 text-sm font-semibold" :class="paymentStatusClass">{{ paymentStatusLabel }}</p>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                                <span class="text-sm text-slate-600">{{ order.payment_status === 'approved' ? 'Total pagado' : 'Total del pedido' }}</span>
                                <span class="text-lg font-extrabold tabular-nums text-slate-950">{{ formatMoney(order.total) }}</span>
                            </div>
                        </div>
                        <CustomerTransferPaymentPanel v-if="order.payment_method === 'transferencia'" :key="order.id" :order="order" />
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="destination-title">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-800"><i :class="order.delivery_type === 'delivery' ? 'pi pi-truck' : 'pi pi-shopping-bag'" aria-hidden="true"></i></span>
                            <div>
                                <h2 id="destination-title" class="font-bold text-slate-950">{{ order.delivery_type === 'delivery' ? 'Entrega a domicilio' : 'Recojo en sede' }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ order.delivery_type === 'delivery' ? 'Destino y datos disponibles para este pedido.' : 'Información de la sede y del recojo.' }}</p>
                            </div>
                        </div>
                        <div v-if="order.delivery_type === 'delivery'" class="mt-4 space-y-2 text-sm text-slate-700">
                            <p v-if="delivery"><span class="font-semibold text-slate-900">Modalidad:</span> {{ deliveryMethodLabel(delivery.method) }}</p>
                            <p v-if="delivery"><span class="font-semibold text-slate-900">Estado de entrega:</span> {{ deliveryStatusLabel(delivery.status) }}</p>
                            <p v-if="order.shipping_info?.address"><span class="font-semibold text-slate-900">Dirección:</span> {{ order.shipping_info.address }}</p>
                            <p v-if="order.shipping_quote?.district || order.shipping_info?.district"><span class="font-semibold text-slate-900">Distrito:</span> {{ order.shipping_quote?.district || order.shipping_info?.district }}</p>
                            <p v-if="order.shipping_quote?.zone_name"><span class="font-semibold text-slate-900">Zona:</span> {{ order.shipping_quote.zone_name }}</p>
                            <p v-if="order.shipping_quote?.estimated_days_min != null"><span class="font-semibold text-slate-900">Plazo estimado:</span> {{ order.shipping_quote.estimated_days_min }}–{{ order.shipping_quote.estimated_days_max }} días</p>
                            <p v-if="order.shipping_info?.phone"><span class="font-semibold text-slate-900">Teléfono registrado:</span> {{ order.shipping_info.phone }}</p>
                            <p v-if="delivery?.scheduled_at"><span class="font-semibold text-slate-900">Entrega programada:</span> {{ formatDateTime(delivery.scheduled_at) }}</p>
                            <p v-if="delivery?.courier?.name"><span class="font-semibold text-slate-900">Courier:</span> {{ delivery.courier.name }}</p>
                            <p v-if="delivery?.courier?.tracking_number"><span class="font-semibold text-slate-900">Número de seguimiento:</span> {{ delivery.courier.tracking_number }}</p>
                            <a v-if="safeTrackingUrl" :href="safeTrackingUrl" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center gap-2 font-semibold text-blue-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">Abrir seguimiento del courier <i class="pi pi-external-link" aria-hidden="true"></i></a>
                            <p v-if="delivery?.status === 'failed_attempt'" class="rounded-lg bg-amber-50 p-3 font-medium text-amber-950">Hubo un inconveniente con el intento de entrega. La coordinación continuará por los canales registrados.</p>
                        </div>
                        <div v-else-if="order.pickup" class="mt-4 space-y-2 text-sm text-slate-700">
                            <p v-if="order.pickup.name" class="font-semibold text-slate-950">{{ order.pickup.name }}</p>
                            <p v-if="order.pickup.address"><span class="font-semibold text-slate-900">Dirección:</span> {{ order.pickup.address }}<template v-if="order.pickup.district"> — {{ order.pickup.district }}</template></p>
                            <p v-if="order.pickup.business_hours"><span class="font-semibold text-slate-900">Horario:</span> {{ order.pickup.business_hours }}</p>
                            <p v-if="order.pickup.instructions">{{ order.pickup.instructions }}</p>
                            <p v-if="order.pickup.ready_at"><span class="font-semibold text-slate-900">Listo para recoger:</span> {{ formatDateTime(order.pickup.ready_at) }}</p>
                            <p v-if="order.pickup.picked_up_at"><span class="font-semibold text-emerald-800">Recogido:</span> {{ formatDateTime(order.pickup.picked_up_at) }}</p>
                            <p v-else-if="order.pickup.deadline_at"><span class="font-semibold text-slate-900">Fecha límite de recojo:</span> {{ formatDateTime(order.pickup.deadline_at) }}</p>
                            <p v-if="order.pickup.deadline_status === 'expired'" class="rounded-lg bg-amber-50 p-3 font-medium text-amber-950">El plazo de recojo venció; el pedido requiere revisión.</p>
                            <p v-else-if="order.pickup.deadline_status === 'within_deadline'" class="rounded-lg bg-emerald-50 p-3 font-medium text-emerald-900">Tu pedido está listo para recoger en esta sede.</p>
                            <p v-else-if="order.pickup.deadline_status === 'picked_up'" class="rounded-lg bg-emerald-50 p-3 font-medium text-emerald-900">El pedido ya fue recogido.</p>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:col-span-2 sm:p-6" aria-labelledby="products-title">
                        <div class="flex items-start gap-3 border-b border-slate-100 pb-4">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700"><i class="pi pi-list" aria-hidden="true"></i></span>
                            <div><h2 id="products-title" class="font-bold text-slate-950">Resumen de productos</h2><p class="mt-1 text-sm text-slate-600">Cantidades y precios registrados en la compra.</p></div>
                        </div>
                        <ul class="divide-y divide-slate-100">
                            <li v-for="(item, index) in order.items" :key="item.id || index" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center">
                                <img v-if="item.image_url && !brokenImages[index]" :src="item.image_url" @error="brokenImages[index] = true" class="h-16 w-16 shrink-0 rounded-xl border border-slate-200 bg-white object-contain p-1" :alt="item.name">
                                <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400" role="img" :aria-label="`Sin imagen para ${item.name}`"><i class="pi pi-image text-xl" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-slate-950">{{ item.name }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ item.quantity }} × {{ formatMoney(item.price) }}</p>
                                    <div v-if="reviewAction(item)" class="mt-2">
                                        <Tag v-if="item.review_access.existing_review_status === 'pending'" value="Opinión pendiente de revisión" severity="info" />
                                        <Button v-else type="button" size="small" outlined icon="pi pi-star" :label="reviewAction(item)" class="!min-h-10 w-full sm:w-auto" :aria-label="`${reviewAction(item)}: ${item.name}`" @click="openProductReviews(item)" />
                                    </div>
                                </div>
                                <p class="font-bold tabular-nums text-slate-950 sm:ml-auto">{{ formatMoney(item.subtotal ?? (Number(item.quantity) * Number(item.price))) }}</p>
                            </li>
                        </ul>
                        <dl class="ml-auto max-w-sm space-y-2 border-t border-slate-100 pt-4 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-slate-600">Subtotal</dt><dd class="font-medium tabular-nums text-slate-900">{{ formatMoney(order.subtotal) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-600">Descuentos</dt><dd class="font-medium tabular-nums text-slate-900">− {{ formatMoney(order.discount_total) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-600">Envío</dt><dd class="font-medium tabular-nums text-slate-900">{{ formatMoney(order.shipping_amount) }}</dd></div>
                            <div class="flex justify-between gap-4 border-t border-slate-100 pt-3 text-base"><dt class="font-bold text-slate-950">{{ order.payment_status === 'approved' ? 'Total pagado' : 'Total del pedido' }}</dt><dd class="font-extrabold tabular-nums text-blue-900">{{ formatMoney(order.total) }}</dd></div>
                        </dl>
                    </section>
                </div>
            </div>
        </main>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import api from '@/api';
import { formatCalendarDate, formatDateTime } from '@/utils/dateTime';
import CustomerTransferPaymentPanel from '@/components/CustomerTransferPaymentPanel.vue';

const route = useRoute();
const router = useRouter();
const order = ref(null);
const timeline = ref([]);
const delivery = ref(null);
const loading = ref(true);
const error = ref(null);
const brokenImages = ref({});
let requestController = null;
let requestSequence = 0;

const safeTrackingUrl = computed(() => {
    try {
        const url = new URL(delivery.value?.courier?.tracking_url || '');
        return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
});
const activeStep = computed(() => timeline.value.find(step => step.active));
const currentStatusLabel = computed(() => activeStep.value?.label || 'Estado en actualización');
const currentStatusSeverity = computed(() => activeStep.value?.status === 'canceled' ? 'danger' : activeStep.value?.status === 'delivered' ? 'success' : 'info');
const paymentMethodLabel = computed(() => ({
    transferencia: 'Transferencia bancaria',
    card: 'Pago con tarjeta',
    contra_entrega: 'Pago contra entrega',
    pago_en_sede: 'Pago en sede',
}[order.value?.payment_method] || 'Método de pago registrado'));
const paymentStatusLabel = computed(() => {
    if (order.value?.payment_status === 'approved') return 'Pago confirmado';
    if (['contra_entrega', 'pago_en_sede'].includes(order.value?.payment_method) && order.value?.status === 'canceled') return 'Pedido cancelado antes del pago';
    if (['contra_entrega', 'pago_en_sede'].includes(order.value?.payment_method)) return 'Pago pendiente al recibir o recoger';
    return ({
        pending: 'Pendiente de confirmación',
        rejected: 'Pago no confirmado',
        refunded: 'Pago reembolsado',
        refund_pending: 'Reembolso en proceso',
    }[order.value?.payment_status] || 'Estado de pago en actualización');
});
const paymentStatusClass = computed(() => order.value?.payment_status === 'approved' ? 'text-emerald-800' : 'text-amber-800');

const reviewAction = item => {
    const access = item.review_access;
    if (!access?.enabled) return '';
    if (access.existing_review_status === 'pending') return 'Opinión pendiente de revisión';
    if (access.existing_review_status === 'published') return 'Ver o editar mi opinión';
    if (access.existing_review_status === 'rejected') return 'Revisar mi opinión';
    if (access.existing_review_status === 'hidden') return 'Editar mi opinión';
    return access.eligible ? 'Opinar sobre este producto' : '';
};

const openProductReviews = item => {
    const slug = item.review_access?.product_slug;
    if (typeof slug !== 'string' || !slug) return;
    return router.push({ path: `/product/${encodeURIComponent(slug)}`, query: { reviews: 'open' } });
};

const formatMoney = value => `S/ ${Number(value || 0).toFixed(2)}`;
const deliveryMethodLabel = value => ({ store_pickup: 'Recojo en tienda', own_delivery: 'Reparto propio', external_courier: 'Courier externo' }[value] || 'Entrega');
const deliveryStatusLabel = value => ({ pending: 'Pendiente', scheduled: 'Programado', assigned: 'Asignado', dispatched: 'Despachado', out_for_delivery: 'En camino', awaiting_pickup: 'Esperando recojo', failed_attempt: 'Intento no completado', rescheduled: 'Reprogramado', delivered: 'Entregado', canceled: 'Cancelado' }[value] || 'En actualización');

const stepCircleClass = step => {
    if (step.status === 'canceled') return 'bg-rose-700 text-white ring-4 ring-rose-100';
    if (step.completed) return 'bg-emerald-700 text-white';
    if (step.active) return 'bg-blue-800 text-white ring-4 ring-blue-100';
    return 'bg-slate-100 text-slate-500';
};

const connectorClass = index => {
    const current = timeline.value[index];
    const next = timeline.value[index + 1];
    if (next?.status === 'canceled') return 'bg-rose-400';
    return current?.completed ? 'bg-emerald-600' : 'bg-slate-200';
};

async function fetchTracking() {
    const id = route.params.id;
    if (id == null) return;

    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    const sequence = ++requestSequence;
    order.value = null;
    timeline.value = [];
    delivery.value = null;
    brokenImages.value = {};
    error.value = null;
    loading.value = true;

    try {
        const response = await api.get(`/orders/${encodeURIComponent(id)}/tracking`, { signal: controller.signal });
        if (sequence !== requestSequence || String(route.params.id) !== String(id)) return;
        order.value = response.data.order;
        timeline.value = response.data.timeline;
        delivery.value = response.data.delivery;
    } catch (exception) {
        if (exception?.name === 'AbortError' || exception?.code === 'ERR_CANCELED') return;
        if (sequence !== requestSequence) return;
        error.value = exception.response?.status === 404
            ? 'El pedido no existe o no pertenece a tu cuenta.'
            : 'No se pudo cargar la información de seguimiento.';
    } finally {
        if (sequence === requestSequence) {
            loading.value = false;
            if (requestController === controller) requestController = null;
        }
    }
}

watch(() => route.params.id, fetchTracking, { immediate: true });

onBeforeUnmount(() => {
    requestSequence += 1;
    requestController?.abort();
    requestController = null;
});
</script>

<style scoped>
.tracking-timeline {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.tracking-step {
    position: relative;
    display: flex;
    min-width: 0;
    align-items: flex-start;
    gap: 1rem;
}

.tracking-step-icon {
    z-index: 1;
    display: inline-flex;
    width: 2.75rem;
    height: 2.75rem;
    flex: 0 0 2.75rem;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
}

.tracking-step-copy {
    min-width: 0;
    padding-top: 0.25rem;
}

.tracking-connector {
    position: absolute;
    top: 2.75rem;
    bottom: -1.5rem;
    left: 1.34rem;
    width: 2px;
}

@media (min-width: 768px) {
    .tracking-timeline {
        flex-direction: row;
        align-items: flex-start;
        gap: 0;
    }

    .tracking-step {
        flex: 1 1 0;
        flex-direction: column;
        align-items: center;
        gap: 0.75rem;
        padding: 0 0.5rem;
        text-align: center;
    }

    .tracking-step-copy {
        padding-top: 0;
    }

    .tracking-connector {
        top: 1.35rem;
        right: 0;
        bottom: auto;
        left: calc(50% + 1.4rem);
        width: calc(100% - 2.8rem);
        height: 2px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        scroll-behavior: auto !important;
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
