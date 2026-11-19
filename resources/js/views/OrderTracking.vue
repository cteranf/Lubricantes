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

                <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2 lg:gap-6">
                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="payment-title" aria-live="polite">
                        <div class="p-5 sm:p-6">
                            <div class="flex items-start gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-800"><i class="pi pi-wallet" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <h2 id="payment-title" class="text-lg font-bold text-slate-950">Información del pago</h2>
                                    <p class="mt-1 text-sm text-slate-600">{{ paymentMethodLabel }}</p>
                                    <Tag :value="paymentStatusLabel" :severity="paymentStatusSeverity" class="mt-2" />
                                </div>
                            </div>
                            <div class="mt-5 flex items-center justify-between gap-4 rounded-xl bg-slate-50 px-4 py-3">
                                <span class="text-sm font-medium text-slate-600">{{ amountLabel }}</span>
                                <span class="text-xl font-extrabold tabular-nums text-slate-950">{{ formatMoney(order.total) }}</span>
                            </div>
                            <p v-if="order.payment_status === 'approved' && order.paid_at" class="mt-3 text-sm text-slate-600">Confirmado el <time :datetime="order.paid_at">{{ formatDateTime(order.paid_at) }}</time></p>
                            <p v-if="paymentProgressMessage" class="mt-3 text-sm text-slate-700">{{ paymentProgressMessage }}</p>
                        </div>
                        <CustomerTransferPaymentPanel v-if="order.payment_method === 'transferencia' && (order.reservation_status !== 'expired' || ['pending_review', 'observed'].includes(order.payment_submission_status))" :key="order.id" :order="order" :show-expected-amount="false" />
                        <div v-if="canRepurchase" class="border-t border-slate-100 p-5 sm:p-6">
                            <Button label="Volver a comprar" icon="pi pi-shopping-cart" :loading="repurchaseLoading" :disabled="repurchaseLoading" @click="loadRepurchaseQuote" />
                            <p class="mt-2 text-sm text-slate-600">Revisa la disponibilidad y los precios actuales antes de iniciar un nuevo checkout. El pedido original y su historial se conservan.</p>
                            <p v-if="repurchaseError" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900" role="alert">{{ repurchaseError }}</p>
                            <div v-if="repurchaseQuote" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-live="polite">
                                <h3 class="font-bold text-slate-950">Revisa los productos y precios vigentes</h3>
                                <ul class="mt-3 divide-y divide-slate-200">
                                    <li v-for="(line, index) in repurchaseQuote.items" :key="`${line.product_id || 'unavailable'}-${index}`" class="py-3 text-sm">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-semibold text-slate-900">{{ line.name }}</p>
                                                <p class="mt-1 text-slate-600">{{ line.quantity }} {{ line.quantity === 1 ? 'unidad' : 'unidades' }} · {{ line.price !== null ? formatMoney(line.price) : 'Sin precio vigente' }} por unidad</p>
                                                <p v-if="line.product_available && line.available_quantity < (line.quantity + cartQuantity(line.product_id))" class="mt-1 font-medium text-amber-800" role="status">Disponibles: {{ line.available_quantity }}. En el carrito quedarán {{ line.quantity + cartQuantity(line.product_id) }} para que ajustes la cantidad antes de continuar.</p>
                                                <p v-else-if="!line.product_available" class="mt-1 font-medium text-rose-800" role="status">Este producto ya no está disponible y no se agregará.</p>
                                                <p v-if="line.product_available && line.price !== null && line.price < line.regular_price" class="mt-1 text-emerald-800">Oferta vigente: {{ formatMoney(line.regular_price) }} → {{ formatMoney(line.price) }}</p>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                                <p v-if="repurchaseHasUnavailable" class="mt-3 text-sm text-amber-900">Los productos no disponibles se omitirán. Revisa el carrito; el stock y el precio volverán a validarse antes de confirmar.</p>
                                <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                                    <Button label="Cancelar" severity="secondary" outlined @click="repurchaseQuote = null" />
                                    <Button :label="repurchaseAdded ? 'Productos agregados' : 'Agregar disponibles y revisar carrito'" icon="pi pi-arrow-right" :loading="repurchaseAdding" :disabled="!repurchaseHasAvailable || repurchaseAdding || repurchaseAdded" @click="addRepurchaseItems" />
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="receipt-request-title">
                        <div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-800"><i class="pi pi-file" aria-hidden="true"></i></span><div><h2 id="receipt-request-title" class="font-bold text-slate-950">Comprobante solicitado</h2><p class="text-sm text-slate-600">Datos declarados para este pedido</p></div></div>
                        <p v-if="!order.receipt_request" class="mt-4 text-sm text-slate-600">Datos de comprobante no registrados.</p>
                        <dl v-else class="mt-4 grid gap-2 text-sm sm:grid-cols-2"><div><dt class="font-semibold text-slate-700">Tipo</dt><dd>{{ order.receipt_request.type === 'factura' ? 'Factura' : 'Boleta de venta' }}</dd></div><div><dt class="font-semibold text-slate-700">Estado</dt><dd>Solicitado; aún no emitido</dd></div><div v-if="order.receipt_request.recipient_name"><dt class="font-semibold text-slate-700">Receptor</dt><dd>{{ order.receipt_request.recipient_name }}</dd></div><div v-if="order.receipt_request.identity_number"><dt class="font-semibold text-slate-700">Documento</dt><dd>{{ order.receipt_request.identity_type }} · {{ order.receipt_request.identity_number }}</dd></div><div v-if="order.receipt_request.billing_address" class="sm:col-span-2"><dt class="font-semibold text-slate-700">Dirección de facturación</dt><dd>{{ order.receipt_request.billing_address }}</dd></div><div v-if="order.receipt_request.email" class="sm:col-span-2"><dt class="font-semibold text-slate-700">Correo</dt><dd>{{ order.receipt_request.email }}</dd></div></dl>
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
                                    <p class="mt-1 flex flex-wrap items-baseline gap-x-2 text-sm text-slate-600">
                                        <span v-if="itemPriceBreakdown(item).hasDiscount" class="text-xs text-slate-500 line-through">{{ formatMoney(itemPriceBreakdown(item).regularPrice) }}</span>
                                        <span>{{ item.quantity }} × {{ formatMoney(itemPriceBreakdown(item).appliedPrice) }}</span>
                                    </p>
                                    <p v-if="itemPriceBreakdown(item).hasDiscount" class="mt-1 text-xs font-semibold text-emerald-700">Ahorras {{ formatMoney(itemPriceBreakdown(item).unitSavings) }} por unidad</p>
                                    <div v-if="reviewAction(item)" class="mt-2">
                                        <Tag v-if="item.review_access.existing_review_status === 'pending'" value="Opinión pendiente de revisión" severity="info" />
                                        <Button v-else type="button" size="small" outlined icon="pi pi-star" :label="reviewAction(item)" class="!min-h-10 w-full sm:w-auto" :aria-label="`${reviewAction(item)}: ${item.name}`" @click="openProductReviews(item)" />
                                    </div>
                                </div>
                                <p class="font-bold tabular-nums text-slate-950 sm:ml-auto">{{ formatMoney(item.subtotal ?? (Number(item.quantity) * Number(item.price))) }}</p>
                            </li>
                        </ul>
                        <dl class="ml-auto max-w-sm space-y-2 border-t border-slate-100 pt-4 text-sm">
                            <div v-if="orderPriceSummary.hasSavings" class="flex justify-between gap-4"><dt class="text-slate-600">Subtotal sin ofertas</dt><dd class="font-medium tabular-nums text-slate-900">{{ formatMoney(orderPriceSummary.regularSubtotal) }}</dd></div>
                            <div v-if="orderPriceSummary.hasSavings" class="flex justify-between gap-4 text-emerald-700"><dt>Ahorro en ofertas</dt><dd class="font-semibold tabular-nums">− {{ formatMoney(orderPriceSummary.savings) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-600">Subtotal de productos</dt><dd class="font-medium tabular-nums text-slate-900">{{ formatMoney(order.subtotal ?? orderPriceSummary.productSubtotal) }}</dd></div>
                            <div v-if="Number(order.discount_total) > 0" class="flex justify-between gap-4"><dt class="text-slate-600">Descuentos comerciales</dt><dd class="font-medium tabular-nums text-slate-900">− {{ formatMoney(order.discount_total) }}</dd></div>
                            <div v-if="Number(order.shipping_amount) > 0" class="flex justify-between gap-4"><dt class="text-slate-600">Costo de envío</dt><dd class="font-medium tabular-nums text-slate-900">{{ formatMoney(order.shipping_amount) }}</dd></div>
                            <div class="flex justify-between gap-4 border-t border-slate-100 pt-3 text-base"><dt class="font-bold text-slate-950">{{ order.payment_status === 'approved' ? 'Total pagado' : 'Total a pagar' }}</dt><dd class="font-extrabold tabular-nums text-blue-900">{{ formatMoney(order.total) }}</dd></div>
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
import { cartItemPriceBreakdown, summarizeOrderPriceSnapshots } from '@/utils/cartPricing.mjs';
import CustomerTransferPaymentPanel from '@/components/CustomerTransferPaymentPanel.vue';
import { useCartStore } from '@/stores/cart';

const route = useRoute();
const router = useRouter();
const order = ref(null);
const timeline = ref([]);
const delivery = ref(null);
const loading = ref(true);
const error = ref(null);
const brokenImages = ref({});
const cartStore = useCartStore();
const repurchaseQuote = ref(null);
const repurchaseLoading = ref(false);
const repurchaseAdding = ref(false);
const repurchaseAdded = ref(false);
const repurchaseError = ref('');
let repurchaseSequence = 0;
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
const orderPriceSummary = computed(() => summarizeOrderPriceSnapshots(order.value?.items || []));
const itemPriceBreakdown = item => cartItemPriceBreakdown({ ...item, regular_price: item?.regular_price ?? item?.price });
const paymentMethodLabel = computed(() => ({
    transferencia: 'Transferencia bancaria',
    bank_transfer: 'Transferencia bancaria',
    card: 'Pago con tarjeta',
    contra_entrega: 'Pago contraentrega',
    pago_en_sede: 'Pago en sede',
}[order.value?.payment_method] || 'Método de pago registrado'));
const amountLabel = computed(() => order.value?.payment_status === 'approved' ? 'Importe confirmado' : 'Importe del pedido');
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
const paymentStatusSeverity = computed(() => order.value?.payment_status === 'approved' ? 'success' : ['rejected', 'refunded'].includes(order.value?.payment_status) ? 'danger' : 'warn');
const paymentProgressMessage = computed(() => {
    const status = order.value?.payment_status;
    const fulfillment = order.value?.fulfillment_status;
    const canceled = order.value?.status === 'canceled' || fulfillment === 'canceled';
    const finished = fulfillment === 'delivered'
        || ['delivered', 'picked_up'].includes(order.value?.tracking_status)
        || Boolean(order.value?.delivered_at || order.value?.pickup?.picked_up_at);

    if (status === 'refunded') return 'Pago reembolsado.';
    if (status === 'refund_pending') return 'Reembolso en proceso.';
    if (order.value?.reservation_status === 'expired' && status !== 'approved') return 'Reserva vencida. Las instrucciones de pago ya no están disponibles.';
    if (canceled) return '';
    if (status !== 'approved') {
        if (['contra_entrega', 'pago_en_sede'].includes(order.value?.payment_method)) return 'El pago queda pendiente al recibir o recoger.';
        return status === 'pending' ? 'Tu pago está pendiente de validación.' : '';
    }
    if (finished) return 'Pago confirmado correctamente.';
    if (['reserved', 'preparing'].includes(fulfillment)) return 'Pago confirmado. Tu pedido continuará con la preparación.';

    return 'Pago confirmado correctamente.';
});
const canRepurchase = computed(() => order.value?.payment_method === 'transferencia'
    && order.value?.payment_status === 'pending'
    && order.value?.reservation_status === 'expired'
    && !order.value?.payment_submission_status
    && !['rejected', 'delivered'].includes(order.value?.status)
    && !['delivered', 'picked_up'].includes(order.value?.tracking_status));
const repurchaseHasAvailable = computed(() => repurchaseQuote.value?.items?.some(line => line.product_available) || false);
const repurchaseHasUnavailable = computed(() => repurchaseQuote.value?.items?.some(line => !line.product_available) || false);

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
const cartQuantity = productId => Number(cartStore.items.find(item => Number(item.product_id) === Number(productId))?.quantity || 0);

async function loadRepurchaseQuote() {
    if (!canRepurchase.value || repurchaseLoading.value) return;
    const sequence = ++repurchaseSequence;
    repurchaseLoading.value = true;
    repurchaseError.value = '';
    repurchaseQuote.value = null;
    try {
        const response = await api.get(`/orders/${encodeURIComponent(route.params.id)}/repurchase-quote`);
        if (sequence !== repurchaseSequence || String(route.params.id) !== String(order.value?.id)) return;
        if (!Array.isArray(response.data?.items)) throw new TypeError('Respuesta de recompra inválida.');
        repurchaseQuote.value = response.data;
    } catch (exception) {
        if (sequence !== repurchaseSequence) return;
        repurchaseError.value = exception.response?.data?.message || 'No pudimos revisar los precios y la disponibilidad actuales.';
    } finally {
        if (sequence === repurchaseSequence) repurchaseLoading.value = false;
    }
}

async function addRepurchaseItems() {
    if (!repurchaseHasAvailable.value || repurchaseAdding.value || repurchaseAdded.value) return;
    repurchaseAdding.value = true;
    cartStore.mergeRepurchaseItems(repurchaseQuote.value.items);
    repurchaseAdded.value = true;
    try {
        await router.push('/cart');
    } catch {
        repurchaseError.value = 'Los productos se agregaron. Revisa el carrito antes de continuar.';
    } finally {
        repurchaseAdding.value = false;
    }
}
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
    repurchaseSequence++;
    repurchaseQuote.value = null;
    repurchaseError.value = '';
    repurchaseAdded.value = false;
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
