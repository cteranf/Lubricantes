<template>
    <AdminLayout>
        <div class="container mx-auto space-y-5 px-4 py-8">
            <h1 class="text-3xl font-medium text-slate-700">Preparación y entrega de pedidos</h1>
            <section class="overflow-hidden rounded-lg bg-white shadow">
                <div class="space-y-3 p-3 lg:hidden">
                    <button
                        id="orders-mobile-filters-toggle"
                        ref="mobileFiltersToggle"
                        type="button"
                        class="flex min-h-11 w-full items-center justify-between rounded border border-slate-300 px-4 py-2 text-left font-semibold text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                        :aria-expanded="mobileFiltersOpen"
                        aria-controls="orders-mobile-filters-panel"
                        @click="toggleMobileFilters"
                    >
                        <span>Filtros</span>
                        <span v-if="activeFilterCount > 0" class="rounded-full bg-blue-100 px-2 py-1 text-sm text-blue-900" aria-live="polite">{{ activeFilterCount }} activos</span>
                    </button>
                    <form
                        id="orders-mobile-filters-panel"
                        class="grid grid-cols-1 gap-4 rounded border border-slate-200 bg-slate-50 p-4"
                        :hidden="!mobileFiltersOpen"
                        aria-label="Filtros de pedidos"
                        @submit.prevent="applyMobileFilters"
                        @keydown.esc.prevent="closeMobileFilters()"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-base font-semibold">Filtros de pedidos</h2>
                            <button type="button" class="min-h-11 rounded px-3 font-semibold text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="closeMobileFilters">Cerrar filtros</button>
                        </div>
                        <div>
                            <label for="orders-mobile-order-id" class="mb-1 block text-sm font-medium">Pedido</label>
                            <input id="orders-mobile-order-id" v-model="list.filters.order_id" type="text" inputmode="numeric" class="w-full rounded border border-slate-300 bg-white px-3 py-2" placeholder="ID exacto">
                        </div>
                        <div>
                            <label for="orders-mobile-customer" class="mb-1 block text-sm font-medium">Cliente</label>
                            <input id="orders-mobile-customer" v-model="list.filters.customer" type="search" class="w-full rounded border border-slate-300 bg-white px-3 py-2" placeholder="Nombre parcial">
                        </div>
                        <fieldset class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <legend class="mb-1 text-sm font-medium">Total</legend>
                            <div>
                                <label for="orders-mobile-total-min" class="mb-1 block text-sm">Mínimo</label>
                                <input id="orders-mobile-total-min" v-model="list.filters.total_min" type="text" inputmode="decimal" class="w-full rounded border border-slate-300 bg-white px-3 py-2" placeholder="0.00">
                            </div>
                            <div>
                                <label for="orders-mobile-total-max" class="mb-1 block text-sm">Máximo</label>
                                <input id="orders-mobile-total-max" v-model="list.filters.total_max" type="text" inputmode="decimal" class="w-full rounded border border-slate-300 bg-white px-3 py-2" placeholder="0.00">
                            </div>
                        </fieldset>
                        <div>
                            <label for="orders-mobile-fulfillment" class="mb-1 block text-sm font-medium">Estado del pedido</label>
                            <select id="orders-mobile-fulfillment" v-model="list.filters.fulfillment_status" class="w-full rounded border border-slate-300 bg-white px-3 py-2">
                                <option value="">Todos</option>
                                <option v-for="option in fulfillmentOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="orders-mobile-payment-method" class="mb-1 block text-sm font-medium">Método de pago</label>
                            <select id="orders-mobile-payment-method" v-model="list.filters.payment_method" class="w-full rounded border border-slate-300 bg-white px-3 py-2">
                                <option value="">Todos</option>
                                <option v-for="option in paymentMethodOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="orders-mobile-payment-status" class="mb-1 block text-sm font-medium">Estado de pago</label>
                            <select id="orders-mobile-payment-status" v-model="list.filters.payment_status" class="w-full rounded border border-slate-300 bg-white px-3 py-2">
                                <option value="">Todos</option>
                                <option v-for="option in paymentStatusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="orders-mobile-warehouse" class="mb-1 block text-sm font-medium">Almacén</label>
                            <select id="orders-mobile-warehouse" v-model="list.filters.warehouse_id" :disabled="warehouseLoading" class="w-full rounded border border-slate-300 bg-white px-3 py-2 disabled:bg-slate-100">
                                <option value="">Todos</option>
                                <option v-for="option in warehouseOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="orders-mobile-reservation" class="mb-1 block text-sm font-medium">Reserva</label>
                            <select id="orders-mobile-reservation" v-model="list.filters.reservation_status" class="w-full rounded border border-slate-300 bg-white px-3 py-2">
                                <option value="">Todas</option>
                                <option v-for="option in reservationOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="orders-mobile-delivery" class="mb-1 block text-sm font-medium">Entrega</label>
                            <select id="orders-mobile-delivery" v-model="list.filters.delivery_type" class="w-full rounded border border-slate-300 bg-white px-3 py-2">
                                <option value="">Todas</option>
                                <option v-for="option in deliveryOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <fieldset class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <legend class="mb-1 text-sm font-medium">Fecha de creación</legend>
                            <div>
                                <label for="orders-mobile-date-from" class="mb-1 block text-sm">Desde</label>
                                <input id="orders-mobile-date-from" v-model="list.filters.date_from" type="date" class="w-full min-w-0 rounded border border-slate-300 bg-white px-3 py-2">
                            </div>
                            <div>
                                <label for="orders-mobile-date-to" class="mb-1 block text-sm">Hasta</label>
                                <input id="orders-mobile-date-to" v-model="list.filters.date_to" type="date" class="w-full min-w-0 rounded border border-slate-300 bg-white px-3 py-2">
                            </div>
                        </fieldset>
                        <div class="flex flex-col gap-2 pt-2 sm:flex-row">
                            <button type="submit" class="min-h-11 w-full rounded bg-blue-700 px-4 py-2 font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-900">Aplicar</button>
                            <button type="button" class="min-h-11 w-full rounded border border-slate-400 bg-white px-4 py-2 font-semibold text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="clearMobileFilters">Limpiar</button>
                        </div>
                    </form>
                </div>
                <AdminDataTable :rows="list.rows.value" :columns="columns" :filter-values="list.filters" row-key="id" :page="list.meta.value.current_page" :page-size="list.meta.value.per_page" :total-records="list.meta.value.total" :loading="list.loading.value" :error="list.error.value" :sort="{ column: list.sort.value, direction: list.direction.value }" @retry="list.retry" @page="list.setPage" @per-page="list.setPerPage" @sort="list.setSort" @filters-change="list.setFilters" @clear-filters="list.clearFilters">
                    <template #error><span>{{ listErrorMessage }}</span></template>
                    <template #empty>{{ list.meta.value.total === 0 && hasActiveFilters ? 'No se encontraron pedidos con los filtros aplicados' : 'No hay pedidos para mostrar' }}</template>
                    <template #filter-id="{ filterModel, filterCallback }"><InputText v-model="filterModel.value" inputmode="numeric" class="w-full" placeholder="ID exacto" @input="filterCallback" /></template>
                    <template #filter-customer_name="{ filterModel, filterCallback }"><InputText v-model="filterModel.value" class="w-full" placeholder="Nombre parcial" @input="filterCallback" /></template>
                    <template #filter-total="{ filterModel, filterCallback }"><div class="flex min-w-56 flex-col gap-2"><label class="text-xs">Mínimo</label><InputText :model-value="filterModel.value?.[0] || ''" inputmode="decimal" placeholder="0.00" @update:model-value="setTotalBound(filterModel, 0, $event, filterCallback)"/><label class="text-xs">Máximo</label><InputText :model-value="filterModel.value?.[1] || ''" inputmode="decimal" placeholder="0.00" @update:model-value="setTotalBound(filterModel, 1, $event, filterCallback)"/></div></template>
                    <template #filter-fulfillment_status="{ filterModel, filterCallback }"><Select v-model="filterModel.value" :options="fulfillmentOptions" option-label="label" option-value="value" placeholder="Todos" class="w-full" @change="filterCallback" /></template>
                    <template #filter-payment_method="{ filterModel, filterCallback }"><Select v-model="filterModel.value" :options="paymentMethodOptions" option-label="label" option-value="value" placeholder="Todos" class="w-full" @change="filterCallback" /></template>
                    <template #filter-payment_status="{ filterModel, filterCallback }"><Select v-model="filterModel.value" :options="paymentStatusOptions" option-label="label" option-value="value" placeholder="Todos" class="w-full" @change="filterCallback" /></template>
                    <template #filter-warehouse_name="{ filterModel, filterCallback }"><Select v-model="filterModel.value" :options="warehouseOptions" option-label="label" option-value="value" placeholder="Todos" class="w-full" :loading="warehouseLoading" @change="filterCallback" /></template>
                    <template #filter-reservation_status="{ filterModel, filterCallback }"><Select v-model="filterModel.value" :options="reservationOptions" option-label="label" option-value="value" placeholder="Todos" class="w-full" @change="filterCallback" /></template>
                    <template #filter-delivery_type="{ filterModel, filterCallback }"><Select v-model="filterModel.value" :options="deliveryOptions" option-label="label" option-value="value" placeholder="Todos" class="w-full" @change="filterCallback" /></template>
                    <template #filter-created_at="{ filterModel, filterCallback }"><DatePicker v-model="filterModel.value" selection-mode="range" date-format="yy-mm-dd" :manual-input="false" show-icon class="w-full" @update:model-value="filterCallback" /></template>
                    <template #cell-id="{ row }"><span class="font-semibold">Pedido #{{ row.id }}</span></template>
                    <template #cell-customer_name="{ row }"><span>{{ row.customer_name }}</span></template>
                    <template #cell-total="{ row }"><span class="font-semibold">S/ {{ row.total }}</span></template>
                    <template #cell-fulfillment_status="{ row }"><span class="rounded-full bg-blue-100 px-2 py-1 text-blue-800">{{ stateLabel(row.fulfillment_status || legacyState(row), row) }}</span></template>
                    <template #cell-payment_method="{ row }"><span class="text-xs uppercase">{{ row.payment_method || '—' }}</span></template>
                    <template #cell-payment_status="{ row }"><span :class="row.payment_status === 'approved' ? 'font-semibold text-green-700' : 'text-amber-700'">{{ row.payment_status || '—' }}</span></template>
                    <template #cell-warehouse_name="{ row }"><span>{{ row.warehouse_name || '—' }}</span></template>
                    <template #cell-reservation_status="{ row }"><span>{{ row.reservation_status || 'legacy' }}</span></template>
                    <template #cell-delivery_type="{ row }"><span>{{ deliveryLabel(row) }}</span></template>
                    <template #cell-created_at="{ row }"><span>{{ formatDate(row.created_at) }}</span></template>
                    <template #actions="{ row }"><button type="button" class="whitespace-nowrap font-semibold text-blue-700 underline" @click="openOrder(row)"><i class="pi pi-eye mr-1" aria-hidden="true"/>Gestionar</button></template>
                </AdminDataTable>
            </section>
        </div>

        <div v-if="selectedOrder" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4" @keydown.esc="closeModal">
            <div class="mx-auto my-8 max-w-4xl rounded-lg bg-white p-6 shadow-xl" role="dialog" aria-modal="true" :aria-labelledby="'order-dialog-title'">
                <div class="mb-5 flex justify-between gap-3"><div><h2 id="order-dialog-title" class="text-xl font-bold">Pedido #{{ selectedOrder.id }}</h2><p class="text-slate-500">{{ selectedOrder.user?.name }} · {{ selectedOrder.payment_method }} · Pago {{ selectedOrder.payment_status }}</p></div><button type="button" class="text-2xl" aria-label="Cerrar gestión del pedido" @click="closeModal">&times;</button></div>
                <div v-if="detailLoading" class="p-8 text-center" role="status">Cargando detalle…</div>
                <div v-else-if="detailError" class="rounded bg-red-50 p-4 text-red-800" role="alert">{{ detailError }} <button type="button" class="underline" @click="openOrder(selectedOrder)">Reintentar</button></div>
                <template v-else>
                    <div class="mb-5 grid gap-3 md:grid-cols-3"><Info label="Estado operativo" :value="fulfillment.label"/><Info label="Almacén reservado" :value="fulfillment.warehouse?.name || '—'"/><Info label="Reserva" :value="`${fulfillment.reservation_status} · ${fulfillment.expires_at ? formatDate(fulfillment.expires_at) : 'sin vencimiento'}`"/><Info label="Preparación" :value="stepText(fulfillment.preparing_at, fulfillment.prepared_by)"/><Info :label="selectedOrder.delivery_type === 'pickup' ? 'Listo para recoger' : 'Listo'" :value="stepText(fulfillment.ready_at, fulfillment.ready_by)"/><Info :label="selectedOrder.delivery_type === 'pickup' ? 'Recogido' : 'Entrega'" :value="stepText(fulfillment.picked_up_at || fulfillment.delivered_at, fulfillment.delivered_by)"/></div>
                    <div v-if="selectedOrder.delivery_type === 'pickup'" class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4"><b>Recojo en sede · {{ selectedOrder.pickup_branch_name_snapshot || 'Sede histórica' }}</b><p class="text-sm">{{ selectedOrder.pickup_address_snapshot }}<template v-if="selectedOrder.pickup_district_snapshot"> — {{ selectedOrder.pickup_district_snapshot }}</template></p><p v-if="selectedOrder.pickup_business_hours_snapshot" class="text-sm">Horario: {{ selectedOrder.pickup_business_hours_snapshot }}</p><p v-if="selectedOrder.pickup_instructions_snapshot" class="text-sm">{{ selectedOrder.pickup_instructions_snapshot }}</p><p class="mt-2 text-sm"><b>Plazo:</b> {{ deadlineLabel(fulfillment.pickup_deadline_status) }}<template v-if="fulfillment.pickup_deadline_at"> · hasta {{ formatDate(fulfillment.pickup_deadline_at) }}</template></p></div>
                    <div v-else class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-4"><b>Envío a domicilio</b><p class="text-sm">{{ selectedOrder.shipping_info?.address }}<template v-if="selectedOrder.shipping_district_snapshot"> — {{ selectedOrder.shipping_district_snapshot }}</template></p><p class="text-sm">Zona: {{ selectedOrder.shipping_zone_name_snapshot || 'Configuración histórica' }} · Envío S/ {{ selectedOrder.shipping_amount || '0.00' }}</p><p v-if="selectedOrder.shipping_estimated_days_min_snapshot != null" class="text-sm">Plazo estimado: {{ selectedOrder.shipping_estimated_days_min_snapshot }}–{{ selectedOrder.shipping_estimated_days_max_snapshot }} días</p></div>
                    <div v-if="selectedOrder.payment_method === 'transferencia' && selectedOrder.has_payment_submission" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900"><span class="font-semibold">{{ paymentSubmissionLabel(selectedOrder.payment_submission_status) }}</span><p class="mt-1 text-sm">Este pago se gestiona desde el flujo de Tesorería.</p></div>
                    <div class="mb-6 flex flex-wrap gap-3"><button v-if="fulfillment.actions?.approve_payment && !selectedOrder.has_payment_submission" type="button" class="rounded bg-emerald-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="actionLoading" @click="confirmAction('approve')">Aprobar transferencia</button><button v-if="fulfillment.actions?.start_preparation" type="button" class="rounded bg-blue-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="actionLoading" @click="confirmAction('start')">Iniciar preparación</button><button v-if="fulfillment.actions?.mark_ready" type="button" class="rounded bg-indigo-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="actionLoading" @click="confirmAction('ready')">{{ selectedOrder.delivery_type === 'pickup' ? 'Listo para recoger' : 'Marcar como listo' }}</button><button v-if="fulfillment.actions?.mark_picked_up" type="button" class="rounded bg-green-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="actionLoading" @click="confirmAction('picked')">Pedido recogido</button><button v-if="fulfillment.actions?.mark_delivered" type="button" class="rounded bg-green-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="actionLoading" @click="confirmAction('delivered')">Marcar como entregado</button><button v-if="fulfillment.actions?.cancel" type="button" class="rounded bg-red-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="actionLoading" @click="confirmAction('cancel')">Cancelar pedido</button></div>
                    <OrderPickingPackingPanel v-if="handling" :order-id="selectedOrder.id" :summary="handling" @updated="handlingUpdated"/><OrderDeliveryPanel v-if="selectedOrder.delivery_type !== 'pickup' && (fulfillment.status === 'ready' || fulfillment.status === 'delivered')" :order-id="selectedOrder.id" :initial="delivery" @updated="deliveryUpdated"/>
                    <h3 class="mb-2 border-b pb-2 font-bold">Productos</h3><div v-for="item in selectedOrder.items" :key="item.id" class="flex justify-between border-b py-2"><div>{{ item.product?.name }}<div class="text-xs text-slate-500">{{ item.warehouse?.name }} · reserva {{ item.reservation?.status || 'legacy' }}</div></div><b>{{ item.quantity }} × S/ {{ item.price }}</b></div>
                    <h3 class="mb-2 mt-6 border-b pb-2 font-bold">Historial operativo</h3><div v-if="!fulfillment.history?.length" class="py-3 text-slate-500">Sin transiciones registradas.</div><div v-for="entry in fulfillment.history" :key="entry.id" class="border-l-4 border-blue-300 py-2 pl-3"><b>{{ stateLabel(entry.from_status) }} → {{ stateLabel(entry.to_status) }}</b><div class="text-xs text-slate-500">{{ formatDate(entry.created_at) }} · {{ entry.user?.name || 'Sistema' }}</div><p v-if="entry.observation" class="text-sm">{{ entry.observation }}</p></div>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, ref } from 'vue';
import { onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminDataTable from '@/components/admin/table/AdminDataTable.vue';
import OrderPickingPackingPanel from '@/components/admin/OrderPickingPackingPanel.vue';
import OrderDeliveryPanel from '@/components/admin/OrderDeliveryPanel.vue';
import api from '@/api';
import { useAdminListQuery } from '@/composables/useAdminListQuery';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { formatDateTime } from '@/utils/dateTime';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';

const Info = defineComponent({ props: { label: String, value: String }, setup: (props) => () => h('div', { class: 'rounded bg-slate-50 p-3' }, [h('div', { class: 'text-xs uppercase text-slate-500' }, props.label), h('div', { class: 'mt-1 font-semibold' }, props.value)]) });
const route = useRoute();
const router = useRouter();
const toast = useToast();
const confirm = useConfirm();
const list = useAdminListQuery({ route, router, filters: ['search', 'order_id', 'customer', 'total_min', 'total_max', 'fulfillment_status', 'payment_method', 'payment_status', 'warehouse_id', 'reservation_status', 'delivery_type', 'date_from', 'date_to'], sorts: ['created_at', 'total', 'id'], fetchPage: async (params) => (await api.get('/admin/orders', { params })).data });
const columns = [
    { key: 'id', label: 'Pedido', sortable: true, filterKey: 'order_id', filterMatchMode: 'equals', class: 'whitespace-nowrap' },
    { key: 'customer_name', label: 'Cliente', filterKey: 'customer', filterMatchMode: 'contains', class: 'min-w-48' },
    { key: 'total', label: 'Total', sortable: true, filterKey: 'total_range', filterMatchMode: 'between', class: 'hidden md:table-cell whitespace-nowrap', mobileDetail: true },
    { key: 'fulfillment_status', label: 'Estado', filterKey: 'fulfillment_status', filterMatchMode: 'equals', class: 'whitespace-nowrap' },
    { key: 'payment_method', label: 'Pago', filterKey: 'payment_method', filterMatchMode: 'equals', class: 'hidden lg:table-cell', mobileDetail: true },
    { key: 'payment_status', label: 'Estado de pago', filterKey: 'payment_status', filterMatchMode: 'equals', class: 'hidden lg:table-cell', mobileDetail: true },
    { key: 'warehouse_name', label: 'Almacén', filterKey: 'warehouse_id', filterMatchMode: 'equals', class: 'hidden lg:table-cell', mobileDetail: true },
    { key: 'reservation_status', label: 'Reserva', filterKey: 'reservation_status', filterMatchMode: 'equals', class: 'hidden lg:table-cell', mobileDetail: true },
    { key: 'delivery_type', label: 'Entrega', filterKey: 'delivery_type', filterMatchMode: 'equals', class: 'hidden lg:table-cell', mobileDetail: true },
    { key: 'created_at', label: 'Fecha', sortable: true, filterKey: 'date_range', filterMatchMode: 'between', class: 'hidden lg:table-cell whitespace-nowrap', mobileDetail: true },
];
const fulfillmentOptions = [{ value: 'reserved', label: 'Reservado' }, { value: 'preparing', label: 'En preparación' }, { value: 'ready', label: 'Listo' }, { value: 'delivered', label: 'Entregado' }, { value: 'canceled', label: 'Cancelado' }];
const paymentMethodOptions = [{ value: 'card', label: 'Tarjeta' }, { value: 'transferencia', label: 'Transferencia' }, { value: 'contra_entrega', label: 'Contraentrega' }, { value: 'pago_en_sede', label: 'Pago en sede' }];
const paymentStatusOptions = [{ value: 'pending', label: 'Pendiente' }, { value: 'approved', label: 'Aprobado' }, { value: 'rejected', label: 'Rechazado' }, { value: 'refund_pending', label: 'Reembolso pendiente' }, { value: 'refunded', label: 'Reembolsado' }];
const reservationOptions = [{ value: 'active', label: 'Activa' }, { value: 'consumed', label: 'Consumida' }, { value: 'released', label: 'Liberada' }, { value: 'expired', label: 'Vencida' }];
const deliveryOptions = [{ value: 'delivery', label: 'Envío a domicilio' }, { value: 'pickup', label: 'Recojo en sede' }];
const warehouseOptions = ref([]);
const warehouseLoading = ref(false);
const mobileFiltersOpen = ref(false);
const mobileFiltersToggle = ref(null);
let mobileFilterCloseSequence = 0;
const activeFilterCount = computed(() => {
    const filters = list.filters;
    const singleFilters = [filters.search, filters.order_id, filters.customer, filters.fulfillment_status, filters.payment_method,
        filters.payment_status, filters.warehouse_id, filters.reservation_status, filters.delivery_type];
    return singleFilters.filter((value) => value !== '').length
        + Number(Boolean(filters.total_min || filters.total_max))
        + Number(Boolean(filters.date_from || filters.date_to));
});
const hasActiveFilters = computed(() => activeFilterCount.value > 0);
function applyMobileFilters() {
    const update = list.applyFilters();
    void closeMobileFilters();
    return update;
}
function clearMobileFilters() {
    const update = list.clearFilters();
    void closeMobileFilters();
    return update;
}
function toggleMobileFilters() {
    if (mobileFiltersOpen.value) {
        return closeMobileFilters();
    }

    mobileFiltersOpen.value = true;
}
async function closeMobileFilters({ restoreFocus = true } = {}) {
    if (!mobileFiltersOpen.value) return;

    mobileFiltersOpen.value = false;
    const sequence = ++mobileFilterCloseSequence;
    await nextTick();

    if (!restoreFocus || !componentMounted || sequence !== mobileFilterCloseSequence) return;

    const toggle = mobileFiltersToggle.value;
    if (toggle?.isConnected && toggle.getClientRects().length > 0) {
        toggle.focus();
    }
}
onMounted(async () => {
    warehouseLoading.value = true;
    try {
        const { data } = await api.get('/admin/warehouses/options');
        warehouseOptions.value = Array.isArray(data) ? data.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name })) : [];
    } catch {
        warehouseOptions.value = [];
    } finally {
        warehouseLoading.value = false;
    }
});
function setTotalBound(filterModel, bound, value, callback) {
    const range = filterModel.value || [null, null];
    range[bound] = value || null;
    filterModel.value = [...range];
    callback();
}
const selectedOrder = ref(null);
const fulfillment = ref({ actions: {} });
const handling = ref(null);
const delivery = ref(null);
const detailLoading = ref(false);
const detailError = ref('');
const actionLoading = ref(false);
let detailSequence = 0;
let actionSequence = 0;
let modalSequence = 0;
let componentMounted = true;
const activeActionsByOrder = new Map();
const statusMessages = { 401: 'La sesión expiró. Inicia sesión nuevamente.', 403: 'No tienes permiso para consultar estos pedidos.', 422: 'La consulta contiene parámetros no válidos.', 500: 'No se pudieron cargar los pedidos por un error interno.' };
const actionStatusMessages = {
    401: 'Tu sesión ha expirado. Inicia sesión nuevamente.',
    403: 'No tienes permisos para realizar esta acción.',
    404: 'El pedido solicitado ya no está disponible.',
    409: 'El pedido cambió mientras realizabas la operación. Actualiza la información e inténtalo nuevamente.',
    422: 'No se pudo completar la acción con los datos actuales. Revisa la información e inténtalo nuevamente.',
    429: 'Se realizaron demasiadas solicitudes. Espera un momento e inténtalo nuevamente.',
};
const listErrorMessage = computed(() => statusMessages[list.error.value?.response?.status] || 'No se pudieron cargar los pedidos.');

