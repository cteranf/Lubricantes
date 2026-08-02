<template>
    <AdminLayout>
        <div class="mx-auto max-w-7xl">
            <header class="mb-6">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-700">Atención al cliente</p>
                <h1 class="mt-1 text-3xl font-black text-slate-950">Consultas de contacto</h1>
                <p class="mt-2 text-slate-600">Gestiona consultas, asignaciones, notas e historial sin eliminar la trazabilidad.</p>
            </header>

            <form class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="applyFilters">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-sm font-bold text-slate-700 xl:col-span-2">Buscar
                        <input v-model="filters.search" type="search" class="admin-input mt-1.5" placeholder="Referencia, nombre, correo, teléfono, asunto o mensaje">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Estado
                        <select v-model="filters.status" class="admin-input mt-1.5"><option value="">Todos</option><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">Responsable
                        <select v-model="filters.assigned_to" class="admin-input mt-1.5"><option value="">Todos</option><option value="unassigned">Sin asignar</option><option v-for="admin in admins" :key="admin.id" :value="String(admin.id)">{{ admin.name }}</option></select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">Desde
                        <input v-model="filters.date_from" type="date" class="admin-input mt-1.5">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Hasta
                        <input v-model="filters.date_to" type="date" class="admin-input mt-1.5">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Visibilidad
                        <select v-model="filters.archived" class="admin-input mt-1.5"><option value="active">Activas</option><option value="archived">Archivadas</option><option value="all">Todas</option></select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">Orden
                        <select v-model="filters.sort" class="admin-input mt-1.5"><option value="received_desc">Más recientes</option><option value="received_asc">Más antiguas</option><option value="activity_desc">Actividad reciente</option><option value="activity_asc">Actividad antigua</option></select>
                    </label>
                </div>
                <div class="mt-4 flex flex-wrap gap-3"><button type="submit" class="btn-primary">Aplicar filtros</button><button type="button" class="btn-outline" @click="clearFilters">Limpiar</button></div>
            </form>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="inquiries-heading">
                <h2 id="inquiries-heading" class="sr-only">Resultados de consultas</h2>
                <div v-if="loading" class="p-10 text-center text-slate-600" aria-busy="true"><i class="pi pi-spin pi-spinner mr-2" aria-hidden="true"></i>Cargando consultas…</div>
                <div v-else-if="loadError" class="p-10 text-center" role="alert"><i class="pi pi-exclamation-circle text-3xl text-red-500" aria-hidden="true"></i><p class="mt-3 text-slate-700">{{ loadError }}</p><button type="button" class="btn-primary mt-4" @click="load">Reintentar</button></div>
                <div v-else-if="!items.length" class="p-10 text-center text-slate-600"><i class="pi pi-inbox text-4xl text-slate-400" aria-hidden="true"></i><h3 class="mt-3 text-lg font-black text-slate-900">No hay consultas para estos filtros</h3><p class="mt-1">Cambia los criterios o limpia la búsqueda.</p></div>

                <template v-else>
                    <div class="space-y-3 p-4 md:hidden">
                        <article v-for="item in items" :key="item.public_id" class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-mono text-xs font-bold text-blue-700">{{ item.public_id }}</p><h3 class="mt-1 font-black text-slate-900">{{ item.subject }}</h3></div><span class="status-badge" :class="statusClass(item.status)">{{ statusLabel(item.status) }}</span></div>
                            <p class="mt-3 font-semibold text-slate-800">{{ item.name }}</p><p class="text-sm text-slate-500">{{ formatDate(item.created_at) }}</p>
                            <div class="mt-3 flex items-center justify-between gap-3 text-sm"><span class="truncate text-slate-600">{{ item.assignee?.name || 'Sin asignar' }}</span><button type="button" class="min-h-11 rounded-lg px-3 font-bold text-blue-700 hover:bg-blue-50" @click="openDetail(item.public_id)">Ver consulta</button></div>
                        </article>
                    </div>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm"><thead class="bg-slate-100 text-xs uppercase tracking-wide text-slate-600"><tr><th class="p-4 text-left">Referencia / fecha</th><th class="p-4 text-left">Persona</th><th class="p-4 text-left">Asunto</th><th class="p-4 text-center">Estado</th><th class="p-4 text-left">Responsable</th><th class="p-4 text-left">Última actividad</th><th class="p-4 text-center">Acción</th></tr></thead>
                            <tbody><tr v-for="item in items" :key="item.public_id" class="border-t border-slate-200 hover:bg-slate-50"><td class="p-4"><span class="font-mono text-xs font-bold text-blue-700">{{ item.public_id }}</span><div class="mt-1 text-xs text-slate-500">{{ formatDate(item.created_at) }}</div></td><td class="p-4"><strong class="block text-slate-900">{{ item.name }}</strong><span class="text-xs text-slate-500">{{ item.email }}</span></td><td class="max-w-xs p-4 font-semibold text-slate-800"><span class="line-clamp-2">{{ item.subject }}</span></td><td class="p-4 text-center"><span class="status-badge" :class="statusClass(item.status)">{{ statusLabel(item.status) }}</span></td><td class="p-4 text-slate-700">{{ item.assignee?.name || 'Sin asignar' }}</td><td class="p-4 text-xs text-slate-500">{{ formatDate(item.last_activity_at) }}</td><td class="p-4 text-center"><button type="button" class="min-h-11 rounded-lg px-3 font-bold text-blue-700 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="openDetail(item.public_id)"><i class="pi pi-eye mr-1" aria-hidden="true"></i>Ver</button></td></tr></tbody>
                        </table>
                    </div>
                </template>
            </section>

            <nav v-if="meta.last_page > 1" class="mt-5 flex items-center justify-center gap-3" aria-label="Paginación de consultas"><button type="button" class="btn-outline" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Anterior</button><span class="text-sm font-bold text-slate-700">Página {{ meta.current_page }} de {{ meta.last_page }}</span><button type="button" class="btn-outline" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Siguiente</button></nav>
        </div>

        <div v-if="detailOpen" class="fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-0 sm:p-4" @mousedown.self="closeDetail">
            <section ref="detailPanel" role="dialog" aria-modal="true" aria-labelledby="detail-title" tabindex="-1" class="ml-auto min-h-full w-full max-w-4xl bg-white p-5 shadow-2xl outline-none sm:min-h-0 sm:rounded-2xl sm:p-7" @keydown="handleDetailKeydown">
                <div class="flex items-start justify-between gap-4"><div><p class="font-mono text-xs font-bold text-blue-700">{{ detail?.public_id }}</p><h2 id="detail-title" class="mt-1 text-2xl font-black text-slate-950">{{ detail?.subject || 'Detalle de consulta' }}</h2></div><button ref="detailClose" type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" aria-label="Cerrar detalle" @click="closeDetail"><i class="pi pi-times" aria-hidden="true"></i></button></div>

                <div v-if="detailLoading" class="p-12 text-center text-slate-600"><i class="pi pi-spin pi-spinner mr-2"></i>Cargando detalle…</div>
                <div v-else-if="detailError" class="p-10 text-center text-red-700" role="alert">{{ detailError }}<br><button class="btn-primary mt-4" @click="openDetail(activeReference)">Reintentar</button></div>
                <template v-else-if="detail">
                    <div class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,.75fr)]">
                        <div class="space-y-5">
                            <article class="rounded-xl border border-slate-200 p-5"><div class="grid gap-4 sm:grid-cols-2"><Info label="Fecha" :value="formatDate(detail.created_at)"/><Info label="Estado" :value="statusLabel(detail.status)"/><Info label="Nombre" :value="detail.name"/><Info label="Correo" :value="detail.email"/><Info label="Teléfono" :value="detail.phone || 'No proporcionado'"/><Info label="Responsable" :value="detail.assignee?.name || 'Sin asignar'"/></div><h3 class="mt-5 text-sm font-black uppercase tracking-wide text-slate-700">Mensaje</h3><p class="mt-2 whitespace-pre-line break-words leading-7 text-slate-700">{{ detail.message }}</p></article>

                            <article class="rounded-xl border border-slate-200 p-5"><h3 class="text-lg font-black text-slate-900">Notas internas</h3><div v-if="detail.notes?.length" class="mt-4 space-y-3"><div v-for="note in detail.notes" :key="note.id" class="rounded-lg bg-amber-50 p-3"><p class="whitespace-pre-line break-words text-sm text-slate-800">{{ note.body }}</p><p class="mt-2 text-xs text-slate-500">{{ note.author?.name || 'Administrador eliminado' }} · {{ formatDate(note.created_at) }}</p></div></div><p v-else class="mt-3 text-sm text-slate-500">Todavía no hay notas.</p><form class="mt-4" @submit.prevent="addNote"><label for="internal-note" class="text-sm font-bold text-slate-700">Nueva nota</label><textarea id="internal-note" v-model="noteBody" maxlength="2000" rows="3" class="admin-input mt-1.5 resize-y" required></textarea><button class="btn-primary mt-3" :disabled="busy || noteBody.trim().length < 2">Agregar nota</button></form></article>

                            <article class="rounded-xl border border-slate-200 p-5"><h3 class="text-lg font-black text-slate-900">Historial</h3><div class="mt-4 space-y-4"><div v-for="entry in [...(detail.history || [])].reverse()" :key="entry.id" class="border-l-2 border-blue-300 pl-4"><p class="font-bold text-slate-800">{{ eventLabel(entry) }}</p><p class="mt-1 text-xs text-slate-500">{{ formatDate(entry.created_at) }} · {{ entry.actor?.name || 'Sistema' }}</p></div></div></article>
                        </div>

                        <aside class="space-y-5">
                            <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-black text-slate-900">Estado</h3><select v-model="statusDraft" class="admin-input mt-3"><option :value="detail.status">{{ statusLabel(detail.status) }}</option><option v-for="status in allowedTransitions" :key="status" :value="status">{{ statusLabel(status) }}</option></select><button type="button" class="btn-primary mt-3 w-full" :disabled="busy || statusDraft === detail.status" @click="saveStatus">Actualizar estado</button></div>
                            <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-black text-slate-900">Asignación manual</h3><select v-model="assigneeDraft" class="admin-input mt-3"><option value="">Sin asignar</option><option v-for="admin in admins" :key="admin.id" :value="String(admin.id)">{{ admin.name }}</option></select><button type="button" class="btn-outline mt-3 w-full" :disabled="busy || assigneeDraft === String(detail.assignee?.id || '')" @click="saveAssignment">Guardar responsable</button></div>
                            <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-black text-slate-900">Atención</h3><dl class="mt-3 space-y-3 text-sm"><Info label="Inicio" :value="formatDate(detail.attention_started_at)"/><Info label="Atendida" :value="formatDate(detail.attended_at)"/><Info label="Cerrada" :value="formatDate(detail.closed_at)"/></dl></div>
                            <div class="grid gap-3"><button type="button" class="btn-outline w-full" :disabled="busy" @click="openExternal('email')"><i class="pi pi-envelope"></i>Abrir correo</button><button v-if="detail.phone" type="button" class="btn-outline w-full border-emerald-300 text-emerald-700" :disabled="busy" @click="openExternal('whatsapp')"><i class="pi pi-whatsapp"></i>Abrir WhatsApp</button><button type="button" class="w-full min-h-11 rounded-xl px-4 py-2.5 font-bold" :class="detail.archived_at ? 'bg-blue-50 text-blue-800' : 'bg-slate-900 text-white'" :disabled="busy" @click="toggleArchive">{{ detail.archived_at ? 'Restaurar consulta' : 'Archivar consulta' }}</button></div>
                        </aside>
                    </div>
                </template>
            </section>
        </div>
    </AdminLayout>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import api from '@/api';
