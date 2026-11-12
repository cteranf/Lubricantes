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
                    <div class="flex items-end text-sm text-slate-500">Combina los filtros con la búsqueda para acotar el listado.</div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 px-1" aria-live="polite">
                    <p class="text-sm font-medium text-slate-600">{{ productPaginationSummary }}</p>
                    <p v-if="hasActiveFilters" class="text-xs text-slate-500">Filtros aplicados: {{ activeFilterCount }}</p>
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
                    :row-hover="true"
                    :actions-frozen="true"
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
                    <template #empty>
                        <div class="flex flex-col items-center gap-2 py-5 text-center">
                            <i class="pi pi-box text-2xl text-slate-400" aria-hidden="true" />
                            <p class="font-semibold text-slate-700">{{ hasActiveFilters ? 'No se encontraron productos con los filtros aplicados.' : 'No hay productos para mostrar.' }}</p>
                            <p class="text-sm text-slate-500">{{ hasActiveFilters ? 'Prueba ajustar o limpiar los filtros.' : 'Registra un producto para comenzar.' }}</p>
                        </div>
                    </template>
                    <template #cell-name="{ row }">
                        <div class="flex min-w-48 items-center gap-3">
                            <img v-if="row.image_url && !failedImages.has(row.id)" :src="row.image_url" :srcset="productImageSrcset(row, ['thumbnail', 'card'])" sizes="48px" :width="row.image_dimensions?.width" :height="row.image_dimensions?.height" alt="" loading="lazy" class="h-12 w-12 shrink-0 rounded-lg bg-slate-50 object-contain ring-1 ring-slate-200" @error="failedImages.add(row.id)">
                            <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 ring-1 ring-slate-200" aria-label="Sin imagen"><i class="pi pi-image" aria-hidden="true" /></span>
                            <span class="min-w-0 font-semibold text-slate-800">{{ row.name }}</span>
                        </div>
                    </template>
                    <template #cell-sku="{ row }"><span class="whitespace-nowrap font-mono text-xs tracking-tight text-slate-600">{{ row.sku || '—' }}</span></template>
                    <template #cell-category="{ row }">{{ row.category?.name || 'Sin categoría' }}</template>
                    <template #cell-brand="{ row }">{{ row.brand?.name || 'Sin marca' }}</template>
                    <template #cell-price="{ row }"><span class="block whitespace-nowrap text-right font-semibold tabular-nums text-slate-800">{{ formatProductPrice(row.price) }}</span></template>
                    <template #cell-physical_stock="{ row }">
                        <div class="flex min-w-28 flex-col items-start gap-1">
                            <span class="font-bold tabular-nums text-slate-800">{{ row.physical_stock }}</span>
                            <Tag :value="stockStatus(row.physical_stock).label" :severity="stockStatus(row.physical_stock).severity" />
                            <small class="text-slate-500">stock físico total</small>
                        </div>
                    </template>
                    <template #cell-is_active="{ row }"><Tag :value="row.is_active ? 'Activo' : 'Inactivo'" :severity="row.is_active ? 'success' : 'secondary'" /></template>
                    <template #actions="{ row }">
                        <div class="hidden items-center justify-end gap-2 md:flex">
                            <Button type="button" label="Editar" icon="pi pi-pencil" severity="secondary" outlined size="small" v-tooltip.top="'Editar producto'" :aria-label="`Editar ${row.name}`" @click="openModal(row)" />
                            <Button type="button" :label="row.is_active ? 'Desactivar' : 'Reactivar'" :icon="row.is_active ? 'pi pi-power-off' : 'pi pi-check-circle'" :severity="row.is_active ? 'warn' : 'success'" outlined size="small" v-tooltip.top="row.is_active ? 'Desactivar producto' : 'Reactivar producto'" :aria-label="`${row.is_active ? 'Desactivar' : 'Reactivar'} ${row.name}`" :disabled="statusBusy.has(row.id)" :loading="statusBusy.has(row.id)" @click="confirmStatus(row)" />
                        </div>
                        <Button type="button" icon="pi pi-ellipsis-v" text rounded severity="secondary" class="md:hidden" :aria-label="`Acciones para ${row.name}`" aria-haspopup="menu" v-tooltip.top="'Acciones del producto'" @click="openMobileProductActions($event, row)" />
                    </template>
                </AdminDataTable>
            </section>

            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4" @keydown.esc="closeModal">
                <div class="mx-auto my-4 max-h-[calc(100vh-2rem)] w-[95vw] max-w-[960px] overflow-y-auto rounded-lg bg-white p-4 shadow-xl sm:p-6" role="dialog" aria-modal="true" aria-labelledby="product-dialog-title">
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
                        <div class="md:col-span-2">
                            <section aria-labelledby="product-gallery-title" class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h3 id="product-gallery-title" class="font-semibold text-slate-800">Galería del producto</h3>
                                        <p class="mt-1 text-sm text-slate-600">Elige la fotografía principal; cada imagen se procesa por separado.</p>
                                    </div>
                                    <Tag :value="`${galleryImageCount} de 6`" :severity="galleryImageCount >= 6 ? 'warn' : 'secondary'" aria-live="polite" />
                                </div>
                                <p v-if="imageSelectionMessage" role="alert" aria-live="assertive" class="rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">{{ imageSelectionMessage }}</p>
                                <div v-if="selectedProduct?.images?.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    <article v-for="(image, index) in selectedProduct.images" :key="image.id" class="min-w-0 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                                        <div class="relative mb-3 flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-50">
                                            <Image v-if="image.url" :src="image.url" :alt="`Fotografía ${index + 1} de ${selectedProduct.name}`" preview image-class="h-full w-full object-contain" :image-style="{ objectFit: 'contain' }" class="h-full w-full" />
                                            <span v-else class="px-3 text-center text-sm text-slate-600" role="status">Sin imagen publicada</span>
                                            <Tag v-if="image.is_primary" value="Principal" severity="info" class="absolute left-2 top-2" />
                                        </div>
                                        <div class="mb-3 flex min-w-0 flex-wrap items-center justify-between gap-2">
                                            <span class="truncate text-sm font-semibold text-slate-800">Fotografía {{ index + 1 }}</span>
                                            <Tag :value="imageProcessingLabel(image.processing_status)" :severity="imageProcessingSeverity(image.processing_status)" />
                                        </div>
                                        <div class="flex flex-wrap gap-1 border-t border-slate-100 pt-2">
                                            <Button v-if="!image.is_primary && image.url" type="button" icon="pi pi-star" text rounded aria-label="Hacer principal" v-tooltip.top="'Hacer principal'" :disabled="imageSaved || imageStatusBusy" @click="setPrimaryImage(image)" />
                                            <Button type="button" icon="pi pi-arrow-up" text rounded aria-label="Subir fotografía" v-tooltip.top="'Subir fotografía'" :disabled="imageSaved || imageStatusBusy || index === 0" @click="moveImage(image, -1)" />
                                            <Button type="button" icon="pi pi-arrow-down" text rounded aria-label="Bajar fotografía" v-tooltip.top="'Bajar fotografía'" :disabled="imageSaved || imageStatusBusy || index === selectedProduct.images.length - 1" @click="moveImage(image, 1)" />
                                            <Button type="button" icon="pi pi-refresh" text rounded aria-label="Reemplazar fotografía" v-tooltip.top="'Reemplazar fotografía'" :disabled="imageSaved || imageStatusBusy" @click="chooseReplacement(image)" />
                                            <Button v-if="image.can_regenerate_image" type="button" icon="pi pi-sync" text rounded aria-label="Regenerar fotografía" v-tooltip.top="'Regenerar fotografía'" :disabled="imageSaved || imageStatusBusy" @click="requestImageRegeneration(image)" />
                                            <Button type="button" icon="pi pi-trash" severity="danger" text rounded :aria-label="deletingImageIds.has(Number(image.id)) ? 'Eliminando fotografía' : 'Eliminar fotografía'" v-tooltip.top="'Eliminar fotografía'" :disabled="imageSaved || imageStatusBusy || deletingImageIds.has(Number(image.id))" @click="deleteImage(image)" />
                                        </div>
                                    </article>
                                </div>
                                <FileUpload
                                    ref="galleryUpload"
                                    mode="advanced"
                                    name="images"
                                    :multiple="!replacementTargetImageId"
                                    :auto="false"
                                    :show-upload-button="false"
                                    :show-cancel-button="false"
                                    :disabled="imageSaved || (galleryImageCount >= 6 && !replacementTargetImageId)"
                                    :file-limit="6"
                                    :max-file-size="10 * 1024 * 1024"
                                    accept="image/jpeg,image/png,image/webp"
                                    choose-label="Agregar fotografías"
                                    invalid-file-size-message="Cada fotografía debe pesar como máximo 10 MB."
                                    invalid-file-type-message="Usa archivos JPEG, PNG o WebP."
                                    invalid-file-limit-message="Puedes elegir hasta seis archivos por selección."
                                    class="overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50"
                                    @select="selectGalleryFiles"
                                    @clear="onGalleryUploadClear"
                                >
                                    <template #header="{ chooseCallback }">
                                        <div class="border-b border-slate-200 p-3">
                                            <Button type="button" icon="pi pi-images" label="Agregar fotografías" severity="secondary" :disabled="imageSaved || (galleryImageCount >= 6 && !replacementTargetImageId)" @click="chooseNewGalleryImages(chooseCallback)" />
                                        </div>
                                    </template>
                                    <template #content="{ messages }">
                                        <div class="p-3">
                                            <p v-for="message in messages || []" :key="message" role="alert" class="mb-2 text-sm text-red-700">{{ message }}</p>
                                            <div v-if="imagePreviews.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                                <article v-for="(preview, index) in imagePreviews" :key="preview.key" class="min-w-0 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                                                    <div class="relative mb-3 flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-50">
                                                        <Image :src="preview.url" :alt="`Vista previa de ${preview.file.name}`" preview image-class="h-full w-full object-contain" image-style="object-fit: contain" class="h-full w-full" />
                                                        <Tag value="Nueva" severity="success" class="absolute left-2 top-2" />
                                                    </div>
                                                    <p class="truncate text-sm font-semibold" :title="preview.file.name">{{ preview.file.name }}</p>
                                                    <p class="text-xs text-slate-600">{{ formatFileSize(preview.file.size) }}</p>
                                                    <Button type="button" icon="pi pi-times" label="Retirar" severity="secondary" text size="small" class="mt-2 w-full" :disabled="imageSaved" @click="removeImagePreview(index)" />
                                                </article>
                                            </div>
                                        </div>
                                    </template>
                                    <template #empty>
                                        <div class="p-5 text-center text-sm text-slate-600" aria-describedby="product-gallery-help">
                                            <i class="pi pi-cloud-upload mb-2 text-2xl text-slate-500" aria-hidden="true" />
                                            <p>Arrastra fotografías aquí o usa “Agregar fotografías”.</p>
                                        </div>
                                    </template>
                                </FileUpload>
                                <p id="product-gallery-help" class="text-sm text-slate-600">JPEG, PNG o WebP; máximo 10 MB por archivo, dimensiones de 800 × 800 a 5000 × 5000 y hasta 20 megapíxeles. Máximo seis fotografías por producto. Las nuevas se envían al guardar el producto.</p>
                                <p v-if="isEditing" class="text-sm text-slate-500">Las fotografías se procesan por separado; la imagen publicada permanece disponible mientras se procesa un reemplazo.</p>
                                <div v-if="imageStatusMessage" class="rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950" role="status" aria-live="polite">
                                        <p>{{ imageStatusMessage }}</p>
                                    <button v-if="imageFollowupNeeded" type="button" class="mt-2 min-h-10 font-semibold underline" :disabled="imageStatusBusy" @click="checkImageStatus">{{ imageStatusBusy ? 'Consultando…' : 'Consultar estado' }}</button>
                                </div>
                            </section>
                            <p v-if="fieldError('image')" role="alert" class="mt-1 text-xs text-red-700">{{ fieldError('image') }}</p>
                        </div>
                        <Field label="Descripción" :error="fieldError('description')" class="md:col-span-2"><textarea v-model="form.description" class="input" /></Field>
                        <div class="flex justify-end gap-3 md:col-span-2"><button type="button" class="rounded bg-slate-200 px-4 py-2" @click="closeModal">Cancelar</button><button type="submit" class="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50" :disabled="processing || optionsLoading || imageSaved">{{ processing ? 'Guardando…' : imageSaved ? 'Imagen en procesamiento…' : 'Guardar' }}</button></div>
                    </form>
                </div>
            </div>
        </div>
        <Menu ref="mobileActionsMenu" :model="mobileActionItems" popup aria-label="Acciones del producto" @hide="clearMobileProductAction" />
    </AdminLayout>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { nextTick } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import Button from 'primevue/button';
