<template>
    <AdminLayout>
        <main class="mx-auto max-w-7xl space-y-6" aria-labelledby="warehouses-title">
            <AdminPageHeader eyebrow="OPERACIÓN DE INVENTARIO" title="Administración de almacenes" title-id="warehouses-title" description="Gestiona los almacenes asociados a sedes operativas.">
                <template #action>
                <button type="button" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700" @click="open()"><i class="pi pi-plus mr-2"></i>Nuevo almacén</button>
                </template>
            </AdminPageHeader>

            <form class="mb-4 flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm md:flex-row" @submit.prevent="load(1)">
                <label class="sr-only" for="warehouse-search">Buscar almacén</label>
                <input id="warehouse-search" v-model.trim="search" type="search" placeholder="Buscar almacén o sede" class="min-h-11 flex-1 rounded-lg border border-slate-300 px-3">
                <label class="sr-only" for="warehouse-branch">Filtrar por sede</label>
                <select id="warehouse-branch" v-model="branchId" class="min-h-11 rounded-lg border border-slate-300 px-3 md:w-72">
                    <option value="">Todas las sedes</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.code }}</option>
                </select><div class="flex gap-2"><button class="min-h-11 rounded-lg bg-blue-700 px-4 font-bold text-white" :disabled="loading">Aplicar</button><button type="button" class="min-h-11 rounded-lg border px-4 font-bold" :disabled="loading" @click="clear">Limpiar</button><button type="button" class="min-h-11 w-11 rounded-lg border" title="Actualizar almacenes" aria-label="Actualizar almacenes" :disabled="loading" @click="load()"><i class="pi pi-refresh" :class="{ 'pi-spin': loading }"></i></button></div>
            </form>

            <section class="rounded-xl bg-white p-4 shadow-sm"><span class="text-sm font-semibold text-slate-500">Total de almacenes</span><strong class="mt-1 block text-3xl">{{ pagination.total || 0 }}</strong></section><section v-if="error" class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-900" role="alert">{{ error }} <button type="button" class="font-bold underline" @click="load()">Reintentar</button></section>
            <div v-if="loading" class="rounded-xl bg-white p-10 text-center text-slate-500 shadow-sm">Cargando almacenes…</div>
            <section v-else class="overflow-hidden rounded-2xl bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full min-w-[740px] text-left text-sm"><thead class="bg-slate-100 text-xs font-bold uppercase text-slate-600"><tr><th scope="col" class="p-4">Código</th><th scope="col" class="p-4">Almacén</th><th scope="col" class="p-4">Sede</th><th scope="col" class="p-4">Estado</th><th scope="col" class="p-4 text-right">Acciones</th></tr></thead><tbody><template v-if="items.length"><tr v-for="warehouse in items" :key="warehouse.id" class="border-t hover:bg-slate-50"><td class="p-4 font-mono text-xs">{{ warehouse.code }}</td><td class="p-4"><b>{{ warehouse.name }}</b><span v-if="warehouse.is_default" class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-bold text-blue-700">Predeterminado</span></td><td class="p-4 text-slate-600">{{ warehouse.branch?.name || 'Sin sede asignada' }}</td><td class="p-4"><AdminStatusBadge :active="!!warehouse.is_active" active-label="Activo" inactive-label="Inactivo" /></td><td class="p-4"><div class="flex justify-end gap-1"><AdminIconButton icon="pi pi-pencil" :label="`Editar ${warehouse.name}`" @click="open(warehouse)"/><AdminIconButton :icon="warehouse.is_active ? 'pi pi-ban' : 'pi pi-check-circle'" :label="`${warehouse.is_active ? 'Desactivar' : 'Activar'} ${warehouse.name}`" :tone="warehouse.is_active ? 'text-red-700' : 'text-emerald-700'" @click="toggle(warehouse)"/></div></td></tr></template><tr v-else><td colspan="5"><AdminEmptyState :icon="search || branchId ? 'pi pi-search' : 'pi pi-building'" :title="search || branchId ? 'No encontramos almacenes con los filtros seleccionados' : 'Todavía no hay almacenes registrados'" :description="search || branchId ? 'Prueba limpiando o ajustando los filtros.' : 'Crea el primer almacén desde este panel.'"/></td></tr></tbody></table></div></section>
            <AdminPagination :page="pagination.current_page || 1" :last-page="pagination.last_page || 1" :loading="loading" :summary="pagination.total ? `Mostrando ${pagination.from}–${pagination.to} de ${pagination.total} almacenes` : 'Sin almacenes para mostrar'" @change="load" />

            <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3" role="dialog" aria-modal="true" aria-labelledby="warehouse-modal-title" @mousedown.self="close">
                <form class="w-full max-w-xl rounded-xl bg-white p-5 shadow-2xl md:p-6" @submit.prevent="save">
                    <div class="mb-5 flex items-center justify-between"><h2 id="warehouse-modal-title" class="text-xl font-bold">{{ form.id ? 'Editar almacén' : 'Nuevo almacén' }}</h2><button type="button" class="h-11 w-11 rounded-lg hover:bg-slate-100" aria-label="Cerrar" @click="close"><i class="pi pi-times"></i></button></div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="text-sm font-medium md:col-span-2">Sede activa
                            <select v-model="form.branch_id" required class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2">
                                <option disabled value="">Seleccione una sede</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.code }}<template v-if="branch.district"> — {{ branch.district }}</template></option>
                            </select>
                        </label>
                        <label class="text-sm font-medium">Código<input v-model="form.code" required maxlength="50" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Nombre<input v-model="form.name" required maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium md:col-span-2">Dirección propia <span class="font-normal text-slate-500">(opcional)</span><input v-model="form.address" maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium md:col-span-2">Descripción <span class="font-normal text-slate-500">(opcional)</span><textarea v-model="form.description" rows="2" maxlength="2000" class="mt-1 block w-full rounded-lg border border-slate-300 p-2"></textarea></label>
                        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 p-3 md:col-span-2"><input v-model="form.is_default" type="checkbox" class="h-5 w-5">Almacén principal para venta web</label>
                        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 p-3 md:col-span-2"><input v-model="form.is_active" type="checkbox" class="h-5 w-5">Almacén activo</label>
                    </div>
                    <p class="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">La sede principal y el almacén principal de venta web son independientes. Cambiar uno no modifica el otro.</p>
                    <div class="mt-6 flex justify-end gap-3"><button type="button" class="min-h-11 rounded-lg bg-slate-100 px-4 font-semibold" @click="close">Cancelar</button><button :disabled="saving" class="min-h-11 rounded-lg bg-blue-600 px-4 font-semibold text-white disabled:opacity-60">{{ saving ? 'Guardando…' : 'Guardar almacén' }}</button></div>
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
import AdminIconButton from '@/components/admin/AdminIconButton.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminPagination from '@/components/admin/AdminPagination.vue';

