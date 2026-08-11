<template>
    <AdminLayout>
        <div class="container mx-auto px-3 py-6 md:px-6">
            <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-3xl font-semibold text-slate-800">Sedes</h1>
                    <p class="text-sm text-slate-500">Ubicaciones comerciales y operativas de LubriStore</p>
                </div>
                <button type="button" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700" @click="open()">
                    <i class="pi pi-plus mr-2" aria-hidden="true"></i>Nueva sede
                </button>
            </div>

            <div v-if="!loading && items.length && !items.some(item => item.is_main)" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                No hay una sede principal definida. Seleccione una sede activa y use “Establecer como principal”.
            </div>

            <div class="mb-4 rounded-xl bg-white p-4 shadow-sm">
                <label class="sr-only" for="branch-search">Buscar sede</label>
                <input id="branch-search" v-model="search" type="search" placeholder="Buscar por código, nombre o distrito" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 md:w-96" @input="load">
            </div>

            <div v-if="loading" class="rounded-xl bg-white p-10 text-center text-slate-500 shadow-sm">Cargando sedes…</div>
            <div v-else class="grid gap-4 lg:grid-cols-2">
                <article v-for="item in items" :key="item.id" class="rounded-xl border bg-white p-5 shadow-sm" :class="item.is_main ? 'border-blue-300 ring-1 ring-blue-100' : 'border-slate-200'">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="mb-1 flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs text-slate-500">{{ item.code }}</span>
                                <span v-if="item.is_main" class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700">Sede principal</span>
                            </div>
                            <h2 class="text-lg font-bold text-slate-800">{{ item.name }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ location(item) }}</p>
                            <p class="text-sm text-slate-500">{{ item.address }}</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="item.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                            {{ item.is_active ? 'Activa' : 'Inactiva' }}
                        </span>
                    </div>

                    <div class="my-4 grid grid-cols-3 gap-2 text-center text-sm">
                        <div class="rounded-lg bg-slate-50 p-3"><strong class="block text-lg">{{ item.warehouses_count }}</strong><span class="text-xs text-slate-500">Almacenes</span></div>
                        <div class="rounded-lg bg-slate-50 p-3"><i class="pi text-lg" :class="item.allows_pickup ? 'pi-check text-emerald-600' : 'pi-times text-slate-400'"></i><span class="block text-xs text-slate-500">Permite recojo</span></div>
                        <div class="rounded-lg bg-slate-50 p-3"><i class="pi text-lg" :class="item.serves_public ? 'pi-check text-emerald-600' : 'pi-times text-slate-400'"></i><span class="block text-xs text-slate-500">Atiende público</span></div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                        <button v-if="item.is_active && !item.is_main" type="button" class="min-h-10 rounded-lg border border-blue-300 px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50" @click="makeMain(item)">Establecer como principal</button>
                        <button type="button" class="min-h-10 rounded-lg px-3 text-blue-700 hover:bg-blue-50" :aria-label="`Editar ${item.name}`" @click="open(item)"><i class="pi pi-pencil" aria-hidden="true"></i></button>
                        <button type="button" class="min-h-10 rounded-lg px-3" :class="item.is_active ? 'text-red-700 hover:bg-red-50' : 'text-emerald-700 hover:bg-emerald-50'" :aria-label="`${item.is_active ? 'Desactivar' : 'Activar'} ${item.name}`" @click="toggle(item)"><i class="pi" :class="item.is_active ? 'pi-ban' : 'pi-check-circle'" aria-hidden="true"></i></button>
                        <button v-if="!item.is_active && !item.is_main && item.warehouses_count === 0" type="button" class="min-h-10 rounded-lg px-3 text-red-700 hover:bg-red-50" :aria-label="`Eliminar ${item.name}`" @click="remove(item)"><i class="pi pi-trash" aria-hidden="true"></i></button>
                    </div>
                </article>
                <div v-if="!items.length" class="rounded-xl bg-white p-10 text-center text-slate-500 shadow-sm lg:col-span-2">No se encontraron sedes.</div>
            </div>

            <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3" role="dialog" aria-modal="true" aria-labelledby="branch-modal-title" @mousedown.self="close">
                <form class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white p-5 shadow-2xl md:p-6" @submit.prevent="save">
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <h2 id="branch-modal-title" class="text-xl font-bold text-slate-800">{{ form.id ? 'Editar sede' : 'Nueva sede' }}</h2>
                        <button type="button" class="h-11 w-11 rounded-lg text-slate-600 hover:bg-slate-100" aria-label="Cerrar" @click="close"><i class="pi pi-times"></i></button>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="text-sm font-medium">Código<input v-model="form.code" required maxlength="50" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Nombre<input v-model="form.name" required maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium md:col-span-2">Dirección<input v-model="form.address" required maxlength="255" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Departamento<input v-model="form.department" required maxlength="100" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Provincia<input v-model="form.province" required maxlength="100" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                        <label class="text-sm font-medium">Distrito<input v-model="form.district" required maxlength="100" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
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
const search = ref('');
const loading = ref(false);
const modal = ref(false);
const saving = ref(false);
const form = ref({});
let timer;

const emptyForm = () => ({
    code: '', name: '', address: '', department: '', province: '', district: '', reference: '',
    phone: '', email: '', business_hours: '', pickup_instructions: '', description: '',
    allows_pickup: false, serves_public: false, is_active: true,
});
const location = item => [item.district, item.province, item.department].filter(Boolean).join(' — ') || 'Ubicación pendiente de completar';
const message = error => error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'No se pudo completar la operación.';

function load() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        loading.value = true;
        try { items.value = (await api.get('/admin/branches', { params: { search: search.value } })).data.data; }
        catch (error) { toast.add({ severity: 'error', summary: 'Error', detail: message(error), life: 5000 }); }
        finally { loading.value = false; }
    }, 180);
}
function open(item = null) { form.value = item ? { ...item } : emptyForm(); modal.value = true; }
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

onMounted(load);
</script>