import FileUpload from 'primevue/fileupload';
import Image from 'primevue/image';
import Tag from 'primevue/tag';
import Menu from 'primevue/menu';
import Tooltip from 'primevue/tooltip';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminDataTable from '@/components/admin/table/AdminDataTable.vue';
import api from '@/api';
import { useAdminListQuery } from '@/composables/useAdminListQuery';
import { useRoute, useRouter } from 'vue-router';
import { productImageSrcset } from '@/utils/productPresentation';

const vTooltip = Tooltip;

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
    { key: 'name', label: 'Producto', sortable: true, mobileDetail: true, class: 'min-w-60' },
    { key: 'sku', label: 'SKU', sortable: true, mobileDetail: true, class: 'min-w-28' },
    { key: 'category', label: 'Categoría', mobileDetail: true, class: 'hidden md:table-cell min-w-32' },
    { key: 'brand', label: 'Marca', mobileDetail: true, class: 'hidden md:table-cell min-w-28' },
    { key: 'price', label: 'Precio', sortable: true, mobileDetail: true, class: 'min-w-28 text-right' },
    { key: 'physical_stock', label: 'Stock físico', mobileDetail: true, class: 'min-w-32' },
    { key: 'is_active', label: 'Estado', sortable: true, mobileDetail: true, class: 'min-w-24' },
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
const mobileActionsMenu = ref(null);
const mobileActionProduct = ref(null);
const deletingImageIds = ref(new Set());
const galleryUpload = ref(null);
const imagePreviews = ref([]);
const imageSelectionMessage = ref('');
const replacementTargetImageId = ref(null);
const imageStatus = ref('');
const imageStatusMessage = ref('');
const imageStatusBusy = ref(false);
const imageFollowupNeeded = ref(false);
const imageSaved = ref(false);
const currentImageUrl = computed(() => selectedProduct.value?.image_url || null);
const galleryImageCount = computed(() => (selectedProduct.value?.images?.length || 0) + (form.value?.images?.length || 0));
let openingSequence = 0;
let submissionSequence = 0;
let optionsPromise = null;
let isComponentMounted = false;
let imageStatusTimer = null;
let imageStatusSequence = 0;
let imageStatusStartedAt = 0;
let imageStatusRequestInFlight = false;
let imageStatusController = null;
let imageStatusQueue = [];
let imageStatusImageId = null;
let imageOperation = 'upload';
let clearingGalleryUpload = false;
let imagePreviewSequence = 0;
const ownedImageObjectUrls = new Set();
const hasActiveFilters = computed(() => filterKeys.some((key) => list.filters[key] !== ''));
const activeFilterCount = computed(() => filterKeys.filter((key) => list.filters[key] !== '').length);
const productPaginationSummary = computed(() => {
    const meta = list.meta.value;
    if (!meta.total) return 'Mostrando 0 de 0 productos';
    const from = meta.from ?? ((meta.current_page - 1) * meta.per_page + 1);
    const to = meta.to ?? Math.min(meta.current_page * meta.per_page, meta.total);
    return `Mostrando ${from}–${to} de ${meta.total} productos`;
});
const mobileActionItems = computed(() => {
    const product = mobileActionProduct.value;
    if (!product) return [];

    const busy = statusBusy.has(product.id);
    return [
        { label: 'Editar', icon: 'pi pi-pencil', command: () => openModal(product) },
        { separator: true },
        {
            label: product.is_active ? 'Desactivar' : 'Reactivar',
            icon: product.is_active ? 'pi pi-power-off' : 'pi pi-check-circle',
            class: product.is_active ? 'text-amber-700' : 'text-emerald-700',
            disabled: busy,
            command: () => confirmStatus(product),
        },
    ];
});
const listErrorMessage = computed(() => safeRequestMessage(list.error.value));
const productPriceFormatter = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' });

