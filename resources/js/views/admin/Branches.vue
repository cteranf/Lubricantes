<template>
    <AdminLayout>
        <main class="mx-auto max-w-7xl space-y-6" aria-labelledby="branches-title">
            <AdminPageHeader eyebrow="OPERACIÓN COMERCIAL" title="Administración de sedes" title-id="branches-title" description="Gestiona las ubicaciones operativas y los puntos de recojo.">
                <template #action>
                <button type="button" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700" @click="open()">
                    <i class="pi pi-plus mr-2" aria-hidden="true"></i>Nueva sede
                </button>
                </template>
            </AdminPageHeader>

            <div v-if="!loading && items.length && !items.some(item => item.is_main)" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                No hay una sede principal definida. Seleccione una sede activa y use “Establecer como principal”.
            </div>

            <section class="rounded-2xl bg-white p-4 shadow-sm"><form class="flex flex-col gap-3 md:flex-row" @submit.prevent="load(1)"><label class="flex-1 text-sm font-bold text-slate-700">Buscar sede<input id="branch-search" v-model.trim="search" type="search" placeholder="Código, nombre o distrito" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"></label><div class="flex items-end gap-2"><button class="min-h-11 rounded-lg bg-blue-700 px-4 font-bold text-white" :disabled="loading">Aplicar</button><button type="button" class="min-h-11 rounded-lg border border-slate-300 px-4 font-bold" :disabled="loading" @click="clear">Limpiar</button><button type="button" class="min-h-11 w-11 rounded-lg border border-slate-300" title="Actualizar sedes" aria-label="Actualizar sedes" :disabled="loading" @click="load()"><i class="pi pi-refresh" :class="{ 'pi-spin': loading }"></i></button></div></form></section>

            <section class="grid gap-3 sm:grid-cols-2"><article class="rounded-xl bg-white p-4 shadow-sm"><span class="text-sm font-semibold text-slate-500">Total de sedes</span><strong class="mt-1 block text-3xl">{{ pagination.total || 0 }}</strong></article></section>
            <section v-if="error" class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-900" role="alert">{{ error }} <button type="button" class="ml-2 font-bold underline" @click="load()">Reintentar</button></section>
            <div v-else-if="loading" class="rounded-xl bg-white p-10 text-center text-slate-500 shadow-sm"><i class="pi pi-spin pi-spinner mr-2"></i>Cargando sedes…</div>
            <section v-else class="overflow-hidden rounded-2xl bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full min-w-[860px] text-left text-sm"><thead class="bg-slate-100 text-xs font-bold uppercase tracking-wide text-slate-600"><tr><th scope="col" class="p-4">Código</th><th scope="col" class="p-4">Sede</th><th scope="col" class="p-4">Ubicación</th><th scope="col" class="p-4">Pickup</th><th scope="col" class="p-4">Estado</th><th scope="col" class="p-4 text-right">Acciones</th></tr></thead><tbody><template v-if="items.length"><tr v-for="item in items" :key="item.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="p-4 font-mono text-xs text-slate-600">{{ item.code }}</td><td class="p-4"><span class="font-bold text-slate-900">{{ item.name }}</span><span v-if="item.is_main" class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-bold text-blue-700">Principal</span></td><td class="p-4 text-slate-600">{{ location(item) }}</td><td class="p-4"><AdminStatusBadge :active="!!item.allows_pickup" active-label="Permite recojo" inactive-label="Sin recojo" /></td><td class="p-4"><AdminStatusBadge :active="!!item.is_active" active-label="Activa" inactive-label="Inactiva" /></td><td class="p-4"><div class="flex justify-end gap-1"><AdminIconButton v-if="item.is_active && !item.is_main" icon="pi pi-star" :label="`Establecer ${item.name} como principal`" tone="text-blue-700" @click="makeMain(item)"/><AdminIconButton icon="pi pi-pencil" :label="`Editar ${item.name}`" @click="open(item)"/><AdminIconButton :icon="item.is_active ? 'pi pi-ban' : 'pi pi-check-circle'" :label="`${item.is_active ? 'Desactivar' : 'Activar'} ${item.name}`" :tone="item.is_active ? 'text-red-700' : 'text-emerald-700'" @click="toggle(item)"/></div></td></tr></template><tr v-else><td colspan="6"><AdminEmptyState :icon="search ? 'pi pi-search' : 'pi pi-building'" :title="search ? 'No encontramos sedes con los filtros seleccionados' : 'Todavía no hay sedes registradas'" :description="search ? 'Prueba limpiando o ajustando la búsqueda.' : 'Crea la primera sede desde este panel.'"/></td></tr></tbody></table></div></section>
            <AdminPagination :page="pagination.current_page || 1" :last-page="pagination.last_page || 1" :loading="loading" :summary="pagination.total ? `Mostrando ${pagination.from}–${pagination.to} de ${pagination.total} sedes` : 'Sin sedes para mostrar'" @change="load" />

            <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3" role="dialog" aria-modal="true" aria-labelledby="branch-modal-title" @mousedown.self="close">
                <form class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl md:p-6" @submit.prevent="save">
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <div><h2 id="branch-modal-title" class="text-xl font-black text-slate-900">{{ form.id ? 'Editar sede' : 'Nueva sede' }}</h2><p class="mt-1 text-sm text-slate-600">{{ form.id ? 'Actualiza la información operativa de la sede.' : 'Registra una nueva ubicación para atención y recojo.' }}</p></div>
                        <button type="button" class="h-11 w-11 rounded-lg text-slate-600 hover:bg-slate-100" aria-label="Cerrar modal de sede" @click="close"><i class="pi pi-times"></i></button>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="text-sm font-medium">Código<input v-model="form.code" required maxlength="50" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Nombre<input v-model="form.name" required maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium md:col-span-2">Dirección<input v-model="form.address" required maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Departamento<select v-model="form.department_id" required class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2" @change="changeDepartment"><option :value="null" disabled>Selecciona</option><option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                        <label class="text-sm font-medium">Provincia<select v-model="form.province_id" required :disabled="!form.department_id" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2 disabled:bg-slate-100" @change="changeProvince"><option :value="null" disabled>Selecciona</option><option v-for="item in provinces" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                        <label class="text-sm font-medium">Distrito<select v-model="form.district_id" required :disabled="!form.province_id" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2 disabled:bg-slate-100"><option :value="null" disabled>Selecciona</option><option v-for="item in districts" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                        <label class="text-sm font-medium">Referencia <span class="font-normal text-slate-500">(opcional)</span><input v-model="form.reference" maxlength="500" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Teléfono <span class="font-normal text-slate-500">(opcional)</span><input v-model="form.phone" maxlength="30" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Correo <span class="font-normal text-slate-500">(opcional)</span><input v-model="form.email" type="email" maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Horario <span class="font-normal text-slate-500">(opcional)</span><input v-model="form.business_hours" maxlength="500" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium md:col-span-2">Instrucciones de recojo <span class="font-normal text-slate-500">(opcional)</span><textarea v-model="form.pickup_instructions" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border border-slate-300 p-2"></textarea></label>
                        <label class="text-sm font-medium md:col-span-2">Descripción <span class="font-normal text-slate-500">(opcional)</span><textarea v-model="form.description" rows="2" maxlength="2000" class="mt-1 block w-full rounded-lg border border-slate-300 p-2"></textarea></label>
                        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.allows_pickup" type="checkbox" class="h-5 w-5">Permite recojo en tienda</label>
                        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.serves_public" type="checkbox" class="h-5 w-5">Atiende al público</label>
                        <label v-if="!form.id" class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.is_active" type="checkbox" class="h-5 w-5">Sede activa</label>
                    </div>
                    <p v-if="form.id && form.is_main" class="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">Esta es la sede principal. Para cambiarla, use la acción correspondiente en la lista.</p>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" class="min-h-11 rounded-lg bg-slate-100 px-4 font-semibold text-slate-700" @click="close">Cancelar</button>
                        <button :disabled="saving" class="min-h-11 rounded-lg bg-blue-600 px-4 font-semibold text-white disabled:opacity-60">{{ saving ? 'Guardando…' : 'Guardar sede' }}</button>
                    </div>
                </form>
            </div>
        </main>
    </AdminLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminStatusBadge from '@/components/admin/AdminStatusBadge.vue';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import AdminIconButton from '@/components/admin/AdminIconButton.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';

