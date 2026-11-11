<template>
    <AdminLayout>
        <div class="container mx-auto space-y-5 px-4 py-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h1 class="text-3xl font-medium text-slate-700">Gestión de productos</h1>
                <button type="button" class="min-h-11 rounded bg-blue-700 px-4 py-2 font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-800" @click="openModal()">Nuevo producto</button>
            </div>

            <section class="space-y-3 rounded-lg bg-white p-3 shadow" aria-label="Listado de productos">
                <div class="lg:hidden">
                    <button type="button" class="min-h-11 w-full rounded border border-slate-300 px-4 text-left font-semibold" :aria-expanded="filtersOpen" aria-controls="products-filters" @click="filtersOpen = !filtersOpen">Filtros <span v-if="activeFilterCount">({{ activeFilterCount }} activos)</span></button>
                </div>
                <div id="products-filters" class="grid grid-cols-1 gap-3 rounded bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4" :class="filtersOpen ? '' : 'hidden lg:grid'" aria-label="Filtros de productos">
                    <label class="text-sm">Nombre
                        <input :value="list.filters.name" type="search" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" @input="list.setFilter('name', $event.target.value, true)">
                    </label>
                    <label class="text-sm">SKU / código
                        <input :value="list.filters.sku" type="search" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" @input="list.setFilter('sku', $event.target.value, true)">
                    </label>
                    <label class="text-sm">Categoría
                        <select :value="list.filters.category_id" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2" @change="list.setFilter('category_id', $event.target.value, false)">
                            <option value="">Todas</option><option v-for="item in categoryOptions" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">Marca
                        <select :value="list.filters.brand_id" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2" @change="list.setFilter('brand_id', $event.target.value, false)">
                            <option value="">Todas</option><option v-for="item in brandOptions" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">Estado
                        <select :value="list.filters.is_active" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2" @change="list.setFilter('is_active', $event.target.value, false)">
                            <option value="">Todos</option><option value="1">Activo</option><option value="0">Inactivo</option>
                        </select>
                    </label>
                    <label class="text-sm">Precio mínimo
                        <input :value="list.filters.price_min" type="text" inputmode="decimal" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" placeholder="0.00" @change="list.setFilter('price_min', $event.target.value, false)">
                    </label>
                    <label class="text-sm">Precio máximo
                        <input :value="list.filters.price_max" type="text" inputmode="decimal" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" placeholder="0.00" @change="list.setFilter('price_max', $event.target.value, false)">
                    </label>
                    <div class="flex items-end gap-2"><button type="button" class="min-h-10 rounded border border-slate-400 px-3" @click="clearAllFilters">Limpiar filtros</button></div>
                </div>

                <AdminDataTable
                    :rows="list.rows.value"
                    :columns="columns"
                    :filter-values="list.filters"
                    row-key="id"
                    :page="list.meta.value.current_page"
                    :page-size="list.meta.value.per_page"
                    :total-records="list.meta.value.total"
                    :loading="list.loading.value"
                    :error="list.error.value"
                    :sort="{ column: list.sort.value, direction: list.direction.value }"
                    search-placeholder="Buscar productos por nombre o SKU"
                    current-page-report-template="Mostrando {first} a {last} de {totalRecords} productos"
                    loading-message="Cargando productos…"
                    mobile-details-label="Detalles del producto {id}"
                    :global-filter-fields="['name', 'sku']"
                    @retry="list.retry"
                    @page="list.setPage"
                    @per-page="list.setPerPage"
                    @sort="list.setSort"
                    @filters-change="list.setFilters"
                    @clear-filters="clearAllFilters"
                >
                    <template #empty>{{ hasActiveFilters ? 'No se encontraron productos con los filtros aplicados.' : 'No hay productos para mostrar.' }}</template>
                    <template #cell-name="{ row }">
                        <div class="flex min-w-48 items-center gap-3">
                            <img v-if="row.image_url && !failedImages.has(row.id)" :src="row.image_url" alt="" loading="lazy" class="h-10 w-10 rounded object-cover" @error="failedImages.add(row.id)">
                            <span v-else class="flex h-10 w-10 items-center justify-center rounded bg-slate-100 text-slate-500" aria-label="Sin imagen"><i class="pi pi-image" aria-hidden="true" /></span>
                            <span class="font-medium">{{ row.name }}</span>
                        </div>
                    </template>
                    <template #cell-sku="{ row }"><span class="font-mono text-sm">{{ row.sku || '—' }}</span></template>
                    <template #cell-category="{ row }">{{ row.category?.name || 'Sin categoría' }}</template>
                    <template #cell-brand="{ row }">{{ row.brand?.name || 'Sin marca' }}</template>
                    <template #cell-price="{ row }"><span class="whitespace-nowrap">S/ {{ row.price }}</span></template>
                    <template #cell-physical_stock="{ row }"><span>{{ row.physical_stock }}</span><small class="block text-slate-500">stock físico total</small></template>
                    <template #cell-is_active="{ row }"><span :class="row.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-700'" class="rounded-full px-2 py-1 text-xs">{{ row.is_active ? 'Activo' : 'Inactivo' }}</span></template>
                    <template #actions="{ row }">
                        <div class="flex items-center gap-3">
                            <button type="button" class="font-semibold text-blue-700 underline" :aria-label="`Editar ${row.name}`" @click="openModal(row)">Editar</button>
                            <button type="button" class="font-semibold underline" :class="row.is_active ? 'text-red-700' : 'text-green-700'" :disabled="statusBusy.has(row.id)" @click="confirmStatus(row)">{{ row.is_active ? 'Desactivar' : 'Activar' }}</button>
                        </div>
                    </template>
                </AdminDataTable>
            </section>

            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4" @keydown.esc="closeModal">
                <div class="mx-auto my-8 w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="product-dialog-title">
                    <h2 id="product-dialog-title" class="mb-4 text-xl font-bold">{{ isEditing ? 'Editar producto' : 'Nuevo producto' }}</h2>
                    <form class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="submitForm">
                        <Field label="Nombre" :error="fieldError('name')" class="md:col-span-2"><input v-model="form.name" class="input" required></Field>
                        <Field label="SKU interno" :error="fieldError('sku')"><input v-model="form.sku" class="input uppercase" placeholder="Se genera automáticamente"></Field>
                        <Field label="Precio" :error="fieldError('price')"><input v-model="form.price" type="number" min="0" step="0.01" class="input" required></Field>
                        <Field v-if="!isEditing" label="Cantidad inicial" :error="fieldError('cantidad_inicial')"><input v-model.number="form.cantidad_inicial" type="number" min="0" class="input" required></Field>
                        <Field v-if="!isEditing" label="Almacén inicial" :error="fieldError('warehouse_id')"><select v-model="form.warehouse_id" :required="form.cantidad_inicial > 0" class="input"><option value="">Seleccione un almacén</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} — {{ warehouse.branch?.name }}</option></select><small class="text-slate-500">Los cambios posteriores se realizan desde Inventario.</small></Field>
                        <Field label="Categoría" :error="fieldError('category_id')"><select v-model="form.category_id" class="input" :disabled="optionsLoading"><option value="">Sin categoría</option><option v-for="item in categoryOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select><router-link to="/admin/categories" class="text-xs text-blue-700">Administrar categorías</router-link></Field>
                        <Field label="Marca" :error="fieldError('brand_id')"><select v-model="form.brand_id" class="input" :disabled="optionsLoading"><option value="">Sin marca</option><option v-for="item in brandOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select><router-link to="/admin/brands" class="text-xs text-blue-700">Administrar marcas</router-link></Field>
                        <div v-if="optionsError" class="text-sm text-red-700 md:col-span-2" role="alert">{{ optionsError }} <button type="button" class="underline" :disabled="optionsLoading" @click="retryOptions">Reintentar</button></div>
                        <Field label="Imagen" :error="fieldError('image')" class="md:col-span-2"><input type="file" accept="image/*" class="w-full text-sm" @change="form.image = $event.target.files?.[0] || null"><small v-if="isEditing" class="text-slate-500">Deje vacío para conservar la imagen actual.</small></Field>
                        <Field label="Descripción" :error="fieldError('description')" class="md:col-span-2"><textarea v-model="form.description" class="input" /></Field>
                        <div class="flex justify-end gap-3 md:col-span-2"><button type="button" class="rounded bg-slate-200 px-4 py-2" @click="closeModal">Cancelar</button><button type="submit" class="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50" :disabled="processing || optionsLoading">{{ processing ? 'Guardando…' : 'Guardar' }}</button></div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminDataTable from '@/components/admin/table/AdminDataTable.vue';