function formatProductPrice(value) {
    const price = Number(value);
    return Number.isFinite(price) ? productPriceFormatter.format(price) : '—';
}

function stockStatus(value) {
    const quantity = Number(value);
    if (!Number.isFinite(quantity) || quantity <= 0) return { label: 'Agotado', severity: 'danger' };
    if (quantity <= 5) return { label: 'Stock bajo', severity: 'warn' };
    return { label: 'Disponible', severity: 'success' };
}

function openMobileProductActions(event, product) {
    mobileActionProduct.value = product;
    mobileActionsMenu.value?.toggle(event);
}

function clearMobileProductAction() {
    mobileActionProduct.value = null;
}

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
    const mode = product ? 'edit' : 'create';
    const productId = product?.id ?? null;

    if (product && !validId(product.id)) {
        toast.add({ severity: 'error', summary: 'Producto inválido', detail: 'No se puede abrir la edición del producto.', life: 3000 });
        return;
    }

    const sequence = ++openingSequence;

    stopImageStatusPolling();
    clearImagePreview();
    imageSaved.value = false;
    imageStatus.value = '';
    imageStatusMessage.value = '';
    imageStatusBusy.value = false;
    imageFollowupNeeded.value = false;
    imageOperation = 'upload';

    errors.value = {};
    isEditing.value = Boolean(product);
    selectedProduct.value = product;

    if (product) {
        preserveAssignedOption(categoryOptions.value, product.category, product.category_id);
        preserveAssignedOption(brandOptions.value, product.brand, product.brand_id);
        form.value = { id: product.id, name: product.name, sku: product.sku || '', price: product.price, category_id: product.category?.id || '', brand_id: product.brand?.id || '', description: product.description ?? '', images: [] };
    } else {
        form.value = { name: '', sku: '', price: 0, cantidad_inicial: 0, warehouse_id: preferredWarehouse(), category_id: '', brand_id: '', description: '', images: [] };
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
    stopImageStatusPolling();
    clearImagePreview();
    showModal.value = false;
    selectedProduct.value = null;
    errors.value = {};
    imageSaved.value = false;
    imageStatus.value = '';
    imageStatusMessage.value = '';
    imageFollowupNeeded.value = false;
}