function stateLabel(status, order) { return ({ reserved: 'Reservado', preparing: 'En preparación', ready: order?.delivery_type === 'pickup' ? 'Listo para recoger' : 'Listo para entrega', delivered: order?.delivery_type === 'pickup' ? 'Recogido' : 'Entregado', canceled: 'Cancelado' })[status] || status || 'Reservado'; }
function legacyState(order) { return ['canceled', 'rejected'].includes(order.status) ? 'canceled' : order.status === 'delivered' ? 'delivered' : 'reserved'; }
function deliveryLabel(order) { return order.delivery_type === 'pickup' ? `Recojo en sede · ${order.pickup_branch_name_snapshot || 'Sede histórica'}` : 'Envío a domicilio'; }
const deadlineLabel = (status) => ({ preparing: 'En preparación', within_deadline: 'Dentro del plazo', expired: 'Recojo vencido · requiere revisión administrativa', picked_up: 'Recogido', not_applicable: 'No aplica' })[status] || status;
const paymentSubmissionLabel = (status) => ({ pending_review: 'Pendiente de Tesorería', observed: 'Observado por Tesorería', approved: 'Aprobado por Tesorería', rejected: 'Rechazado por Tesorería', expired: 'Presentación vencida', canceled: 'Presentación cancelada' })[status] || 'Pago gestionado por Tesorería';
const formatDate = formatDateTime;
const stepText = (date, user) => date ? `${formatDate(date)} · ${user?.name || 'Sistema'}` : 'Pendiente';

