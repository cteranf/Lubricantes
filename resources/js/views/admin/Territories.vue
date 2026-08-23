<template>
    <AdminLayout>
        <div class="space-y-6">
            <header class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-3xl font-black text-slate-900">División territorial</h1><p class="text-sm text-slate-600">Catálogo administrable de departamentos, provincias y distritos.</p></div><div class="flex flex-wrap gap-2"><button type="button" class="min-h-11 rounded-lg border border-blue-300 px-4 font-bold text-blue-700" @click="downloadTemplate">Descargar plantilla</button><button type="button" class="min-h-11 rounded-lg bg-blue-700 px-4 font-bold text-white" @click="openImport">Importar catálogo</button></div></header>
            <div class="flex overflow-x-auto rounded-xl bg-white p-2 shadow-sm" role="tablist">
                <button v-for="option in tabs" :key="option.value" type="button" class="min-h-11 whitespace-nowrap rounded-lg px-4 font-bold" :class="tab === option.value ? 'bg-blue-700 text-white' : 'text-slate-700'" @click="tab = option.value; load()">{{ option.label }}</button>
            </div>

            <section class="rounded-xl bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-wrap gap-3">
                    <input v-model="search" type="search" class="min-h-11 min-w-0 flex-1 rounded-lg border px-3" :placeholder="`Buscar ${singular} por nombre o código`" @keyup.enter="load">
                    <select v-if="tab !== 'departments'" v-model="departmentFilter" class="min-h-11 rounded-lg border px-3" @change="onDepartmentFilter"><option value="">Todos los departamentos</option><option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option></select>
                    <select v-if="tab === 'districts'" v-model="provinceFilter" class="min-h-11 rounded-lg border px-3" @change="load"><option value="">Todas las provincias</option><option v-for="item in filterProvinces" :key="item.id" :value="item.id">{{ item.name }}</option></select>
                    <button type="button" class="min-h-11 rounded-lg border px-4 font-bold text-slate-700" @click="load">Buscar</button>
                    <button type="button" class="min-h-11 rounded-lg bg-blue-700 px-4 font-bold text-white" @click="open()">Nuevo {{ singular }}</button>
                </div>
                <div v-if="loading" class="py-12 text-center text-slate-500">Cargando ubicaciones…</div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-3">Código</th><th class="p-3">Nombre / ruta</th><th v-if="tab === 'districts'" class="p-3">Ubigeo</th><th class="p-3">Relaciones</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead><tbody>
                        <tr v-for="item in items" :key="item.id" class="border-b"><td class="p-3 font-mono">{{ item.code }}</td><td class="p-3"><b>{{ item.name }}</b><div v-if="tab === 'provinces'" class="text-xs text-slate-500">{{ item.department?.name }}</div><div v-if="tab === 'districts'" class="text-xs text-slate-500">{{ item.province?.department?.name }} → {{ item.province?.name }} → {{ item.name }}</div></td><td v-if="tab === 'districts'" class="p-3">{{ item.ubigeo || '—' }}</td><td class="p-3">{{ relationCount(item) }}</td><td class="p-3"><span class="rounded-full px-2 py-1 text-xs font-bold" :class="item.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'">{{ item.is_active ? 'Activo' : 'Inactivo' }}</span></td><td class="p-3 text-right"><button class="mr-3 font-bold text-blue-700" @click="open(item)">Editar</button><button class="mr-3 font-bold" :class="item.is_active ? 'text-red-700' : 'text-emerald-700'" @click="toggle(item)">{{ item.is_active ? 'Desactivar' : 'Activar' }}</button><button v-if="!item.is_active" class="font-bold text-red-700" @click="remove(item)">Eliminar</button></td></tr>
                    </tbody></table>
                    <p v-if="!items.length" class="py-10 text-center text-slate-500">No se encontraron registros.</p>
                </div>
            </section>
        </div>

        <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" @mousedown.self="modal = false">
            <form class="w-full max-w-lg space-y-4 rounded-xl bg-white p-6" @submit.prevent="save">
                <h2 class="text-xl font-black">{{ form.id ? 'Editar' : 'Nuevo' }} {{ singular }}</h2>
                <label v-if="tab !== 'departments'" class="block text-sm font-medium">Departamento<select v-model="form.department_id" required class="mt-1 min-h-11 w-full rounded-lg border px-3" @change="loadFormProvinces"><option :value="null" disabled>Selecciona</option><option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                <label v-if="tab === 'districts'" class="block text-sm font-medium">Provincia<select v-model="form.province_id" required :disabled="!form.department_id" class="mt-1 min-h-11 w-full rounded-lg border px-3 disabled:bg-slate-100"><option :value="null" disabled>Selecciona</option><option v-for="item in formProvinces" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                <label class="block text-sm font-medium">Código<input v-model.trim="form.code" required maxlength="50" class="mt-1 min-h-11 w-full rounded-lg border px-3"></label>
                <label class="block text-sm font-medium">Nombre<input v-model.trim="form.name" required maxlength="100" class="mt-1 min-h-11 w-full rounded-lg border px-3"></label>
                <label v-if="tab === 'districts'" class="block text-sm font-medium">Ubigeo <span class="font-normal text-slate-500">(opcional)</span><input v-model.trim="form.ubigeo" maxlength="20" class="mt-1 min-h-11 w-full rounded-lg border px-3"></label>
                <label class="flex min-h-11 items-center gap-3"><input v-model="form.is_active" type="checkbox" class="h-5 w-5"> Registro activo</label>
                <div class="flex justify-end gap-3"><button type="button" class="min-h-11 px-4" @click="modal = false">Cancelar</button><button :disabled="saving" class="min-h-11 rounded-lg bg-blue-700 px-4 font-bold text-white disabled:opacity-50">{{ saving ? 'Guardando…' : 'Guardar' }}</button></div>
            </form>
        </div>

        <div v-if="importModal" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/60 p-3" role="dialog" aria-modal="true" aria-labelledby="territory-import-title" @mousedown.self="closeImport">
            <div class="my-5 max-h-[94vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white p-5 shadow-2xl md:p-6">
                <div class="mb-5 flex items-start justify-between gap-4"><div><h2 id="territory-import-title" class="text-2xl font-black">Importar catálogo territorial</h2><p class="text-sm text-slate-600">CSV UTF-8 · límite configurado por el servidor · la vista previa no modifica el catálogo.</p></div><button type="button" class="h-11 w-11 rounded-lg hover:bg-slate-100" aria-label="Cerrar" @click="closeImport"><i class="pi pi-times"></i></button></div>

                <div v-if="!preview && !importResult" class="space-y-4">
                    <label class="block rounded-xl border-2 border-dashed border-slate-300 p-6 text-center"><span class="mb-2 block font-bold">Selecciona el archivo oficial</span><input type="file" accept=".csv,text/csv" class="mx-auto block max-w-full" @change="selectImportFile"></label>
                    <p v-if="importFile" class="rounded-lg bg-slate-50 p-3 text-sm"><b>Archivo:</b> {{ importFile.name }} · {{ Math.ceil(importFile.size / 1024) }} KB</p>
                    <div class="flex flex-wrap justify-between gap-3"><button type="button" class="min-h-11 font-bold text-blue-700 underline" @click="downloadTemplate">Descargar plantilla CSV</button><button type="button" :disabled="!importFile || importLoading" class="min-h-11 rounded-lg bg-blue-700 px-5 font-bold text-white disabled:opacity-50" @click="previewImport">{{ importLoading ? 'Validando…' : 'Generar vista previa' }}</button></div>
                </div>

                <div v-else-if="preview" class="space-y-5">
                    <div class="rounded-xl bg-slate-50 p-4"><div class="font-bold">{{ preview.original_filename }}</div><div class="break-all font-mono text-xs text-slate-500">SHA-256 {{ preview.checksum }}</div></div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><div v-for="metric in previewMetrics" :key="metric.label" class="rounded-xl border p-4"><strong class="block text-2xl">{{ metric.value }}</strong><span class="text-sm text-slate-600">{{ metric.label }}</span></div></div>
                    <div v-if="!preview.can_confirm" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900"><b>No se puede confirmar.</b> Corrige duplicados, errores y conflictos estructurales y vuelve a generar la vista previa.</div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[920px] text-left text-xs"><thead><tr class="border-b text-slate-500"><th class="p-2">Fila</th><th class="p-2">Estado</th><th class="p-2">Departamento</th><th class="p-2">Provincia</th><th class="p-2">Distrito</th><th class="p-2">UBIGEO</th><th class="p-2">Observaciones</th></tr></thead><tbody><tr v-for="row in preview.sample" :key="row.row" class="border-b"><td class="p-2">{{ row.row }}</td><td class="p-2 font-bold">{{ statusLabel(row.status) }}</td><td class="p-2">{{ row.data.department_code }} · {{ row.data.department_name }}</td><td class="p-2">{{ row.data.province_code }} · {{ row.data.province_name }}</td><td class="p-2">{{ row.data.district_code }} · {{ row.data.district_name }}</td><td class="p-2">{{ row.data.ubigeo || '—' }}</td><td class="p-2 text-red-700">{{ row.messages.join(' ') || '—' }}</td></tr></tbody></table></div>
                    <p class="text-xs text-slate-500">Se muestran como máximo 50 filas. El reporte CSV contiene todos los errores y conflictos.</p>
                    <div class="flex flex-wrap justify-between gap-3"><div class="flex gap-2"><button type="button" class="min-h-11 rounded-lg border px-4 font-bold" @click="resetImport">Elegir otro archivo</button><button v-if="!preview.can_confirm" type="button" class="min-h-11 rounded-lg border border-red-300 px-4 font-bold text-red-700" @click="downloadReport">Descargar reporte</button></div><button type="button" :disabled="!preview.can_confirm || importLoading" class="min-h-11 rounded-lg bg-emerald-700 px-5 font-bold text-white disabled:opacity-50" @click="confirmImport">{{ importLoading ? 'Importando…' : 'Confirmar importación' }}</button></div>
                </div>

                <div v-else class="space-y-5"><div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900"><h3 class="text-xl font-black">{{ importResult.summary?.message || 'Importación completada' }}</h3><p>El archivo temporal fue eliminado y el resultado quedó registrado.</p></div><div class="grid gap-3 sm:grid-cols-3"><div class="rounded-xl border p-4"><strong class="text-2xl">{{ importResult.created_departments }}</strong><span class="block text-sm">Departamentos creados</span></div><div class="rounded-xl border p-4"><strong class="text-2xl">{{ importResult.created_provinces }}</strong><span class="block text-sm">Provincias creadas</span></div><div class="rounded-xl border p-4"><strong class="text-2xl">{{ importResult.created_districts }}</strong><span class="block text-sm">Distritos creados</span></div></div><div class="flex justify-end"><button type="button" class="min-h-11 rounded-lg bg-blue-700 px-5 font-bold text-white" @click="finishImport">Cerrar y actualizar catálogo</button></div></div>

                <section v-if="importHistory.length" class="mt-7 border-t pt-5"><h3 class="mb-3 font-black">Importaciones recientes</h3><div class="overflow-x-auto"><table class="w-full min-w-[650px] text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-2">Fecha</th><th class="p-2">Archivo</th><th class="p-2">Administrador</th><th class="p-2">Filas</th><th class="p-2">Resultado</th></tr></thead><tbody><tr v-for="item in importHistory" :key="item.id" class="border-b"><td class="p-2">{{ new Date(item.completed_at || item.created_at).toLocaleString('es-PE') }}</td><td class="p-2">{{ item.original_filename }}</td><td class="p-2">{{ item.user?.name || 'Usuario eliminado' }}</td><td class="p-2">{{ item.total_rows }}</td><td class="p-2 font-bold">{{ item.summary?.message || item.status }}</td></tr></tbody></table></div></section>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';