function clearImagePreview() {
    ownedImageObjectUrls.forEach(url => URL.revokeObjectURL(url));
    ownedImageObjectUrls.clear();
    imagePreviews.value = [];
    imageSelectionMessage.value = '';
    replacementTargetImageId.value = null;
    if (form.value) form.value.images = [];
    clearGalleryUploadQueue();
}

function clearGalleryUploadQueue() {
    if (!galleryUpload.value) return;
    clearingGalleryUpload = true;
    galleryUpload.value.clear();
    clearingGalleryUpload = false;
}

function onGalleryUploadClear() {
    if (clearingGalleryUpload) return;
    clearImagePreview();
}

function ownImageObjectUrl(file) {
    let url = file.objectURL;
    if (!url) {
        url = URL.createObjectURL(file);
        file.objectURL = url;
    }
    ownedImageObjectUrls.add(url);
    return url;
}

function releaseImageObjectUrl(file) {
    const url = file?.objectURL;
    if (url && ownedImageObjectUrls.delete(url)) URL.revokeObjectURL(url);
}

async function chooseNewGalleryImages(chooseCallback) {
    replacementTargetImageId.value = null;
    await nextTick();
    chooseCallback();
}

function selectGalleryFiles(event) {
    const files = Array.isArray(event.files) ? [...event.files] : [];
    const replacementId = replacementTargetImageId.value;
    if (replacementId) {
        replacementTargetImageId.value = null;
        const image = (selectedProduct.value?.images || []).find(item => Number(item.id) === Number(replacementId));
        if (files.length !== 1 || !image) {
            files.forEach(releaseImageObjectUrl);
            imageSelectionMessage.value = 'Para reemplazar una fotografía, selecciona un único archivo válido.';
            clearGalleryUploadQueue();
            return;
        }
        imageSelectionMessage.value = '';
        ownImageObjectUrl(files[0]);
        clearGalleryUploadQueue();
        void replaceImageFile(image, files[0]);
        return;
    }

    if (imageSaved.value) {
        files.forEach(releaseImageObjectUrl);
        clearGalleryUploadQueue();
        return;
    }

    const existingCount = selectedProduct.value?.images?.length || 0;
    const currentFiles = Array.isArray(form.value.images) ? form.value.images : [];
    const rawFiles = event.originalEvent?.dataTransfer?.files || event.originalEvent?.target?.files;
    const rejectedByFileUpload = rawFiles && rawFiles.length > files.length;
    if (rejectedByFileUpload || existingCount + currentFiles.length + files.length > 6) {
        files.forEach(releaseImageObjectUrl);
        imageSelectionMessage.value = rejectedByFileUpload
            ? 'Uno o más archivos no cumplen el formato, el tamaño máximo de 10 MB o el límite de selección.'
            : 'Un producto admite hasta seis fotografías en total. Retira una selección o elige menos archivos.';
        clearGalleryUploadQueue();
        return;
    }
    imageSelectionMessage.value = '';
    form.value.images = [...currentFiles, ...files];
    const previewOffset = imagePreviews.value.length;
    imagePreviews.value = [...imagePreviews.value, ...files.map((file, index) => {
        const url = ownImageObjectUrl(file);
        return { key: ++imagePreviewSequence, file, url, index: previewOffset + index };
    })];
    clearGalleryUploadQueue();
    if (files.length) {
        imageStatus.value = '';
        imageStatusMessage.value = '';
        imageFollowupNeeded.value = false;
    }
}