import api from '@/api';
import { useAdminListQuery } from '@/composables/useAdminListQuery';
import { useRoute, useRouter } from 'vue-router';

const Field = defineComponent({
    props: { label: String, error: String },
    setup(props, { slots, attrs }) {
        return () => h('div', attrs, [h('label', { class: 'mb-2 block text-sm font-bold' }, props.label), slots.default?.(), props.error ? h('p', { class: 'mt-1 text-xs text-red-700', role: 'alert' }, props.error) : null]);
    },
});

const toast = useToast();
const confirm = useConfirm();
const route = useRoute();
const router = useRouter();
const filterKeys = ['search', 'sku', 'name', 'category_id', 'brand_id', 'is_active', 'price_min', 'price_max'];
const list = useAdminListQuery({
    route,
    router,
    filters: filterKeys,
    sorts: ['created_at', 'id', 'name', 'sku', 'price', 'is_active'],
    fetchPage: async (params) => (await api.get('/admin/products', { params })).data,
});
const columns = [
    { key: 'name', label: 'Producto', sortable: true, mobileDetail: true, class: 'min-w-52' },
    { key: 'sku', label: 'SKU', sortable: true, mobileDetail: true },
    { key: 'category', label: 'Categoría', mobileDetail: true, class: 'hidden md:table-cell' },
    { key: 'brand', label: 'Marca', mobileDetail: true, class: 'hidden md:table-cell' },
    { key: 'price', label: 'Precio', sortable: true, mobileDetail: true },
    { key: 'physical_stock', label: 'Stock físico', mobileDetail: true },
    { key: 'is_active', label: 'Estado', sortable: true, mobileDetail: true },
];
const warehouses = ref([]);
const categoryOptions = ref([]);
const brandOptions = ref([]);
const showModal = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const optionsLoading = ref(false);
const optionsError = ref('');
const optionsLoaded = ref(false);
const errors = ref({});
const form = ref({});
const selectedProduct = ref(null);
const filtersOpen = ref(false);
const failedImages = reactive(new Set());
const statusBusy = reactive(new Set());
let openingSequence = 0;
let submissionSequence = 0;
let optionsPromise = null;
let isComponentMounted = false;
const hasActiveFilters = computed(() => filterKeys.some((key) => list.filters[key] !== ''));
const activeFilterCount = computed(() => filterKeys.filter((key) => list.filters[key] !== '').length);
const listErrorMessage = computed(() => safeRequestMessage(list.error.value));