const toast = useToast();
const tabs = [{ value: 'departments', label: 'Departamentos' }, { value: 'provinces', label: 'Provincias' }, { value: 'districts', label: 'Distritos' }];
const tab = ref('departments'), items = ref([]), departments = ref([]), filterProvinces = ref([]), formProvinces = ref([]);
const search = ref(''), departmentFilter = ref(''), provinceFilter = ref(''), loading = ref(false), saving = ref(false), modal = ref(false), form = ref({});
const importModal = ref(false), importFile = ref(null), importLoading = ref(false), preview = ref(null), importResult = ref(null), importHistory = ref([]);
const singular = computed(() => ({ departments: 'departamento', provinces: 'provincia', districts: 'distrito' })[tab.value]);
const message = error => Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'No se pudo completar la operación.';
const relationCount = item => tab.value === 'departments' ? `${item.provinces_count} provincias · ${item.districts_count} distritos` : tab.value === 'provinces' ? `${item.districts_count} distritos` : 'Ruta territorial';
const endpoint = () => `/admin/${tab.value}`;
const previewMetrics = computed(() => preview.value ? [
    { label: 'Filas', value: preview.value.total_rows }, { label: 'Departamentos detectados', value: preview.value.departments_detected },
    { label: 'Provincias detectadas', value: preview.value.provinces_detected }, { label: 'Distritos detectados', value: preview.value.districts_detected },
    { label: 'Departamentos nuevos', value: preview.value.new_departments }, { label: 'Provincias nuevas', value: preview.value.new_provinces },
    { label: 'Distritos nuevos', value: preview.value.new_districts }, { label: 'Nombres a actualizar', value: preview.value.name_updates },
    { label: 'Sin cambios', value: preview.value.unchanged_rows }, { label: 'UBIGEO por completar', value: preview.value.updated_ubigeos },
    { label: 'Duplicadas', value: preview.value.duplicate_rows }, { label: 'Conflictos / errores', value: preview.value.conflict_rows + preview.value.error_rows },
] : []);

