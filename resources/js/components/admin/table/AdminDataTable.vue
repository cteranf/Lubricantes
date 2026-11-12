<template>
    <div class="orders-data-table overflow-hidden rounded-lg bg-white shadow" :aria-busy="loading">
        <div v-if="error" class="p-6 text-center text-red-700" role="alert">
            <slot name="error">No se pudieron cargar los registros.</slot>
            <button type="button" class="ml-2 font-semibold underline" @click="$emit('retry')">Reintentar</button>
        </div>
        <div v-else-if="!rowIdentityContract.valid" class="p-6 text-center text-red-700" role="alert">
            No se pudo mostrar la tabla por una clave de fila inválida o duplicada.
        </div>

        <div v-if="!error && rowIdentityContract.valid" class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
            <Button type="button" label="Limpiar filtros" icon="pi pi-filter-slash" severity="secondary" size="small" @click="clearFilters" />
            <IconField class="w-full sm:max-w-xs">
                <InputIcon class="pi pi-search" />
                <InputText v-model="tableFilters.global.value" class="w-full" :placeholder="searchPlaceholder" :aria-label="searchPlaceholder" @input="emitFilters" />
            </IconField>
        </div>

        <DataTable
            v-if="!error && rowIdentityContract.valid"
            :value="validatedRows"
            :data-key="rowIdentityContract.rowKey"
            v-model:filters="tableFilters"
            filter-display="menu"
            :global-filter-fields="globalFilterFields"
            :lazy="true"
            :paginator="true"
            :first="first"
            :rows="pageSize"
            :total-records="totalRecords"
            :rows-per-page-options="pageSizeOptions"
            :loading="loading"
            :row-hover="rowHover"
            :sort-field="sort.column || undefined"
            :sort-order="sort.direction === 'asc' ? 1 : -1"
            sort-mode="single"
            :striped-rows="true"
            :scrollable="true"
            size="small"
            paginator-template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
            :current-page-report-template="currentPageReportTemplate"
            v-model:expandedRows="expandedRows"
            @page="onPage"
            @sort="onSort"
            @filter="emitFilters"
        >
            <template #empty>
                <div class="p-6 text-center text-slate-500"><slot name="empty">No hay registros para mostrar.</slot></div>
            </template>
            <template #loading>
                <span class="sr-only">{{ loadingMessage }}</span>
            </template>
            <template #expansion="{ data }">
                <div class="grid grid-cols-1 gap-3 bg-slate-50 p-4 text-sm sm:grid-cols-2" :aria-label="mobileDetailsLabel.replace('{id}', String(data.id))">
                    <div v-for="column in detailColumns" :key="column.key">
                        <div class="text-xs font-medium text-slate-500">{{ column.label }}</div>
                        <slot :name="`cell-${column.key}`" :row="data" :value="data[column.key]">{{ data[column.key] ?? '—' }}</slot>
                    </div>
                </div>
            </template>

            <Column expander class="orders-mobile-expander md:hidden" header="" />
            <Column
                v-for="column in columns"
                :key="column.key"
                :field="column.key"
                :header="column.label"
                :sortable="column.sortable === true"
                :class="column.class || ''"
                :header-class="column.class || ''"
                :body-class="column.class || ''"
                :filter="Boolean(column.filterKey)"
                :filter-field="column.filterKey || column.key"
                :show-filter-match-modes="false"
                :show-filter-operator="false"
                :show-add-button="false"
                :max-constraints="1"
            >
                <template v-if="column.filterKey" #filter="slotProps">
                    <slot :name="`filter-${column.key}`" v-bind="slotProps" />
                </template>
                <template #body="{ data }">
                    <slot :name="`cell-${column.key}`" :row="data" :value="data[column.key]">{{ data[column.key] ?? '—' }}</slot>
                </template>
            </Column>
            <Column
                v-if="$slots.actions"
                header="Acciones"
                class="actions-column min-w-24 whitespace-nowrap"
                :frozen="actionsFrozen"
                :align-frozen="actionsFrozen ? 'right' : undefined"
            >
                <template #body="{ data }"><slot name="actions" :row="data" /></template>
            </Column>
        </DataTable>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    columns: { type: Array, required: true },
    filterValues: { type: Object, default: () => ({}) },
    rowKey: { type: String, required: true, validator: (value) => typeof value === 'string' && value.trim() !== '' },
    page: { type: Number, required: true },
    pageSize: { type: Number, required: true },
    totalRecords: { type: Number, required: true },
    loading: Boolean,
    rowHover: Boolean,
    actionsFrozen: Boolean,
    error: { type: [Object, String, Boolean], default: null },
    sort: { type: Object, default: () => ({ column: null, direction: 'desc' }) },
    searchPlaceholder: { type: String, default: 'Buscar pedido o cliente' },
    currentPageReportTemplate: { type: String, default: 'Mostrando {first} a {last} de {totalRecords} pedidos' },
    loadingMessage: { type: String, default: 'Cargando pedidos…' },
    mobileDetailsLabel: { type: String, default: 'Detalle del pedido {id}' },
    globalFilterFields: { type: Array, default: () => ['id', 'customer_name'] },
});
const emit = defineEmits(['retry', 'page', 'per-page', 'sort', 'filters-change', 'clear-filters']);
const pageSizeOptions = [15, 25, 50, 100];
const first = computed(() => Math.max(0, (props.page - 1) * props.pageSize));
const detailColumns = computed(() => props.columns.filter((column) => column.mobileDetail === true));
const rowIdentityContract = computed(() => {
    const rowKey = props.rowKey;
    if (typeof rowKey !== 'string') {
        return { valid: false, rowKey: null, tokens: [] };
    }

    const canonicalRowKey = rowKey.trim();
    if (!canonicalRowKey || canonicalRowKey !== rowKey) {
        return { valid: false, rowKey: null, tokens: [] };
    }

    const tokens = [];
    const seen = new Set();

    for (const row of props.rows) {
        if (row === null || typeof row !== 'object' || Array.isArray(row)) {
            return { valid: false, tokens: [] };
        }

        const value = row[canonicalRowKey];
        if (value === null || value === undefined) {
            return { valid: false, tokens: [] };
        }

        const validString = typeof value === 'string' && value.trim() !== '';
        const validNumber = typeof value === 'number' && Number.isFinite(value);
        if (!validString && !validNumber) {
            return { valid: false, tokens: [] };
        }

        const token = String(value);
        if (seen.has(token)) {
            return { valid: false, tokens: [] };
        }

        seen.add(token);
        tokens.push(token);
    }

    return { valid: true, rowKey: canonicalRowKey, tokens };
});
const tableFilters = ref({
    global: { value: props.filterValues.search || '', matchMode: 'contains' },
    ...Object.fromEntries(props.columns.filter((column) => column.filterKey).map((column) => [
        column.filterKey,
        { value: initialFilterValue(column, props.filterValues), matchMode: column.filterMatchMode || 'contains' },
    ])),
});
const validatedRows = computed(() => rowIdentityContract.value.valid ? props.rows : []);
const expandedRowState = ref({});
const expandedRows = computed({
    get: () => expandedRowState.value,
    set: (value) => { expandedRowState.value = pruneExpandedRows(value); },
});