function removeImagePreview(index) {
    const preview = imagePreviews.value[index];
    if (preview) releaseImageObjectUrl(preview.file);
    imagePreviews.value.splice(index, 1);
    form.value.images.splice(index, 1);
}

function imageProcessingSeverity(status) {
    return ({ pending: 'warn', processing: 'info', ready: 'success', failed: 'danger' })[status] || 'secondary';
}

function formatFileSize(bytes) {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function imageProcessingLabel(status) {
    return ({ pending: 'Pendiente', processing: 'Procesando', ready: 'Lista', failed: 'No procesada' })[status] || 'Estado no disponible';
}

function stopImageStatusPolling() {
    imageStatusSequence++;
    if (imageStatusTimer) clearTimeout(imageStatusTimer);
    imageStatusTimer = null;
    imageStatusController?.abort();
    imageStatusController = null;
    imageStatusBusy.value = false;
    imageStatusRequestInFlight = false;
    imageStatusQueue = [];
    imageStatusImageId = null;
}

function startImageStatusPolling(productId, operation, opening, imageIds = []) {
    stopImageStatusPolling();
    const sequence = imageStatusSequence;
    imageStatusQueue = [...new Set(imageIds.filter(validId).map(Number))];
    imageStatusImageId = imageStatusQueue.shift() || null;
    if (!validId(imageStatusImageId)) {
        imageSaved.value = false;
        imageStatus.value = 'failed';
        imageStatusMessage.value = 'No fue posible identificar la fotografía procesada.';
        imageFollowupNeeded.value = false;
        return;
    }
    imageOperation = operation;
    imageStatusStartedAt = Date.now();
    imageStatus.value = 'pending';
    imageStatusMessage.value = 'Imagen enviada para procesamiento.';
    imageFollowupNeeded.value = false;
    imageStatusTimer = setTimeout(() => pollImageStatus(productId, opening, sequence), 4000);
}

function imageStatusIsCurrent(productId, opening, sequence) {
    return isComponentMounted && showModal.value && openingSequence === opening
        && imageStatusSequence === sequence && Number(selectedProduct.value?.id) === Number(productId);
}

async function pollImageStatus(productId, opening, sequence) {
    if (!imageStatusIsCurrent(productId, opening, sequence) || imageStatusRequestInFlight) return;
    if (!validId(imageStatusImageId)) return;
    imageStatusTimer = null;
    imageStatusRequestInFlight = true;
    imageStatusBusy.value = true;
    const controller = new AbortController();
    imageStatusController = controller;
    try {
        const statusPath = `/admin/products/${productId}/images/${imageStatusImageId}/status`;
        const { data } = await api.get(statusPath, { signal: controller.signal });
        if (!imageStatusIsCurrent(productId, opening, sequence)) return;
        const status = ['pending', 'processing', 'ready', 'failed'].includes(data?.status) ? data.status : 'failed';
        imageStatus.value = status;
        if (status === 'pending') imageStatusMessage.value = 'Imagen enviada para procesamiento.';
        else if (status === 'processing') imageStatusMessage.value = 'Se están generando los formatos optimizados.';
        else if (status === 'ready') imageStatusMessage.value = imageOperation === 'regenerate' ? 'Formatos regenerados correctamente.' : 'Imagen procesada correctamente.';
        else imageStatusMessage.value = 'No fue posible procesar la imagen.';

        if (status === 'ready' || status === 'failed') {
            await list.load();
            if (!imageStatusIsCurrent(productId, opening, sequence)) return;
            const refreshed = list.rows.value.find(row => Number(row.id) === Number(productId));
            selectedProduct.value = refreshed || { ...selectedProduct.value, ...data };
            toast.add({ severity: status === 'ready' ? 'success' : 'error', summary: status === 'ready' ? 'Imagen actualizada' : 'Procesamiento fallido', detail: imageStatusMessage.value, life: 3500 });
            if (imageStatusQueue.length) {
                imageStatusImageId = imageStatusQueue.shift();
                imageStatusSequence++;
                const nextSequence = imageStatusSequence;
                imageStatusStartedAt = Date.now();
                imageStatusTimer = setTimeout(() => pollImageStatus(productId, opening, nextSequence), 4000);
                return;
            }
            imageSaved.value = false;
            imageFollowupNeeded.value = false;
            clearImagePreview();
            return;
        }

        if (Date.now() - imageStatusStartedAt >= 120000) {
            imageFollowupNeeded.value = true;
            imageStatusMessage.value = 'El seguimiento automático terminó. Puedes consultar el estado nuevamente.';
            return;
        }
        imageStatusTimer = setTimeout(() => pollImageStatus(productId, opening, sequence), 4000);
    } catch {
        if (!imageStatusIsCurrent(productId, opening, sequence)) return;
        imageFollowupNeeded.value = true;
        imageStatusMessage.value = 'No se pudo consultar el estado de la imagen. Inténtalo nuevamente.';
    } finally {
        if (imageStatusController === controller) {
            imageStatusController = null;
            imageStatusRequestInFlight = false;
            if (imageStatusIsCurrent(productId, opening, sequence)) imageStatusBusy.value = false;
        }
    }
}

function checkImageStatus() {
    const productId = selectedProduct.value?.id;
    if (!validId(productId) || imageStatusBusy.value) return;
    if (imageStatusTimer) clearTimeout(imageStatusTimer);
    imageStatusTimer = null;
    imageStatusStartedAt = Date.now();
    imageFollowupNeeded.value = false;
    imageStatusMessage.value = 'Consultando el estado de la imagen…';
    const sequence = imageStatusSequence;
    void pollImageStatus(productId, openingSequence, sequence);
}

function requestImageRegeneration(image = null) {
    const product = selectedProduct.value;
    const targetImage = image || product?.images?.find(item => item.is_primary) || null;
    if (!validId(product?.id) || !targetImage?.can_regenerate_image || !validId(targetImage?.id) || imageSaved.value || imageStatusBusy.value) return;
    const imageId = Number(targetImage.id);
    const requestedOpening = openingSequence;
    confirm.require({
        message: 'Se volverán a crear las versiones optimizadas usando la configuración y marca de agua actuales. La imagen publicada seguirá disponible durante el proceso',
        header: 'Regenerar formatos',
        icon: 'pi pi-exclamation-triangle',
        accept: async () => {
            if (imageSaved.value || imageStatusBusy.value || !isCurrentOpening(requestedOpening, product.id, 'edit')) return;
            if (!(selectedProduct.value?.images || []).some(item => Number(item.id) === imageId)) return;
            const opening = requestedOpening;
            imageSaved.value = true;
            imageOperation = 'regenerate';
            imageStatus.value = 'pending';
            imageStatusMessage.value = 'Imagen enviada para procesamiento.';
            imageStatusBusy.value = true;
            try {
                const { data } = await api.post(`/admin/products/${product.id}/images/${imageId}/regenerate`);
                if (!isCurrentOpening(opening, product.id, 'edit')) return;
                startImageStatusPolling(product.id, 'regenerate', opening, [imageId]);
                imageStatus.value = data?.status || 'pending';
            } catch (error) {
                if (!isCurrentOpening(opening, product.id, 'edit')) return;
                imageSaved.value = false;
                imageStatus.value = 'failed';
                imageStatusMessage.value = statusOf(error) === 422
                    ? 'Sube una imagen para generar sus formatos optimizados.'
                    : 'No fue posible procesar la imagen.';
                imageFollowupNeeded.value = false;
            } finally {
                if (isCurrentOpening(opening, product.id, 'edit')) imageStatusBusy.value = false;
            }
        },
    });
}

async function refreshProductImages(productId, opening = openingSequence) {
    await list.load();
    if (!isCurrentOpening(opening, productId, 'edit')) return;
    selectedProduct.value = list.rows.value.find(row => Number(row.id) === Number(productId)) || selectedProduct.value;
}

async function setPrimaryImage(image) {
    const product = selectedProduct.value;
    if (!validId(product?.id) || !validId(image?.id) || imageSaved.value) return;
    try {
        await api.put(`/admin/products/${product.id}/images/${image.id}/primary`);
        await refreshProductImages(product.id);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'No se pudo actualizar', detail: safeRequestMessage(error), life: 3500 });
    }
}