async function loadDepartments() { departments.value = (await api.get('/location/departments')).data; }
async function load() { loading.value = true; try { const params = { search: search.value }; if (departmentFilter.value) params.department_id = departmentFilter.value; if (provinceFilter.value) params.province_id = provinceFilter.value; items.value = (await api.get(endpoint(), { params })).data.data; } catch (error) { toast.add({ severity: 'error', summary: 'Error', detail: message(error), life: 5000 }); } finally { loading.value = false; } }
async function onDepartmentFilter() { provinceFilter.value = ''; filterProvinces.value = departmentFilter.value ? (await api.get('/location/provinces', { params: { department_id: departmentFilter.value } })).data : []; load(); }
async function loadFormProvinces() { form.value.province_id = null; formProvinces.value = form.value.department_id ? (await api.get('/location/provinces', { params: { department_id: form.value.department_id } })).data : []; }
async function open(item = null) { form.value = item ? { ...item, department_id: item.department_id || item.province?.department_id } : { id: null, code: '', name: '', ubigeo: '', department_id: null, province_id: null, is_active: true }; formProvinces.value = form.value.department_id ? (await api.get('/location/provinces', { params: { department_id: form.value.department_id } })).data : []; modal.value = true; }
async function save() { saving.value = true; try { const payload = { ...form.value }; delete payload.id; delete payload.department; delete payload.province; if (tab.value === 'provinces') delete payload.province_id; if (tab.value === 'departments') { delete payload.department_id; delete payload.province_id; delete payload.ubigeo; } form.value.id ? await api.put(`${endpoint()}/${form.value.id}`, payload) : await api.post(endpoint(), payload); modal.value = false; await Promise.all([loadDepartments(), load()]); toast.add({ severity: 'success', summary: 'Ubicación guardada', life: 2500 }); } catch (error) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: message(error), life: 5000 }); } finally { saving.value = false; } }
async function toggle(item) { try { await api.patch(`${endpoint()}/${item.id}/status`, { is_active: !item.is_active }); await Promise.all([loadDepartments(), load()]); } catch (error) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: message(error), life: 5000 }); } }
async function remove(item) { if (!confirm(`¿Eliminar definitivamente “${item.name}”?`)) return; try { await api.delete(`${endpoint()}/${item.id}`); load(); } catch (error) { toast.add({ severity: 'warn', summary: 'No se pudo eliminar', detail: message(error), life: 5000 }); } }
function saveBlob(data, filename) { const url = URL.createObjectURL(new Blob([data], { type: 'text/csv;charset=utf-8' })); const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.click(); URL.revokeObjectURL(url); }
async function downloadTemplate() { try { const response = await api.get('/admin/territories/import/template', { responseType: 'blob' }); saveBlob(response.data, 'plantilla-catalogo-territorial-peru.csv'); } catch (error) { toast.add({ severity: 'error', summary: 'No se pudo descargar', detail: message(error), life: 5000 }); } }
async function openImport() { importModal.value = true; try { importHistory.value = (await api.get('/admin/territories/imports')).data.data; } catch { importHistory.value = []; } }
function closeImport() { if (!importLoading.value) importModal.value = false; }
function selectImportFile(event) { importFile.value = event.target.files?.[0] || null; preview.value = null; importResult.value = null; }
function resetImport() { importFile.value = null; preview.value = null; importResult.value = null; }
function statusLabel(status) { return ({ new: 'Nuevo', unchanged: 'Sin cambios', update_ubigeo: 'Nuevo UBIGEO', duplicate: 'Duplicado', conflict: 'Conflicto', error: 'Error' })[status] || status; }
async function previewImport() { importLoading.value = true; try { const body = new FormData(); body.append('file', importFile.value); preview.value = (await api.post('/admin/territories/import/preview', body, { headers: { 'Content-Type': 'multipart/form-data' } })).data; } catch (error) { toast.add({ severity: 'error', summary: 'Archivo rechazado', detail: message(error), life: 6000 }); } finally { importLoading.value = false; } }
async function confirmImport() { if (!preview.value?.can_confirm || !confirm('¿Confirmar la importación? La operación será atómica y no podrá revertirse automáticamente.')) return; importLoading.value = true; try { importResult.value = (await api.post('/admin/territories/import/confirm', { preview_token: preview.value.preview_token })).data; preview.value = null; importHistory.value = (await api.get('/admin/territories/imports')).data.data; } catch (error) { toast.add({ severity: 'error', summary: 'Importación cancelada', detail: message(error), life: 6000 }); } finally { importLoading.value = false; } }
async function downloadReport() { try { const response = await api.get('/admin/territories/import/report', { params: { preview_token: preview.value.preview_token }, responseType: 'blob' }); saveBlob(response.data, 'reporte-importacion-territorial.csv'); } catch (error) { toast.add({ severity: 'error', summary: 'No se pudo descargar', detail: message(error), life: 5000 }); } }
async function finishImport() { importModal.value = false; resetImport(); await Promise.all([loadDepartments(), load()]); }
onMounted(async () => { await loadDepartments(); await load(); });
</script>
