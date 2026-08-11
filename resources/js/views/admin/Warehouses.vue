<template>
    <AdminLayout>
        <div class="container mx-auto px-3 py-6 md:px-6">
            <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div><h1 class="text-3xl font-semibold text-slate-800">Almacenes</h1><p class="text-sm text-slate-500">Inventario físico asociado a sedes reales</p></div>
                <button type="button" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700" @click="open()"><i class="pi pi-plus mr-2"></i>Nuevo almacén</button>
            </div>

            <div class="mb-4 flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm md:flex-row">
                <label class="sr-only" for="warehouse-search">Buscar almacén</label>
                <input id="warehouse-search" v-model="search" type="search" placeholder="Buscar almacén o sede" class="min-h-11 flex-1 rounded-lg border border-slate-300 px-3" @input="load">
                <label class="sr-only" for="warehouse-branch">Filtrar por sede</label>
                <select id="warehouse-branch" v-model="branchId" class="min-h-11 rounded-lg border border-slate-300 px-3 md:w-72" @change="load">
                    <option value="">Todas las sedes</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.code }}</option>
                </select>
            </div>

            <div v-if="loading" class="rounded-xl bg-white p-10 text-center text-slate-500 shadow-sm">Cargando almacenes…</div>
            <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="warehouse in items" :key="warehouse.id" class="rounded-xl border bg-white p-5 shadow-sm" :class="warehouse.is_default ? 'border-blue-300 ring-1 ring-blue-100' : 'border-slate-200'">
                    <div class="flex items-start justify-between gap-3">
                        <div><div class="font-mono text-xs text-slate-500">{{ warehouse.code }}</div><h2 class="text-lg font-bold text-slate-800">{{ warehouse.name }}</h2></div>
                        <span v-if="warehouse.is_default" class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700">Predeterminado</span>
                    </div>
                    <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="font-semibold text-slate-800">{{ warehouse.branch?.name || 'Sede no disponible' }}</div>
                        <div class="text-slate-500">{{ warehouse.branch?.code }}<template v-if="warehouse.branch?.district"> — {{ warehouse.branch.district }}</template></div>
                    </div>
                    <div class="my-4 grid grid-cols-2 gap-2 text-center">
                        <div class="rounded-lg bg-slate-50 p-2"><b>{{ warehouse.total_stock || 0 }}</b><div class="text-xs text-slate-500">unidades</div></div>
                        <div class="rounded-lg bg-slate-50 p-2"><b>{{ warehouse.inventories_count }}</b><div class="text-xs text-slate-500">productos</div></div>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 pt-4">
                        <span class="text-sm font-semibold" :class="warehouse.is_active ? 'text-emerald-600' : 'text-slate-500'">{{ warehouse.is_active ? 'Activo' : 'Inactivo' }}</span>
                        <div class="flex gap-1">
                            <button type="button" class="min-h-10 rounded-lg px-3 text-blue-700 hover:bg-blue-50" :aria-label="`Editar ${warehouse.name}`" @click="open(warehouse)"><i class="pi pi-pencil"></i></button>
                            <button type="button" class="min-h-10 rounded-lg px-3" :class="warehouse.is_active ? 'text-red-700 hover:bg-red-50' : 'text-emerald-700 hover:bg-emerald-50'" :aria-label="`${warehouse.is_active ? 'Desactivar' : 'Activar'} ${warehouse.name}`" @click="toggle(warehouse)"><i class="pi" :class="warehouse.is_active ? 'pi-ban' : 'pi-check-circle'"></i></button>
                        </div>
                    </div>
                </article>
                <div v-if="!items.length" class="rounded-xl bg-white p-10 text-center text-slate-500 shadow-sm">No se encontraron almacenes.</div>
            </div>

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
        </div>
    </AdminLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';

const toast = useToast();
const confirm = useConfirm();
const items = ref([]);
const branches = ref([]);
const search = ref('');
const branchId = ref('');
const loading = ref(false);
const modal = ref(false);
const saving = ref(false);
const form = ref({});
let timer;
const message = error => error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'No se pudo completar la operación.';

async function loadBranches() { branches.value = (await api.get('/admin/branches/options')).data; }
function load() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        loading.value = true;
        try { items.value = (await api.get('/admin/warehouses', { params: { search: search.value, branch_id: branchId.value } })).data.data; }
        catch (error) { toast.add({ severity: 'error', summary: 'Error', detail: message(error), life: 5000 }); }
        finally { loading.value = false; }
    }, 180);
}
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
