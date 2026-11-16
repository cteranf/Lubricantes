<template>
    <AdminLayout>
        <div class="container mx-auto space-y-5 px-4 py-8">
            <header class="space-y-2">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-blue-700">Operaciones</p>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900">Preparación y entrega de pedidos</h1>
                <p class="text-sm text-slate-600 sm:text-base">Supervisa y gestiona el avance de los pedidos</p>
            </header>
            <section class="space-y-4">
                <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm xl:hidden">
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
                            <button v-if="hasActiveFilters" type="button" class="min-h-11 w-full rounded border border-slate-400 bg-white px-4 py-2 font-semibold text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="clearMobileFilters">Limpiar filtros</button>
                        </div>
                    </form>
                </div>
                <div v-if="activeFilterTags.length" class="flex flex-wrap items-center gap-2" aria-label="Filtros activos">
                    <span class="mr-1 text-sm font-semibold text-slate-600">Filtros activos:</span>
                    <button v-for="filter in activeFilterTags" :key="filter.key" type="button" class="inline-flex min-h-9 items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-900 hover:bg-blue-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" :aria-label="`Quitar filtro ${filter.label}`" @click="removeFilter(filter.key)">
                        <span>{{ filter.label }}: {{ filter.value }}</span><i class="pi pi-times text-xs" aria-hidden="true" />
                    </button>
                </div>
                <div v-if="list.error.value" class="flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900 sm:flex-row sm:items-center sm:justify-between" role="alert" aria-live="assertive">
                    <span>{{ list.rows.value.length ? 'No se pudo actualizar el listado. Se conservan los resultados anteriores.' : listErrorMessage }}</span>
                    <Button type="button" label="Reintentar" icon="pi pi-refresh" severity="danger" outlined size="small" :disabled="list.loading.value" @click="list.retry" />
                </div>
                <div class="hidden md:block" aria-label="Tabla de pedidos">
                <AdminDataTable :rows="list.rows.value" :columns="columns" :filter-values="list.filters" row-key="id" :page="list.meta.value.current_page" :page-size="list.meta.value.per_page" :total-records="list.meta.value.total" :loading="list.loading.value" :error="null" :show-clear-filters="hasActiveFilters" :sort="{ column: list.sort.value, direction: list.direction.value }" @retry="list.retry" @page="list.setPage" @per-page="list.setPerPage" @sort="list.setSort" @filters-change="list.setFilters" @clear-filters="list.clearFilters">
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
                    <template #cell-id="{ row }"><span class="font-bold text-slate-900">#{{ row.id }}</span></template>
                    <template #cell-customer_name="{ row }"><span class="font-medium text-slate-800">{{ row.customer_name || 'Cliente no disponible' }}</span></template>
                    <template #cell-total="{ row }"><span class="block text-right font-bold tabular-nums text-slate-900">{{ moneyLabel(row.total) }}</span></template>
                    <template #cell-fulfillment_status="{ row }"><Tag :value="stateLabel(row.fulfillment_status, row)" :severity="stateSeverity(row.fulfillment_status)" :class="stateClass(row.fulfillment_status)" /></template>
                    <template #cell-payment_method="{ row }"><span class="text-sm text-slate-600">{{ paymentMethodLabel(row.payment_method) }}</span></template>
                    <template #cell-payment_status="{ row }"><Tag :value="paymentStatusLabel(row.payment_status)" :severity="paymentStatusSeverity(row.payment_status)" /></template>
                    <template #cell-warehouse_name="{ row }"><div class="space-y-1"><p>{{ row.warehouse_name || 'Almacén no disponible' }}</p><Tag :value="reservationLabel(row.reservation_status)" :severity="reservationSeverity(row.reservation_status)" /></div></template>
                    <template #cell-reservation_status="{ row }"><Tag :value="reservationLabel(row.reservation_status)" :severity="reservationSeverity(row.reservation_status)" /></template>
                    <template #cell-delivery_type="{ row }"><span class="text-sm font-medium text-slate-700">{{ deliveryLabel(row) }}</span></template>
                    <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-sm text-slate-600">{{ formatDate(row.created_at) }}</span></template>
                        <template #actions="{ row }"><Button type="button" label="Gestionar" icon="pi pi-eye" size="small" outlined :disabled="detailLoading && openingOrderId === row.id" :aria-label="`Gestionar pedido ${row.id}`" @click="openOrder(row, $event)" /></template>
                </AdminDataTable>
                </div>
                <div class="space-y-3 md:hidden" aria-label="Pedidos de esta página" :aria-busy="list.loading.value">
                    <div v-if="list.loading.value && !list.rows.value.length" class="rounded-xl border border-slate-200 bg-white p-5 text-center text-sm text-slate-600" role="status" aria-live="polite"><i class="pi pi-spin pi-spinner mr-2" aria-hidden="true" />Cargando pedidos…</div>
                    <article v-for="row in list.rows.value" :key="row.id" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pedido</p><h2 class="text-lg font-bold text-slate-900">#{{ row.id }}</h2></div><span class="text-right text-xs text-slate-500">{{ formatDate(row.created_at) }}</span></div>
                        <p class="break-words font-semibold text-slate-800">{{ row.customer_name || 'Cliente no disponible' }}</p>
                        <div class="flex flex-wrap gap-2"><Tag :value="stateLabel(row.fulfillment_status, row)" :severity="stateSeverity(row.fulfillment_status)" :class="stateClass(row.fulfillment_status)"/><Tag :value="paymentStatusLabel(row.payment_status)" :severity="paymentStatusSeverity(row.payment_status)"/></div>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                            <div><dt class="text-xs font-medium text-slate-500">Entrega</dt><dd class="mt-0.5 font-medium text-slate-800">{{ deliveryLabel(row) }}</dd></div>
                            <div><dt class="text-xs font-medium text-slate-500">Importe</dt><dd class="mt-0.5 text-right font-bold tabular-nums text-slate-900">{{ moneyLabel(row.total) }}</dd></div>
                            <div><dt class="text-xs font-medium text-slate-500">Método de pago</dt><dd class="mt-0.5 text-slate-700">{{ paymentMethodLabel(row.payment_method) }}</dd></div>
                            <div><dt class="text-xs font-medium text-slate-500">Almacén · reserva</dt><dd class="mt-0.5 break-words text-slate-700">{{ row.warehouse_name || '—' }} · {{ reservationLabel(row.reservation_status) }}</dd></div>
                        </dl>
                        <Button type="button" label="Gestionar" icon="pi pi-eye" class="w-full" size="small" :disabled="detailLoading && openingOrderId === row.id" :aria-label="`Gestionar pedido ${row.id}`" @click="openOrder(row, $event)" />
                    </article>
                    <div v-if="!list.loading.value && !list.rows.value.length && !list.error.value" class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center" role="status">
                        <i class="pi pi-inbox mb-3 text-2xl text-slate-400" aria-hidden="true" />
                        <h2 class="font-semibold text-slate-800">{{ hasActiveFilters ? 'No se encontraron pedidos' : 'No hay pedidos para mostrar' }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ hasActiveFilters ? 'Prueba cambiando o limpiando los filtros.' : 'Los pedidos aparecerán aquí cuando estén disponibles.' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-3 py-3 shadow-sm" aria-label="Paginación de pedidos">
                        <p class="text-sm text-slate-600" aria-live="polite">{{ pageSummary }}</p>
                        <div class="flex gap-2"><Button type="button" label="Anterior" icon="pi pi-chevron-left" severity="secondary" outlined size="small" :disabled="list.loading.value || list.meta.value.current_page <= 1" @click="list.setPage(list.meta.value.current_page - 1)"/><Button type="button" label="Siguiente" icon="pi pi-chevron-right" icon-pos="right" severity="secondary" outlined size="small" :disabled="list.loading.value || list.meta.value.current_page >= list.meta.value.last_page" @click="list.setPage(list.meta.value.current_page + 1)"/></div>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="selectedOrder" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 p-2 sm:p-4" @click.self="closeModal">
            <div ref="orderDialog" class="order-dialog mx-auto my-2 max-h-[calc(100dvh-1rem)] max-w-6xl overflow-y-auto rounded-2xl bg-slate-50 shadow-2xl sm:my-4 sm:max-h-[calc(100dvh-2rem)]" role="dialog" aria-modal="true" aria-labelledby="order-dialog-title" aria-describedby="order-dialog-description" tabindex="-1" @keydown.esc.prevent.stop="closeModal" @keydown.tab="trapModalFocus">
                <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-700">Gestión operativa</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <h2 id="order-dialog-title" class="text-xl font-bold text-slate-950 sm:text-2xl">Pedido #{{ selectedOrder.id }}</h2>
                                <Tag v-if="fulfillment.status" :value="stateLabel(fulfillment.status, selectedOrder)" :severity="stateSeverity(fulfillment.status)" :class="stateClass(fulfillment.status)" />
                            </div>
                            <p id="order-dialog-description" class="mt-1 text-sm text-slate-600">{{ selectedOrder.user?.name || selectedOrder.customer_name || 'Cliente no disponible' }} · {{ deliveryLabel(selectedOrder) }}</p>
                        </div>
                        <Button type="button" label="Cerrar" icon="pi pi-times" severity="secondary" text aria-label="Cerrar gestión del pedido y volver al listado" @click="closeModal" />
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 px-3 py-2"><span class="block text-xs text-slate-500">Total registrado</span><strong class="tabular-nums text-slate-900">{{ moneyLabel(selectedOrder.total) }}</strong></div>
                        <div class="rounded-xl bg-slate-50 px-3 py-2"><span class="block text-xs text-slate-500">Pago</span><strong>{{ paymentStatusLabel(selectedOrder.payment_status) }}</strong><span class="block text-xs text-slate-600">{{ paymentMethodLabel(selectedOrder.payment_method) }}</span></div>
                        <div class="rounded-xl bg-slate-50 px-3 py-2"><span class="block text-xs text-slate-500">Almacén</span><strong>{{ fulfillment.warehouse?.name || '—' }}</strong></div>
                        <div class="rounded-xl bg-slate-50 px-3 py-2"><span class="block text-xs text-slate-500">Reserva</span><strong>{{ reservationLabel(fulfillment.reservation_status) }}</strong><span v-if="fulfillment.expires_at" class="block text-xs text-slate-600">Vence {{ formatDate(fulfillment.expires_at) }}</span></div>
                    </div>
                    <p v-if="selectedOrder.payment_method === 'transferencia' && selectedOrder.has_payment_submission" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                        {{ paymentSubmissionLabel(selectedOrder.payment_submission_status) }}. Este pago se gestiona desde el flujo de Tesorería.
                    </p>
                </header>

                <div class="space-y-5 p-4 sm:p-6">
                    <div v-if="detailLoading" class="rounded-xl border border-blue-100 bg-white p-8 text-center text-slate-700" role="status" aria-live="polite"><i class="pi pi-spin pi-spinner mr-2" aria-hidden="true" />Cargando la gestión del pedido…</div>
                    <div v-else-if="detailError" class="flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900 sm:flex-row sm:items-center sm:justify-between" role="alert" aria-live="assertive"><span>{{ detailError }}</span><Button type="button" label="Reintentar" icon="pi pi-refresh" severity="danger" outlined :disabled="detailLoading" @click="openOrder(selectedOrder)" /></div>
                    <template v-else>
                        <section aria-labelledby="order-progress-title" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                                <div><h3 id="order-progress-title" class="text-lg font-bold text-slate-900">Progreso operativo</h3><p class="text-sm text-slate-600">Etapas informativas basadas en los registros actuales.</p></div>
                                <p class="text-sm font-semibold text-slate-700" role="status">{{ activeIncidentCount ? `${activeIncidentCount} incidencia${activeIncidentCount === 1 ? '' : 's'} abierta${activeIncidentCount === 1 ? '' : 's'}` : 'Sin incidencias abiertas' }}</p>
                            </div>
                            <ol class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Etapas del pedido">
                                <li v-for="stage in operationalStages" :key="stage.key" class="rounded-xl border p-3" :class="stageClass(stage.state)">
                                    <div class="flex items-start gap-3"><span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/80"><i :class="stageIcon(stage.state)" aria-hidden="true" /></span><div class="min-w-0"><h4 class="font-semibold">{{ stage.label }}</h4><p class="text-sm">{{ stage.detail }}</p><span class="mt-1 inline-block text-xs font-bold">{{ stageLabel(stage.state) }}</span></div></div>
                                </li>
                            </ol>
                        </section>

                        <section v-if="selectedOrder.delivery_type === 'pickup'" aria-labelledby="order-destination-title" class="rounded-2xl border border-blue-200 bg-blue-50 p-4 sm:p-5">
                            <h3 id="order-destination-title" class="font-bold text-blue-950">Recojo en sede · {{ selectedOrder.pickup_branch_name_snapshot || 'Sede histórica' }}</h3>
                            <p class="mt-1 text-sm text-blue-900">{{ selectedOrder.pickup_address_snapshot || 'Dirección no disponible' }}<template v-if="selectedOrder.pickup_district_snapshot"> — {{ selectedOrder.pickup_district_snapshot }}</template></p>
                            <p v-if="selectedOrder.pickup_business_hours_snapshot" class="mt-1 text-sm text-blue-900">Horario: {{ selectedOrder.pickup_business_hours_snapshot }}</p>
                            <p v-if="selectedOrder.pickup_instructions_snapshot" class="mt-1 text-sm text-blue-900">{{ selectedOrder.pickup_instructions_snapshot }}</p>
                            <p class="mt-3 text-sm text-blue-950"><b>Plazo:</b> {{ deadlineLabel(fulfillment.pickup_deadline_status) }}<template v-if="fulfillment.pickup_deadline_at"> · hasta {{ formatDate(fulfillment.pickup_deadline_at) }}</template></p>
                        </section>
                        <section v-else aria-labelledby="order-destination-title" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h3 id="order-destination-title" class="font-bold text-slate-900">Envío a domicilio</h3>
                            <p class="mt-1 text-sm text-slate-700">{{ selectedOrder.shipping_info?.address || 'Dirección no disponible' }}<template v-if="selectedOrder.shipping_district_snapshot"> — {{ selectedOrder.shipping_district_snapshot }}</template></p>
                            <p class="mt-1 text-sm text-slate-700">Zona: {{ selectedOrder.shipping_zone_name_snapshot || 'Configuración histórica' }} · Envío {{ moneyLabel(selectedOrder.shipping_amount) }}</p>
                            <p v-if="selectedOrder.shipping_estimated_days_min_snapshot != null" class="mt-1 text-sm text-slate-700">Plazo estimado registrado: {{ selectedOrder.shipping_estimated_days_min_snapshot }}–{{ selectedOrder.shipping_estimated_days_max_snapshot }} días</p>
                        </section>

                        <OrderPickingPackingPanel v-if="handling" :order-id="selectedOrder.id" :summary="handling" @updated="handlingUpdated" />
                        <section v-if="!handling?.items?.length && selectedOrder.items?.length" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" aria-labelledby="order-products-title">
                            <h3 id="order-products-title" class="font-bold text-slate-900">Productos del pedido</h3>
                            <ul class="mt-3 divide-y divide-slate-100">
                                <li v-for="item in selectedOrder.items" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                                    <div class="min-w-0"><p class="font-semibold text-slate-900">{{ item.product?.name || 'Producto no disponible' }}</p><p class="text-xs text-slate-600">{{ item.warehouse?.name || 'Almacén no disponible' }} · reserva {{ reservationLabel(item.reservation?.status) }}</p></div>
                                    <p class="shrink-0 font-semibold tabular-nums text-slate-800">{{ item.quantity }} × {{ moneyLabel(item.price) }}</p>
                                </li>
                            </ul>
                        </section>
                        <section v-if="fulfillment.actions?.approve_payment || fulfillment.actions?.start_preparation || fulfillment.actions?.mark_ready || fulfillment.actions?.mark_picked_up || fulfillment.actions?.mark_delivered || fulfillment.actions?.cancel" aria-labelledby="order-actions-title" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <div class="mb-3"><h3 id="order-actions-title" class="font-bold text-slate-900">Siguiente acción autorizada</h3><p class="text-sm text-slate-600">Las acciones disponibles corresponden al estado confirmado por el sistema.</p></div>
                            <div class="flex flex-wrap gap-2">
                                <Button v-if="fulfillment.actions?.approve_payment && !selectedOrder.has_payment_submission" type="button" label="Aprobar transferencia" icon="pi pi-check" severity="success" :outlined="primaryActionName !== 'approve'" :disabled="actionLoading" @click="confirmAction('approve')" />
                                <Button v-if="fulfillment.actions?.start_preparation" type="button" label="Iniciar preparación" icon="pi pi-play" :outlined="primaryActionName !== 'start'" :disabled="actionLoading" @click="confirmAction('start')" />
                                <Button v-if="fulfillment.actions?.mark_ready" type="button" :label="selectedOrder.delivery_type === 'pickup' ? 'Listo para recoger' : 'Marcar como listo'" icon="pi pi-check-circle" severity="info" :outlined="primaryActionName !== 'ready'" :disabled="actionLoading" @click="confirmAction('ready')" />
                                <Button v-if="fulfillment.actions?.mark_picked_up" type="button" label="Confirmar recojo" icon="pi pi-box" severity="success" :outlined="primaryActionName !== 'picked'" :disabled="actionLoading" @click="confirmAction('picked')" />
                                <Button v-if="fulfillment.actions?.mark_delivered" type="button" label="Marcar como entregado" icon="pi pi-check-circle" severity="success" :outlined="primaryActionName !== 'delivered'" :disabled="actionLoading" @click="confirmAction('delivered')" />
                                <Button v-if="fulfillment.actions?.cancel" type="button" label="Cancelar pedido" icon="pi pi-ban" severity="danger" outlined :disabled="actionLoading" @click="confirmAction('cancel')" />
                            </div>
                            <p v-if="actionLoading" class="mt-3 text-sm text-blue-800" role="status" aria-live="polite">Procesando la acción…</p>
                        </section>
                        <OrderDeliveryPanel v-if="fulfillment.actions?.manage_delivery || (selectedOrder.delivery_type !== 'pickup' && (fulfillment.status === 'ready' || fulfillment.status === 'delivered'))" :order-id="selectedOrder.id" :initial="delivery" @updated="deliveryUpdated" />

                        <section aria-labelledby="order-history-title" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h3 id="order-history-title" class="font-bold text-slate-900">Historial operativo <span class="text-sm font-normal text-slate-500">({{ fulfillment.history?.length || 0 }})</span></h3>
                            <p v-if="!fulfillment.history?.length" class="mt-2 text-sm text-slate-600">Sin transiciones registradas.</p>
                            <ol v-else class="mt-3 space-y-3">
                                <li v-for="entry in fulfillment.history" :key="entry.id" class="border-l-2 border-blue-300 py-1 pl-3">
                                    <p class="font-semibold text-slate-900">{{ stateLabel(entry.from_status) }} → {{ stateLabel(entry.to_status) }}</p>
                                    <p class="text-xs text-slate-600">{{ formatDate(entry.created_at) }} · {{ entry.user?.name || 'Sistema' }}</p>
                                    <p v-if="entry.observation" class="mt-1 text-sm text-slate-700">{{ entry.observation }}</p>
                                </li>
                            </ol>
                        </section>
                    </template>
                </div>
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
import Button from 'primevue/button';
import Tag from 'primevue/tag';
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
    { key: 'customer_name', label: 'Cliente', filterKey: 'customer', filterMatchMode: 'contains', class: 'min-w-40' },
    { key: 'fulfillment_status', label: 'Estado operativo', filterKey: 'fulfillment_status', filterMatchMode: 'equals', class: 'whitespace-nowrap' },
    { key: 'payment_status', label: 'Estado de pago', filterKey: 'payment_status', filterMatchMode: 'equals', class: 'whitespace-nowrap' },
    { key: 'payment_method', label: 'Método de pago', filterKey: 'payment_method', filterMatchMode: 'equals', class: 'hidden xl:table-cell whitespace-nowrap', mobileDetail: true },
    { key: 'delivery_type', label: 'Entrega o recojo', filterKey: 'delivery_type', filterMatchMode: 'equals', class: 'hidden lg:table-cell min-w-44', mobileDetail: true },
    { key: 'warehouse_name', label: 'Almacén y reserva', filterKey: 'warehouse_id', filterMatchMode: 'equals', class: 'hidden xl:table-cell min-w-36', mobileDetail: true },
    { key: 'reservation_status', label: 'Estado de reserva', filterKey: 'reservation_status', filterMatchMode: 'equals', class: 'hidden xl:table-cell whitespace-nowrap', mobileDetail: true },
    { key: 'total', label: 'Importe', sortable: true, filterKey: 'total_range', filterMatchMode: 'between', class: 'min-w-32 text-right whitespace-nowrap', mobileDetail: true },
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
const activeFilterTags = computed(() => {
    const filters = list.filters;
    const tags = [
        ['search', 'Búsqueda', filters.search],
        ['order_id', 'Pedido', filters.order_id],
        ['customer', 'Cliente', filters.customer],
        ['fulfillment_status', 'Estado operativo', optionLabel(fulfillmentOptions, filters.fulfillment_status)],
        ['payment_status', 'Estado de pago', optionLabel(paymentStatusOptions, filters.payment_status)],
        ['payment_method', 'Método de pago', optionLabel(paymentMethodOptions, filters.payment_method)],
        ['delivery_type', 'Entrega', optionLabel(deliveryOptions, filters.delivery_type)],
        ['warehouse_id', 'Almacén', optionLabel(warehouseOptions.value, filters.warehouse_id)],
        ['reservation_status', 'Reserva', optionLabel(reservationOptions, filters.reservation_status)],
    ].filter(([, , value]) => value !== '');
    if (filters.total_min || filters.total_max) tags.push(['total_range', 'Importe', `${filters.total_min || '0'}–${filters.total_max || 'sin máximo'}`]);
    if (filters.date_from || filters.date_to) tags.push(['date_range', 'Fecha', `${filters.date_from || 'inicio'}–${filters.date_to || 'hoy'}`]);

    return tags.map(([key, label, value]) => ({ key, label, value }));
});
const pageSummary = computed(() => {
    const { from, to, total } = list.meta.value;

    return total ? `Mostrando ${from ?? 0}–${to ?? 0} de ${total} pedidos` : '0 pedidos';
});
function optionLabel(options, value) { return options.find((option) => option.value === value)?.label || value; }
function removeFilter(key) {
    if (key === 'total_range') {
        list.filters.total_min = '';
        list.filters.total_max = '';
    } else if (key === 'date_range') {
        list.filters.date_from = '';
        list.filters.date_to = '';
    } else {
        list.filters[key] = '';
    }
    return list.applyFilters();
}
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
const orderDialog = ref(null);
const manageTrigger = ref(null);
const fulfillment = ref({ actions: {} });
const handling = ref(null);
const delivery = ref(null);
const detailLoading = ref(false);
const detailError = ref('');
const actionLoading = ref(false);
const openingOrderId = ref(null);
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
const activeIncidentCount = computed(() => (handling.value?.incidents || []).filter((incident) => incident.status === 'open').length);
const primaryActionName = computed(() => {
    const actions = fulfillment.value?.actions || {};
    const available = [
        ['approve', actions.approve_payment && !selectedOrder.value?.has_payment_submission],
        ['start', actions.start_preparation],
        ['ready', actions.mark_ready],
        ['picked', actions.mark_picked_up],
        ['delivered', actions.mark_delivered],
    ];

    return available.find(([, enabled]) => enabled)?.[0] || null;
});
const operationalStages = computed(() => {
    const current = fulfillment.value || {};
    const order = selectedOrder.value || {};
    const process = handling.value || {};
    const deliveryStatus = delivery.value?.delivery?.status;
    const isCanceled = current.status === 'canceled';
    const isFinal = current.status === 'delivered';
    const operationState = (operation) => operation?.status === 'completed'
        ? 'completed'
        : operation?.status === 'in_progress' ? 'in_progress' : 'pending';

    return [
        {
            key: 'reservation', label: 'Reserva y pago',
            detail: `Reserva ${reservationLabel(current.reservation_status).toLowerCase()} · pago ${paymentStatusLabel(order.payment_status).toLowerCase()}`,
            state: isCanceled ? 'canceled' : ['active', 'consumed'].includes(current.reservation_status) ? 'completed' : 'pending',
        },
        {
            key: 'preparation', label: 'Preparación',
            detail: stepText(current.preparing_at, current.prepared_by),
            state: isCanceled ? 'canceled' : ['ready', 'delivered'].includes(current.status) ? 'completed' : current.status === 'preparing' ? 'in_progress' : 'pending',
        },
        {
            key: 'picking', label: 'Picking',
            detail: process.picking?.completed_at ? `Completado ${formatDate(process.picking.completed_at)}` : process.picking?.started_at ? `En curso desde ${formatDate(process.picking.started_at)}` : process.legacy ? 'Registro no requerido para este pedido' : 'Sin iniciar',
            state: isCanceled ? 'canceled' : process.legacy || process.available === false ? 'blocked' : activeIncidentCount.value ? 'incident' : operationState(process.picking),
        },
        {
            key: 'packing', label: 'Packing',
            detail: process.packing?.completed_at ? `Completado ${formatDate(process.packing.completed_at)}` : process.packing?.started_at ? `En curso desde ${formatDate(process.packing.started_at)}` : process.legacy ? 'Registro no requerido para este pedido' : 'Sin iniciar',
            state: isCanceled ? 'canceled' : process.legacy || process.available === false ? 'blocked' : activeIncidentCount.value ? 'incident' : operationState(process.packing),
        },
        {
            key: 'ready', label: order.delivery_type === 'pickup' ? 'Listo para recojo' : 'Listo',
            detail: stepText(current.ready_for_pickup_at || current.ready_at, current.ready_by),
            state: isCanceled ? 'canceled' : current.ready_for_pickup_at || current.ready_at || isFinal ? 'completed' : current.status === 'preparing' ? 'in_progress' : 'pending',
        },
        {
            key: 'completion', label: order.delivery_type === 'pickup' ? 'Recojo' : 'Entrega',
            detail: stepText(current.picked_up_at || current.delivered_at || delivery.value?.delivery?.delivered_at, current.delivered_by),
            state: isCanceled ? 'canceled' : isFinal || ['delivered', 'picked_up'].includes(deliveryStatus) ? 'completed' : ['out_for_delivery', 'awaiting_pickup'].includes(deliveryStatus) ? 'in_progress' : 'pending',
        },
    ];
});

function stateLabel(status, order) { return ({ reserved: 'Reservado', preparing: 'En preparación', ready: order?.delivery_type === 'pickup' ? 'Listo para recoger' : 'Listo para entrega', delivered: order?.delivery_type === 'pickup' ? 'Recogido' : 'Entregado', canceled: 'Cancelado' })[status] || 'Estado no disponible'; }
function stateSeverity(status) { return ({ reserved: 'info', preparing: 'info', ready: 'info', delivered: 'success', canceled: 'secondary' })[status] || 'secondary'; }
function stateClass(status) { return ({ preparing: 'order-state--preparing', ready: 'order-state--ready', canceled: 'order-state--canceled' })[status] || ''; }
function paymentStatusLabel(status) { return ({ pending: 'Pendiente', approved: 'Aprobado', rejected: 'Rechazado', refund_pending: 'Reembolso pendiente', refunded: 'Reembolsado' })[status] || 'Estado no disponible'; }
function paymentStatusSeverity(status) { return ({ pending: 'warn', approved: 'success', rejected: 'danger', refund_pending: 'warn', refunded: 'secondary' })[status] || 'secondary'; }
function paymentMethodLabel(method) { return ({ card: 'Tarjeta', transferencia: 'Transferencia bancaria', contra_entrega: 'Pago contraentrega', pago_en_sede: 'Pago en sede', mercadopago: 'Mercado Pago' })[method] || 'Método no disponible'; }
function reservationLabel(status) { return ({ active: 'Activa', expired: 'Expirada', consumed: 'Consumida', released: 'Liberada' })[status] || (status ? 'Estado no disponible' : 'Histórica'); }
function reservationSeverity(status) { return ({ active: 'info', expired: 'warn', consumed: 'success', released: 'secondary' })[status] || 'secondary'; }
function moneyLabel(value) { const amount = Number(value); return Number.isFinite(amount) ? new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(amount) : 'S/ —'; }
function deliveryLabel(order) { return order.delivery_type === 'pickup' ? `Recojo en sede · ${order.pickup_branch_name_snapshot || 'Sede histórica'}` : 'Envío a domicilio'; }
function stageLabel(state) { return ({ completed: 'Completado', in_progress: 'En proceso', pending: 'Pendiente', blocked: 'No aplica o no disponible', incident: 'Con incidencia', canceled: 'Cancelado' })[state] || 'Estado no disponible'; }
function stageClass(state) { return ({ completed: 'stage--completed', in_progress: 'stage--progress', pending: 'stage--pending', blocked: 'stage--blocked', incident: 'stage--incident', canceled: 'stage--canceled' })[state] || 'stage--pending'; }
function stageIcon(state) { return ({ completed: 'pi pi-check', in_progress: 'pi pi-spin pi-spinner', pending: 'pi pi-clock', blocked: 'pi pi-minus-circle', incident: 'pi pi-exclamation-triangle', canceled: 'pi pi-ban' })[state] || 'pi pi-circle'; }
const deadlineLabel = (status) => ({ preparing: 'En preparación', within_deadline: 'Dentro del plazo', expired: 'Recojo vencido · requiere revisión administrativa', picked_up: 'Recogido', not_applicable: 'No aplica' })[status] || status;
const paymentSubmissionLabel = (status) => ({ pending_review: 'Pendiente de Tesorería', observed: 'Observado por Tesorería', approved: 'Aprobado por Tesorería', rejected: 'Rechazado por Tesorería', expired: 'Presentación vencida', canceled: 'Presentación cancelada' })[status] || 'Pago gestionado por Tesorería';
const formatDate = formatDateTime;
const stepText = (date, user) => date ? `${formatDate(date)} · ${user?.name || 'Sistema'}` : 'Pendiente';

async function openOrder(order, event = null) {
    if (detailLoading.value && openingOrderId.value === order.id) return;
    const isOpeningDialog = selectedOrder.value === null;
    if (event?.currentTarget?.isConnected) manageTrigger.value = event.currentTarget;
    invalidateModalActions();
    const sequence = ++detailSequence;
    openingOrderId.value = order.id;
    selectedOrder.value = order;
    detailLoading.value = true;
    detailError.value = '';
    if (isOpeningDialog) {
        await nextTick();
        orderDialog.value?.focus();
    }
    try {
        const { data } = await api.get(`/admin/orders/${order.id}/fulfillment`);
        if (sequence !== detailSequence) return;
        selectedOrder.value = {
            ...data.order,
            total: order.total ?? selectedOrder.value?.total,
            customer_name: order.customer_name ?? selectedOrder.value?.customer_name,
        };
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
        if (sequence === detailSequence) {
            detailLoading.value = false;
            openingOrderId.value = null;
        }
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
function trapModalFocus(event) {
    const dialog = orderDialog.value;
    if (!dialog) return;
    const focusable = [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true' && element.getClientRects().length > 0);
    if (!focusable.length) {
        event.preventDefault();
        dialog.focus();
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || !dialog.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
        event.preventDefault();
        first.focus();
    }
}
async function closeModal() {
    invalidateModalActions();
    detailSequence++;
    openingOrderId.value = null;
    detailLoading.value = false;
    selectedOrder.value = null;
    fulfillment.value = { actions: {} };
    handling.value = null;
    delivery.value = null;
    detailError.value = '';
    await nextTick();
    if (componentMounted && manageTrigger.value?.isConnected && manageTrigger.value.getClientRects().length > 0) {
        manageTrigger.value.focus();
    }
}
onBeforeUnmount(() => {
    componentMounted = false;
    void closeMobileFilters({ restoreFocus: false });
    invalidateModalActions();
    detailSequence++;
    activeActionsByOrder.clear();
});
function handlingUpdated(summary) { handling.value = summary; fulfillment.value.picking_packing = summary; fulfillment.value.actions.mark_ready = Boolean(summary.actions?.mark_ready); }
async function deliveryUpdated(value) { delivery.value = value; if (value.order?.fulfillment_status === 'delivered' && selectedOrder.value) await openOrder({ ...selectedOrder.value, delivery_type: 'delivery' }); await list.load(); }
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

<style scoped>
.order-state--preparing {
    background: #ede9fe;
    color: #5b21b6;
}

.order-state--ready {
    background: #ccfbf1;
    color: #0f766e;
}

.order-state--canceled {
    background: #e2e8f0;
    color: #334155;
}

.order-dialog:focus {
    outline: 3px solid #2563eb;
    outline-offset: 3px;
}

.stage--completed {
    border-color: #a7f3d0;
    background: #ecfdf5;
    color: #065f46;
}

.stage--progress {
    border-color: #bfdbfe;
    background: #eff6ff;
    color: #1e40af;
}

.stage--pending {
    border-color: #e2e8f0;
    background: #f8fafc;
    color: #475569;
}

.stage--blocked {
    border-color: #cbd5e1;
    background: #f1f5f9;
    color: #64748b;
}

.stage--incident {
    border-color: #fdba74;
    background: #fff7ed;
    color: #9a3412;
}

.stage--canceled {
    border-color: #fecaca;
    background: #fff1f2;
    color: #9f1239;
}

@media (prefers-reduced-motion: reduce) {
    :deep(.pi-spin) {
        animation: none;
    }
}
</style>