function statusOf(error) {
    const status = error?.response?.status;
    return Number.isInteger(status) ? status : 0;
}

function safeRequestMessage(error) {
    const status = statusOf(error);
    if (status === 401) return 'Tu sesión ha expirado. Inicia sesión nuevamente.';
    if (status === 403) return 'No tienes permisos para ver los productos.';
    if (status === 404) return 'El listado solicitado ya no está disponible.';
    if (status === 422) return 'Algunos filtros no son válidos. Revísalos e inténtalo nuevamente.';
    if (status === 429) return 'Se realizaron demasiadas solicitudes. Espera un momento.';
    if (status >= 500 && status <= 599) return 'Ocurrió un problema interno. Inténtalo nuevamente.';
    return status ? 'No se pudo completar la solicitud.' : 'No se pudo conectar. Verifica tu conexión e inténtalo nuevamente.';
}

function fieldError(key) {
    return errors.value[key] ? 'Revisa este campo e inténtalo nuevamente.' : '';
}

function validId(value) {
    return (typeof value === 'number' && Number.isSafeInteger(value) && value > 0)
        || (typeof value === 'string' && /^[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value)));
}

function preferredWarehouse() {
    return warehouses.value.find((warehouse) => warehouse.code === 'ALM-PRINCIPAL')?.id
        || warehouses.value.find((warehouse) => warehouse.is_default)?.id
        || warehouses.value[0]?.id || '';
}

function preserveAssignedOption(options, relation, id) {
    if (id && !options.some((item) => Number(item.id) === Number(id)) && relation?.name) {
        options.push({ id, name: `${relation.name} (inactiva)` });
    }
}

function loadOptions() {
    if (optionsLoaded.value) return Promise.resolve({ ok: true });
    if (optionsPromise) return optionsPromise;

    optionsLoading.value = true;
    optionsError.value = '';

    const request = Promise.all([
            api.get('/admin/warehouses/options'), api.get('/admin/categories/options'), api.get('/admin/brands/options'),
        ])
        .then(([warehouseResponse, categoryResponse, brandResponse]) => {
            if (isComponentMounted) {
                warehouses.value = warehouseResponse.data;
                categoryOptions.value = categoryResponse.data;
                brandOptions.value = brandResponse.data;
                optionsLoaded.value = true;
            }

            return { ok: true };
        })
        .catch((error) => ({ ok: false, message: safeRequestMessage(error) }))
        .finally(() => {
            if (optionsPromise === request) {
                optionsPromise = null;
                if (isComponentMounted) optionsLoading.value = false;
            }
        });

    optionsPromise = request;

    return request;
}