async function moveImage(image, offset) {
    const product = selectedProduct.value;
    if (!validId(product?.id) || !validId(image?.id) || imageSaved.value) return;
    const ordered = [...(product.images || [])];
    const from = ordered.findIndex(item => Number(item.id) === Number(image.id));
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ordered.length) return;
    [ordered[from], ordered[to]] = [ordered[to], ordered[from]];
    try {
        await api.post(`/admin/products/${product.id}/images/reorder`, { image_ids: ordered.map(item => item.id) });
        selectedProduct.value = { ...product, images: ordered.map((item, sort_order) => ({ ...item, sort_order })) };
        await list.load();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'No se pudo ordenar', detail: safeRequestMessage(error), life: 3500 });
    }
}

function deleteImage(image) {
    const product = selectedProduct.value;
    if (!validId(product?.id) || !validId(image?.id) || imageSaved.value) return;
    const productId = Number(product.id);
    const imageId = Number(image.id);
    if (deletingImageIds.value.has(imageId)) return;
    const opening = openingSequence;

    confirm.require({
        message: '¿Eliminar esta fotografía de la galería? Esta acción no se puede deshacer.',
        header: 'Eliminar fotografía',
        icon: 'pi pi-exclamation-triangle',
        accept: () => performImageDeletion(productId, imageId, opening),
    });
}