const toast = useToast();
const confirm = useConfirm();
const items = ref([]);
const branches = ref([]);
const search = ref('');
const branchId = ref('');
const loading = ref(false);
const error = ref('');
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const modal = ref(false);
const saving = ref(false);
const form = ref({});
let timer;
const message = error => error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'No se pudo completar la operación.';

async function loadBranches() { branches.value = (await api.get('/admin/branches/options')).data; }
function load(targetPage = pagination.value.current_page || 1) {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (loading.value) return;
        loading.value = true; error.value = '';
        try { const response = await api.get('/admin/warehouses', { params: { page: targetPage, search: search.value || undefined, branch_id: branchId.value || undefined } }); items.value = response.data.data || []; pagination.value = response.data; }
        catch (requestError) { error.value = message(requestError); }
        finally { loading.value = false; }
    }, 180);
}
function clear() { search.value = ''; branchId.value = ''; load(1); }
function open(item = null) { form.value = item ? { ...item } : { branch_id: '', code: '', name: '', address: '', description: '', is_default: false, is_active: true }; modal.value = true; }
function close() { if (!saving.value) modal.value = false; }
async function persist() { return form.value.id ? api.put(`/admin/warehouses/${form.value.id}`, form.value) : api.post('/admin/warehouses', form.value); }
async function save() {
    const changingDefault = form.value.is_default && (!form.value.id || !items.value.find(item => item.id === form.value.id)?.is_default);
    const execute = async () => {
        saving.value = true;
        try { await persist(); modal.value = false; toast.add({ severity: 'success', summary: 'Almacén guardado', life: 2500 }); load(); }
        catch (error) { toast.add({ severity: 'error', summary: 'No se pudo guardar', detail: message(error), life: 5000 }); }
        finally { saving.value = false; }
    };
    if (!changingDefault) return execute();
    confirm.require({ header: 'Cambiar almacén principal', message: 'Este almacén será el único utilizado para reservar las ventas web. La sede principal no cambiará.', acceptLabel: 'Sí, cambiar', rejectLabel: 'Cancelar', accept: execute });
}
function toggle(warehouse) {
    confirm.require({
        header: 'Confirmar', message: `¿${warehouse.is_active ? 'Desactivar' : 'Activar'} el almacén “${warehouse.name}”?`,
        accept: async () => {
            try { await api.patch(`/admin/warehouses/${warehouse.id}/status`, { is_active: !warehouse.is_active }); load(); }
            catch (error) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: message(error), life: 5000 }); }
        },
    });
}
onMounted(async () => { try { await loadBranches(); } catch (error) { toast.add({ severity: 'error', summary: 'No se pudieron cargar las sedes', detail: message(error), life: 5000 }); } load(); });
</script>