const toast = useToast();
const confirm = useConfirm();
const items = ref([]);
const search = ref('');
const loading = ref(false);
const error = ref('');
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const modal = ref(false);
const saving = ref(false);
const form = ref({});
const departments = ref([]), provinces = ref([]), districts = ref([]);
let timer;

const emptyForm = () => ({
    code: '', name: '', address: '', department_id: null, province_id: null, district_id: null, reference: '',
    phone: '', email: '', business_hours: '', pickup_instructions: '', description: '',
    allows_pickup: false, serves_public: false, is_active: true,
});
const location = item => [item.department, item.province, item.district].every(Boolean) ? [item.department, item.province, item.district].join(' — ') : 'Sin ubicación configurada';
const message = error => error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'No se pudo completar la operación.';

function load(targetPage = pagination.value.current_page || 1) {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (loading.value) return;
        loading.value = true;
        error.value = '';
        try { const response = await api.get('/admin/branches', { params: { page: targetPage, search: search.value || undefined } }); items.value = response.data.data || []; pagination.value = response.data; }
        catch (requestError) { error.value = message(requestError); }
        finally { loading.value = false; }
    }, 180);
}
function clear() { search.value = ''; load(1); }
async function open(item = null) { form.value = item ? { ...item } : emptyForm(); provinces.value = form.value.department_id ? (await api.get('/location/provinces', { params: { department_id: form.value.department_id } })).data : []; districts.value = form.value.province_id ? (await api.get('/location/districts', { params: { province_id: form.value.province_id } })).data : []; modal.value = true; }
async function changeDepartment() { form.value.province_id = null; form.value.district_id = null; districts.value = []; provinces.value = form.value.department_id ? (await api.get('/location/provinces', { params: { department_id: form.value.department_id } })).data : []; }
async function changeProvince() { form.value.district_id = null; districts.value = form.value.province_id ? (await api.get('/location/districts', { params: { province_id: form.value.province_id } })).data : []; }
function close() { if (!saving.value) modal.value = false; }
async function save() {
    saving.value = true;
    try {
        form.value.id ? await api.put(`/admin/branches/${form.value.id}`, form.value) : await api.post('/admin/branches', form.value);
        modal.value = false;
        toast.add({ severity: 'success', summary: 'Sede guardada', life: 2500 });
        load();
    } catch (error) { toast.add({ severity: 'error', summary: 'No se pudo guardar', detail: message(error), life: 5000 }); }
    finally { saving.value = false; }
}
function makeMain(item) {
    confirm.require({
        header: 'Cambiar sede principal',
        message: `¿Establecer “${item.name}” como sede principal? La sede principal actual dejará de serlo. Esto no cambiará el almacén principal de venta web.`,
        acceptLabel: 'Sí, cambiar', rejectLabel: 'Cancelar',
        accept: async () => {
            try { await api.patch(`/admin/branches/${item.id}/main`); toast.add({ severity: 'success', summary: 'Sede principal actualizada', life: 3000 }); load(); }
            catch (error) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: message(error), life: 5000 }); }
        },
    });
}
function toggle(item) {
    confirm.require({
        header: 'Confirmar', message: `¿${item.is_active ? 'Desactivar' : 'Activar'} la sede “${item.name}”?`,
        accept: async () => {
            try { await api.patch(`/admin/branches/${item.id}/status`, { is_active: !item.is_active }); load(); }
            catch (error) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: message(error), life: 5000 }); }
        },
    });
}
function remove(item) {
    confirm.require({
        header: 'Eliminar sede', message: `¿Eliminar definitivamente la sede “${item.name}”?`,
        accept: async () => {
            try { await api.delete(`/admin/branches/${item.id}`); toast.add({ severity: 'success', summary: 'Sede eliminada', life: 2500 }); load(); }
            catch (error) { toast.add({ severity: 'warn', summary: 'No se pudo eliminar', detail: message(error), life: 5000 }); }
        },
    });
}

onMounted(async () => { departments.value = (await api.get('/location/departments')).data; load(); });
</script>