async function performImageDeletion(productId, imageId, opening) {
    if (!isCurrentOpening(opening, productId, 'edit') || imageSaved.value || deletingImageIds.value.has(imageId)) return;
    if (!(selectedProduct.value?.images || []).some(image => Number(image.id) === imageId)) return;
    deletingImageIds.value.add(imageId);
    try {
        await api.delete(`/admin/products/${productId}/images/${imageId}`);
        if (!isCurrentOpening(opening, productId, 'edit')) return;
        await refreshProductImages(productId, opening);
    } catch (error) {
        if (isCurrentOpening(opening, productId, 'edit')) {
            toast.add({ severity: 'error', summary: 'No se pudo eliminar', detail: safeRequestMessage(error), life: 3500 });
        }
    } finally {
        deletingImageIds.value.delete(imageId);
    }
}

async function chooseReplacement(image) {
    if (!validId(selectedProduct.value?.id) || !validId(image?.id) || imageSaved.value || imageStatusBusy.value) return;
    replacementTargetImageId.value = Number(image.id);
    await nextTick();
    galleryUpload.value?.choose();
}

async function replaceImageFile(image, file) {
    const product = selectedProduct.value;
    if (!validId(product?.id) || !validId(image?.id) || !file || imageSaved.value) {
        releaseImageObjectUrl(file);
        return;
    }
    imageSaved.value = true;
    try {
        const data = new FormData();
        data.append('image', file);
        await api.post(`/admin/products/${product.id}/images/${image.id}/replace`, data);
        startImageStatusPolling(product.id, 'replace', openingSequence, [image.id]);
    } catch (error) {
        imageSaved.value = false;
        toast.add({ severity: 'error', summary: 'No se pudo reemplazar', detail: safeRequestMessage(error), life: 3500 });
    } finally {
        releaseImageObjectUrl(file);
    }
}