function pruneExpandedRows(value = expandedRows.value) {
    const visibleTokens = new Set(rowIdentityContract.value.valid ? rowIdentityContract.value.tokens : []);

    return Object.fromEntries(Object.entries(value || {}).filter(([token, expanded]) => expanded && visibleTokens.has(token)));
}

watch(() => rowIdentityContract.value.tokens, () => {
    expandedRowState.value = pruneExpandedRows();
}, { immediate: true });

watch(() => props.filterValues, (values) => {
    tableFilters.value.global.value = values.search || '';
    for (const column of props.columns) {
        if (column.filterKey && tableFilters.value[column.filterKey]) {
            tableFilters.value[column.filterKey].value = initialFilterValue(column, values);
        }
    }
}, { deep: true });

function initialFilterValue(column, values) {
    if (column.filterKey === 'total_range') {
        const range = [values.total_min || null, values.total_max || null];
        return range.some(Boolean) ? range : null;
    }
    if (column.filterKey === 'date_range') {
        const parse = (date) => {
            if (typeof date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
            const [year, month, day] = date.split('-').map(Number);
            return new Date(year, month - 1, day);
        };
        const range = [parse(values.date_from), parse(values.date_to)];
        return range.some(Boolean) ? range : null;
    }

    return values[column.filterKey] || null;
}

function filterValuesFromTable() {
    const values = { search: tableFilters.value.global?.value || '' };
    for (const column of props.columns) {
        if (!column.filterKey) continue;
        const value = tableFilters.value[column.filterKey]?.value;
        if (column.filterKey === 'total_range') {
            values.total_min = value?.[0] || '';
            values.total_max = value?.[1] || '';
        } else if (column.filterKey === 'date_range') {
            values.date_from = toCivilDate(value?.[0]);
            values.date_to = toCivilDate(value?.[1]);
        } else {
            values[column.filterKey] = value ?? '';
        }
    }
    return values;
}

function toCivilDate(value) {
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) return '';
    return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
}

function emitFilters() {
    emit('filters-change', filterValuesFromTable());
}

function clearFilters() {
    tableFilters.value = {
        global: { value: '', matchMode: 'contains' },
        ...Object.fromEntries(props.columns.filter((column) => column.filterKey).map((column) => [
            column.filterKey,
            { value: null, matchMode: column.filterMatchMode || 'contains' },
        ])),
    };
    emit('clear-filters');
}

function onPage(event) {
    if (event.rows !== props.pageSize) {
        emit('per-page', event.rows);
        return;
    }

    emit('page', event.page + 1);
}

function onSort(event) {
    if (typeof event.sortField !== 'string' || !event.sortField) return;
    emit('sort', event.sortField, event.sortOrder === 1 ? 'asc' : 'desc');
}
</script>

<style scoped>
.orders-data-table {
    --p-datatable-header-cell-background: #f1f5f9;
    --p-datatable-header-cell-color: #334155;
    --p-datatable-row-striped-background: #f8fafc;
    --p-primary-color: #1d4ed8;
    --p-primary-contrast-color: #fff;
}

.orders-data-table :deep(.p-datatable-table-container) {
    max-width: 100%;
    overflow-x: auto;
}

.orders-data-table :deep(.p-paginator) {
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.25rem;
}

@media (max-width: 639px) {
    .orders-data-table :deep(.p-paginator-current) {
        flex-basis: 100%;
        order: 2;
        text-align: center;
    }
}
</style>