async function openOrder(order) {
    invalidateModalActions();
    const sequence = ++detailSequence;
    selectedOrder.value = order;
    detailLoading.value = true;
    detailError.value = '';
    try {
        const { data } = await api.get(`/admin/orders/${order.id}/fulfillment`);
        if (sequence !== detailSequence) return;
        selectedOrder.value = data.order;
        fulfillment.value = data.fulfillment;
        handling.value = data.fulfillment.picking_packing || null;
        if (order.delivery_type !== 'pickup') {
            const deliveryResponse = await api.get(`/admin/orders/${order.id}/delivery`);
            if (sequence !== detailSequence) return;
            delivery.value = deliveryResponse.data;
        } else {
            delivery.value = null;
        }
    } catch (error) {
        if (sequence !== detailSequence) return;
        const status = error.response?.status;
        detailError.value = statusMessages[status] || 'No se pudo cargar el detalle del pedido.';
    } finally {
        if (sequence === detailSequence) detailLoading.value = false;
    }
}

function invalidateModalActions() {
    modalSequence++;
    actionLoading.value = false;
}
function actionContextIsCurrent(context) {
    const active = activeActionsByOrder.get(context.orderId);
    return componentMounted
        && modalSequence === context.modalSequence
        && selectedOrder.value?.id === context.orderId
        && active?.sequence === context.sequence
        && active?.action === context.action;
}
function closeModal() { invalidateModalActions(); detailSequence++; selectedOrder.value = null; fulfillment.value = { actions: {} }; handling.value = null; delivery.value = null; detailError.value = ''; }
onBeforeUnmount(() => {
    componentMounted = false;
    void closeMobileFilters({ restoreFocus: false });
    invalidateModalActions();
    detailSequence++;
    activeActionsByOrder.clear();
});
function handlingUpdated(summary) { handling.value = summary; fulfillment.value.picking_packing = summary; fulfillment.value.actions.mark_ready = Boolean(summary.actions?.mark_ready); }
async function deliveryUpdated(value) { delivery.value = value; if (value.order?.fulfillment_status === 'delivered') await openOrder({ id: selectedOrder.value.id, delivery_type: 'delivery' }); await list.load(); }
function confirmAction(action) {
    const messages = { approve: '¿Confirmar la recepción de la transferencia?', start: '¿Iniciar la preparación?', ready: selectedOrder.value.delivery_type === 'pickup' ? '¿Marcar como listo para recoger e iniciar el plazo de 10 días hábiles?' : '¿Marcar como listo?', picked: '¿Confirmar que el cliente recogió el pedido?', delivered: '¿Marcar como entregado?', cancel: 'La cancelación afectará la reserva o el inventario consumido.' };
    confirm.require({ message: messages[action], header: 'Confirmación', accept: () => runAction(action) });
}
async function runAction(action) {
    const targetOrder = selectedOrder.value;
    if (!targetOrder || actionLoading.value) return;
    const targetOrderId = targetOrder.id;
    const targetModalSequence = modalSequence;
    let payload = { observation: null };
    if (action === 'cancel') { const reason = window.prompt('Motivo obligatorio:'); if (!reason) return; payload = { reason }; }
    if (action === 'picked' && ['contra_entrega', 'pago_en_sede'].includes(targetOrder.payment_method)) { const method = window.prompt('Medio: cash, card_terminal, bank_transfer u other', 'cash'); if (!method) return; payload = { money_received: true, collection_method: method }; }
    if (!componentMounted || modalSequence !== targetModalSequence || selectedOrder.value?.id !== targetOrderId) return;
    const paths = { approve: 'approve-transfer', start: 'start-preparation', ready: targetOrder.delivery_type === 'pickup' ? 'ready-for-pickup' : 'ready', picked: 'picked-up', delivered: 'delivered', cancel: 'cancel' };
    const sequence = ++actionSequence;
    const context = { action, orderId: targetOrderId, modalSequence: targetModalSequence, sequence };
    activeActionsByOrder.set(targetOrderId, { action, sequence });
    actionLoading.value = true;
    try {
        const { data } = await api.post(`/admin/orders/${targetOrderId}/fulfillment/${paths[action]}`, payload);
        if (!actionContextIsCurrent(context) || data.order?.id !== targetOrderId) return;
        selectedOrder.value = data.order; fulfillment.value = data.fulfillment; handling.value = data.fulfillment.picking_packing || handling.value;
        toast.add({ severity: 'success', summary: 'Pedido actualizado', life: 2500 }); await list.load();
    } catch (error) { if (actionContextIsCurrent(context)) showActionError(error); }
    finally {
        const contextWasCurrent = actionContextIsCurrent(context);
        if (activeActionsByOrder.get(targetOrderId)?.sequence === sequence) activeActionsByOrder.delete(targetOrderId);
        if (contextWasCurrent) actionLoading.value = false;
    }
}
function safeActionErrorMessage(error) {
    const status = error?.response?.status;
    if (Object.prototype.hasOwnProperty.call(actionStatusMessages, status)) return actionStatusMessages[status];
    if (Number.isInteger(status) && status >= 500 && status <= 599) return 'Ocurrió un problema interno al procesar la solicitud. Inténtalo nuevamente.';
    return 'No se pudo completar la solicitud. Verifica tu conexión e inténtalo nuevamente.';
}
function showActionError(error) { toast.add({ severity: 'error', summary: 'Operación no completada', detail: safeActionErrorMessage(error), life: 4500 }); }
</script>