async function submitForm() {
    if (processing.value || imageSaved.value) return;

    if (isEditing.value && !validId(form.value.id)) {
        toast.add({ severity: 'error', summary: 'Producto inválido', detail: 'No se envió la solicitud.', life: 3000 });
        return;
    }

    const submission = ++submissionSequence;
    const opening = openingSequence;
    const mode = isEditing.value ? 'edit' : 'create';
    const productId = selectedProduct.value?.id ?? null;
    const existingImageIds = new Set((selectedProduct.value?.images || []).map(image => String(image.id)));
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
        const selectedFiles = Array.isArray(formState.images) ? formState.images : [];
        selectedFiles.forEach((file, index) => data.append(`images[${index}]`, file));
        let response;
        if (mode === 'edit') {
            data.append('_method', 'PUT');
            response = await api.post(`/admin/products/${productId}`, data);
        } else {
            response = await api.post('/admin/products', data);
        }
        if (isComponentMounted) void list.load();
        if (isCurrentOpening(opening, productId, mode)) {
            if (selectedFiles.length) {
                const savedProduct = response?.data;
                if (validId(savedProduct?.id)) {
                    isEditing.value = true;
                    selectedProduct.value = savedProduct;
                    form.value.id = savedProduct.id;
                    const operationImages = (savedProduct.images || [])
                        .filter(image => validId(image?.id) && !existingImageIds.has(String(image.id)));
                    const queuedImageIds = operationImages
                        .filter(image => image.processing_status === 'pending' || image.processing_status === 'processing')
                        .map(image => image.id);
                    if (queuedImageIds.length) {
                        imageSaved.value = true;
                        startImageStatusPolling(savedProduct.id, 'upload', opening, queuedImageIds);
                    } else if (operationImages.some(image => image.processing_status === 'failed')) {
                        imageStatus.value = 'failed';
                        imageStatusMessage.value = 'No fue posible procesar la imagen.';
                        imageSaved.value = false;
                        imageFollowupNeeded.value = false;
                    }
                } else {
                    closeModal();
                }
                toast.add({ severity: 'success', summary: 'Guardado', detail: 'Producto guardado correctamente.', life: 2500 });
            } else {
                closeModal();
                toast.add({ severity: 'success', summary: 'Guardado', detail: 'Producto guardado correctamente.', life: 2500 });
            }
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
    if (validId(product?.id) && statusBusy.has(product.id)) return;
    if (!validId(product?.id)) {
        toast.add({ severity: 'error', summary: 'Producto inválido', detail: 'No se envió la solicitud.', life: 3000 });
        return;
    }
    confirm.require({
        message: `¿Desea ${product.is_active ? 'desactivar' : 'reactivar'} ${product.name}?`,
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
    stopImageStatusPolling();
    clearImagePreview();
});
</script>

<style scoped>
.input { width: 100%; border: 1px solid #cbd5e1; border-radius: .25rem; padding: .5rem .75rem; outline: none; }
.input:focus { box-shadow: 0 0 0 2px #1d4ed8; }
</style>
