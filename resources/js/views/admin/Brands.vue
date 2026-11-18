<template>
    <CatalogEntityManager
        endpoint="/admin/brands"
        title="Marcas"
        singular="Marca"
        eyebrow="CATÁLOGO COMERCIAL"
        description="Administra las marcas disponibles en el catálogo de productos."
        icon="pi pi-bookmark"
        image-enabled
        image-label="Logotipo de marca"
        image-help="JPEG, PNG o WebP. Máximo 2 MB; hasta 4000 × 2500 px y 10 megapíxeles. Se conserva completo, sin recorte."
        image-file-error-label="un logotipo"
        image-remove-confirmation="¿Eliminar el logotipo de esta marca? La marca y sus productos se conservarán."
        image-alt-prefix="Logo de"
        image-frame-class="h-12 w-12 rounded-lg border border-slate-200 bg-white p-2"
        image-fit-class="object-contain"
        image-preview-frame-class="aspect-[16/10] bg-slate-50 p-3"
        image-preview-class="object-contain"
        :image-min-width="1"
        :image-min-height="1"
        :image-max-width="4000"
        :image-max-height="2500"
        :image-max-megapixels="10"
        image-removal-message="La marca se conservó sin imagen."
    >
        <template #row-actions="{ item }">
            <Button type="button" label="Modelos" icon="pi pi-list" severity="secondary" text size="small" :aria-label="`Administrar modelos de ${item.name}`" @click="openModels(item)" />
        </template>
    </CatalogEntityManager>

    <Dialog v-model:visible="modelsVisible" modal :header="`Modelos de ${selectedBrand?.name || 'marca'}`" :style="{ width: 'min(44rem, 95vw)' }" :breakpoints="{ '640px': '96vw' }" :close-on-escape="!modelSaving" :closable="!modelSaving">
        <form class="mb-5 flex flex-col gap-2 sm:flex-row" @submit.prevent="createModel">
            <label class="flex-1 text-sm font-semibold text-slate-700" for="new-product-model">Nuevo modelo
                <InputText id="new-product-model" v-model="newModelName" class="mt-1 w-full" maxlength="255" placeholder="Top Tec 4200 o ABC-00123" :disabled="modelSaving" />
            </label>
            <Button type="submit" label="Agregar modelo" icon="pi pi-plus" class="sm:self-end" :disabled="modelSaving || !newModelName.trim()" :loading="modelSaving" />
        </form>
        <p v-if="modelError" class="mb-3 text-sm text-red-700" role="alert">{{ modelError }}</p>
        <div v-if="modelsLoading" class="py-8 text-center text-slate-600" role="status">Cargando modelos…</div>
        <div v-else-if="!models.length" class="rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-600">Esta marca todavía no tiene modelos.</div>
        <div v-else class="max-h-[55vh] space-y-2 overflow-y-auto">
            <article v-for="model in models" :key="model.id" class="grid grid-cols-1 items-center gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                <InputText v-model="model.name" :aria-label="`Nombre del modelo ${model.id}`" maxlength="255" :disabled="modelSaving" @keydown.enter.prevent="saveModel(model)" />
                <span class="text-xs text-slate-600">{{ model.products_count }} productos</span>
                <div class="flex flex-wrap items-center gap-2">
                    <Tag :value="model.is_active ? 'Activo' : 'Inactivo'" :severity="model.is_active ? 'success' : 'secondary'" />
                    <Button type="button" label="Guardar nombre" icon="pi pi-check" severity="secondary" outlined size="small" :disabled="modelSaving || !model.name.trim()" @click="saveModel(model)" />
                    <Button type="button" :label="model.is_active ? 'Desactivar' : 'Reactivar'" :icon="model.is_active ? 'pi pi-ban' : 'pi pi-check-circle'" :severity="model.is_active ? 'warn' : 'success'" text size="small" :disabled="modelSaving" @click="toggleModel(model)" />
                </div>
            </article>
        </div>
        <template #footer>
            <Button label="Cerrar" severity="secondary" outlined :disabled="modelSaving" @click="modelsVisible = false" />
            <Button v-if="modelLastPage > 1" label="Ver más" icon="pi pi-angle-down" severity="secondary" text :disabled="modelsLoading" @click="loadModels(modelPage + 1)" />
        </template>
    </Dialog>