import { formatDateTime } from '@/utils/dateTime';

const Info = defineComponent({ props: { label: String, value: String }, setup: props => () => h('div', [h('dt', { class: 'text-xs font-bold uppercase tracking-wide text-slate-500' }, props.label), h('dd', { class: 'mt-1 break-words font-semibold text-slate-800' }, props.value || '—')]) });
const route = useRoute(), router = useRouter(), toast = useToast();
const items = ref([]), admins = ref([]), loading = ref(true), loadError = ref(''), detailOpen = ref(false), detailLoading = ref(false), detailError = ref(''), detail = ref(null), activeReference = ref(''), busy = ref(false), noteBody = ref(''), detailPanel = ref(null), detailClose = ref(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const filters = reactive({ search: '', status: '', assigned_to: '', date_from: '', date_to: '', archived: 'active', sort: 'received_desc' });
const statusDraft = ref(''), assigneeDraft = ref('');
let previousOverflow = '';
let detailTrigger = null;
const statusOptions = [{value:'pending',label:'Pendiente'},{value:'in_attention',label:'En atención'},{value:'attended',label:'Atendida'},{value:'closed',label:'Cerrada'},{value:'spam',label:'Spam'}];
const transitions = { pending:['in_attention','spam'], in_attention:['attended','spam'], attended:['closed','in_attention'], closed:['in_attention'], spam:['pending'] };
const allowedTransitions = computed(() => transitions[detail.value?.status] || []);
const statusLabel = status => statusOptions.find(option => option.value === status)?.label || status;
const statusClass = status => ({pending:'bg-amber-100 text-amber-800',in_attention:'bg-blue-100 text-blue-800',attended:'bg-emerald-100 text-emerald-800',closed:'bg-slate-200 text-slate-700',spam:'bg-red-100 text-red-800'}[status]);
const formatDate = value => value ? formatDateTime(value) : '—';
const queryParams = page => Object.fromEntries(Object.entries({ ...filters, page }).filter(([, value]) => value !== '' && value !== null));

async function load(page = 1) { loading.value = true; loadError.value = ''; try { const { data } = await api.get('/admin/contact-inquiries', { params: queryParams(page) }); items.value = data.data || []; meta.value = data.meta || { current_page: page, last_page: 1, total: items.value.length }; } catch (error) { loadError.value = error.response?.data?.message || 'No se pudieron cargar las consultas.'; } finally { loading.value = false; } }
async function loadAdmins() { try { admins.value = (await api.get('/admin/contact-inquiries/assignable-admins')).data; } catch { admins.value = []; } }
function applyFilters() { load(1); }
function clearFilters() { Object.assign(filters, { search:'',status:'',assigned_to:'',date_from:'',date_to:'',archived:'active',sort:'received_desc' }); load(1); }
async function openDetail(reference) { const wasOpen=detailOpen.value; if(!wasOpen) detailTrigger=document.activeElement; activeReference.value = reference; detailOpen.value = true; detailLoading.value = true; detailError.value = ''; if(!wasOpen){previousOverflow = document.body.style.overflow;document.body.style.overflow = 'hidden'} await nextTick(); detailPanel.value?.focus(); try { const { data } = await api.get(`/admin/contact-inquiries/${encodeURIComponent(reference)}`); setDetail(data.data); router.replace({ query: { ...route.query, open: reference } }); await nextTick(); detailClose.value?.focus(); } catch (error) { detailError.value = error.response?.data?.message || 'No se pudo cargar el detalle.'; } finally { detailLoading.value = false; } }
function setDetail(value) { detail.value = value; statusDraft.value = value.status; assigneeDraft.value = String(value.assignee?.id || ''); }
async function closeDetail() { detailOpen.value = false; detail.value = null; document.body.style.overflow = previousOverflow; const query = { ...route.query }; delete query.open; router.replace({ query }); await nextTick(); detailTrigger?.focus?.(); detailTrigger = null; }
function handleDetailKeydown(event) { if (event.key === 'Escape') { event.preventDefault(); closeDetail(); return; } if (event.key !== 'Tab') return; const focusable = [...detailPanel.value.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled])')]; const first=focusable[0],last=focusable.at(-1); if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()} }
async function mutate(request, success) { busy.value = true; try { const { data } = await request(); setDetail(data.data); toast.add({severity:'success',summary:success,life:2500}); await load(meta.value.current_page); window.dispatchEvent(new Event('contact-inquiries:changed')); } catch (error) { toast.add({severity:'error',summary:'Operación no completada',detail:error.response?.data?.message||Object.values(error.response?.data?.errors||{})[0]?.[0]||error.message,life:4000}); } finally { busy.value = false; } }
const saveStatus = () => mutate(() => api.patch(`/admin/contact-inquiries/${detail.value.public_id}/status`, { status: statusDraft.value }), 'Estado actualizado');
const saveAssignment = () => mutate(() => api.patch(`/admin/contact-inquiries/${detail.value.public_id}/assignment`, { assigned_to: assigneeDraft.value || null }), 'Responsable actualizado');
async function addNote() { const body=noteBody.value.trim(); if(body.length<2)return; await mutate(() => api.post(`/admin/contact-inquiries/${detail.value.public_id}/notes`, { body }), 'Nota agregada'); noteBody.value=''; }
const toggleArchive = () => mutate(() => api.post(`/admin/contact-inquiries/${detail.value.public_id}/${detail.value.archived_at?'restore':'archive'}`), detail.value.archived_at?'Consulta restaurada':'Consulta archivada');
async function openExternal(channel) { busy.value=true; const popup=channel==='whatsapp'?window.open('about:blank','_blank'):null; try { const {data}=await api.post(`/admin/contact-inquiries/${detail.value.public_id}/actions/${channel}`); if(channel==='email') window.location.href=data.url; else if(popup){popup.opener=null;popup.location=data.url}else window.open(data.url,'_blank','noopener,noreferrer'); await openDetail(detail.value.public_id); window.dispatchEvent(new Event('contact-inquiries:changed')); } catch(error){popup?.close();toast.add({severity:'error',summary:'No se pudo abrir el canal',detail:error.response?.data?.message||Object.values(error.response?.data?.errors||{})[0]?.[0],life:3500})} finally{busy.value=false} }
function eventLabel(entry) { const labels={created:'Consulta creada',status_changed:`Estado: ${statusLabel(entry.from_status)} → ${statusLabel(entry.to_status)}`,assigned:'Responsable asignado',unassigned:'Responsable retirado',note_added:'Nota interna agregada',archived:'Consulta archivada',restored:'Consulta restaurada',email_client_opened:'Apertura de cliente de correo iniciada',whatsapp_opened:'Apertura de WhatsApp iniciada'}; return labels[entry.event_type]||entry.event_type; }

onMounted(async()=>{await Promise.all([load(),loadAdmins()]);if(typeof route.query.open==='string')openDetail(route.query.open)});
onBeforeUnmount(()=>{document.body.style.overflow=previousOverflow});
</script>

<style scoped>
.admin-input{width:100%;min-height:44px;border:1px solid #cbd5e1;border-radius:.65rem;background:white;padding:.65rem .75rem;color:#0f172a;outline:none}.admin-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgb(37 99 235/.15)}.status-badge{display:inline-flex;white-space:nowrap;border-radius:9999px;padding:.3rem .65rem;font-size:.75rem;font-weight:800}
</style>