async function openModal(product = null) {
    const sequence = ++openingSequence;
    const mode = product ? 'edit' : 'create';
    const productId = product?.id ?? null;

    if (product && !validId(product.id)) {
        toast.add({ severity: 'error', summary: 'Producto inválido', detail: 'No se puede abrir la edición del producto.', life: 3000 });
        return;
    }

    errors.value = {};
    isEditing.value = Boolean(product);
    selectedProduct.value = product;

    if (product) {
        preserveAssignedOption(categoryOptions.value, product.category, product.category_id);
        preserveAssignedOption(brandOptions.value, product.brand, product.brand_id);
        form.value = { id: product.id, name: product.name, sku: product.sku || '', price: product.price, category_id: product.category?.id || '', brand_id: product.brand?.id || '', description: product.description ?? '', image: null };
    } else {
        form.value = { name: '', sku: '', price: 0, cantidad_inicial: 0, warehouse_id: preferredWarehouse(), category_id: '', brand_id: '', description: '', image: null };
    }
    showModal.value = true;

    const optionsResult = await loadOptions();
    if (!isCurrentOpening(sequence, productId, mode)) return;

    optionsError.value = optionsResult.ok ? '' : optionsResult.message;
}

function isCurrentOpening(sequence, productId, mode) {
    return isComponentMounted
        && showModal.value
        && openingSequence === sequence
        && (mode === 'edit'
            ? isEditing.value && selectedProduct.value?.id === productId
            : !isEditing.value && selectedProduct.value === null);
}

async function retryOptions() {
    const sequence = openingSequence;
    const mode = isEditing.value ? 'edit' : 'create';
    const productId = selectedProduct.value?.id ?? null;
    optionsError.value = '';

    const optionsResult = await loadOptions();
    if (!isCurrentOpening(sequence, productId, mode)) return;

    optionsError.value = optionsResult.ok ? '' : optionsResult.message;
}

function closeModal() {
    openingSequence++;
    showModal.value = false;
    selectedProduct.value = null;
    errors.value = {};
}

async function submitForm() {
    if (processing.value) return;

    if (isEditing.value && !validId(form.value.id)) {
        toast.add({ severity: 'error', summary: 'Producto inválido', detail: 'No se envió la solicitud.', life: 3000 });
        return;
    }

    const submission = ++submissionSequence;
    const opening = openingSequence;
    const mode = isEditing.value ? 'edit' : 'create';
    const productId = selectedProduct.value?.id ?? null;
    const formState = { ...form.value };
    processing.value = true;
    errors.value = {};

    try {
        const data = new FormData();
        ['name', 'sku', 'price', 'category_id', 'brand_id', 'description'].forEach((key) => {
            if (formState[key] !== '' && formState[key] != null) data.append(key, formState[key]);
        });
        if (mode === 'create') {
            data.append('cantidad_inicial', formState.cantidad_inicial);
            if (formState.warehouse_id) data.append('warehouse_id', formState.warehouse_id);
        }
        if (formState.image) data.append('image', formState.image);
        if (mode === 'edit') {
            data.append('_method', 'PUT');
            await api.post(`/admin/products/${productId}`, data);
        } else {
            await api.post('/admin/products', data);
        }
        if (isComponentMounted) void list.load();
        if (isCurrentOpening(opening, productId, mode)) {
            closeModal();
            toast.add({ severity: 'success', summary: 'Guardado', detail: 'Producto guardado correctamente.', life: 2500 });
        }
    } catch (error) {
        if (isCurrentOpening(opening, productId, mode)) {
            errors.value = statusOf(error) === 422 && error?.response?.data?.errors && typeof error.response.data.errors === 'object' ? error.response.data.errors : {};
            toast.add({ severity: 'error', summary: 'No se pudo guardar', detail: safeRequestMessage(error), life: 4000 });
        }
    } finally {
        if (isComponentMounted && submissionSequence === submission) processing.value = false;
    }
}

function confirmStatus(product) {
    if (!validId(product?.id)) {
        toast.add({ severity: 'error', summary: 'Producto inválido', detail: 'No se envió la solicitud.', life: 3000 });
        return;
    }
    confirm.require({
        message: `¿Desea ${product.is_active ? 'desactivar' : 'activar'} ${product.name}?`,
        header: 'Confirmación', icon: 'pi pi-exclamation-triangle',
        accept: () => changeStatus(product),
    });
}

async function changeStatus(product) {
    if (statusBusy.has(product.id)) return;
    statusBusy.add(product.id);
    try {
        await api.patch(`/admin/products/${product.id}/status`, { is_active: !product.is_active });
        toast.add({ severity: 'success', summary: 'Estado actualizado', detail: 'El estado del producto fue actualizado.', life: 2500 });
        await list.load();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'No se pudo actualizar', detail: safeRequestMessage(error), life: 3500 });
    } finally {
        statusBusy.delete(product.id);
    }
}

function clearAllFilters() {
    list.clearFilters();
}

onMounted(() => {
    isComponentMounted = true;
    void loadOptions();
});
onBeforeUnmount(() => {
    isComponentMounted = false;
    openingSequence++;
    submissionSequence++;
});
</script>

<style scoped>
.input { width: 100%; border: 1px solid #cbd5e1; border-radius: .25rem; padding: .5rem .75rem; outline: none; }
.input:focus { box-shadow: 0 0 0 2px #1d4ed8; }
</style>