</template>

<script setup>
import { ref } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import CatalogEntityManager from '@/components/admin/CatalogEntityManager.vue';

const confirm = useConfirm();
const toast = useToast();
const modelsVisible = ref(false);
const selectedBrand = ref(null);
const models = ref([]);
const newModelName = ref('');
const modelError = ref('');
const modelsLoading = ref(false);
const modelSaving = ref(false);
const modelPage = ref(1);
const modelLastPage = ref(1);

async function openModels(brand) {
    selectedBrand.value = { id: Number(brand.id), name: String(brand.name) };
    modelsVisible.value = true;
    newModelName.value = '';
    modelError.value = '';
    models.value = [];
    await loadModels(1);
}

async function loadModels(page = 1) {
    if (!selectedBrand.value || modelsLoading.value) return;
    modelsLoading.value = true;
    modelError.value = '';
    try {
        const { data } = await api.get(`/admin/brands/${selectedBrand.value.id}/product-models`, { params: { page } });
        models.value = page === 1 ? (data.data || []) : [...models.value, ...(data.data || [])];
        modelPage.value = Number(data.current_page) || 1;
        modelLastPage.value = Number(data.last_page) || 1;
    } catch {
        modelError.value = 'No se pudieron cargar los modelos de esta marca.';
    } finally {
        modelsLoading.value = false;
    }
}

async function createModel() {
    if (modelSaving.value || !selectedBrand.value || !newModelName.value.trim()) return;
    modelSaving.value = true;
    modelError.value = '';
    try {
        await api.post(`/admin/brands/${selectedBrand.value.id}/product-models`, { name: newModelName.value });
        newModelName.value = '';
        toast.add({ severity: 'success', summary: 'Modelo creado', detail: 'El modelo está disponible para productos de esta marca.', life: 3000 });
        await loadModels(1);
    } catch (error) {
        modelError.value = error.response?.status === 422 ? 'El nombre ya existe para esta marca o no es válido.' : 'No se pudo crear el modelo.';
    } finally {
        modelSaving.value = false;
    }
}

async function saveModel(model) {
    if (modelSaving.value || !model?.id || !model.name.trim()) return;
    modelSaving.value = true;
    modelError.value = '';
    try {
        const { data } = await api.put(`/admin/product-models/${model.id}`, { name: model.name });
        Object.assign(model, data);
        toast.add({ severity: 'success', summary: 'Modelo actualizado', detail: 'Se conservó su asociación con los productos.', life: 3000 });
    } catch (error) {
        modelError.value = error.response?.status === 422 ? 'El nombre ya existe para esta marca o no es válido.' : 'No se pudo actualizar el modelo.';
    } finally {
        modelSaving.value = false;
    }
}

function toggleModel(model) {
    const active = !model.is_active;
    confirm.require({
        message: active ? '¿Reactivar este modelo para nuevas asignaciones?' : '¿Desactivar este modelo? Los productos que ya lo usan conservarán su asociación.',
        header: 'Cambiar estado del modelo',
        acceptLabel: active ? 'Reactivar' : 'Desactivar',
        rejectLabel: 'Cancelar',
        accept: () => updateModelStatus(model, active),
    });
}

async function updateModelStatus(model, isActive) {
    if (modelSaving.value) return;
    modelSaving.value = true;
    try {
        const { data } = await api.patch(`/admin/product-models/${model.id}/status`, { is_active: isActive });
        Object.assign(model, data);
        toast.add({ severity: 'success', summary: 'Estado actualizado', detail: 'Las asociaciones existentes se conservaron.', life: 3000 });
    } catch {
        modelError.value = 'No se pudo cambiar el estado del modelo.';
    } finally {
        modelSaving.value = false;
    }
}
</script>
