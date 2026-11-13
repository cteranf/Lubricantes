<template>
    <AdminLayout>
        <main class="mx-auto max-w-7xl space-y-6">
            <header><p class="text-xs font-bold uppercase tracking-widest text-blue-700">Catálogo</p><h1 class="mt-1 text-3xl font-black text-slate-950">Opiniones de productos</h1><p class="mt-2 text-slate-600">Modera opiniones de clientes y controla su publicación.</p></header>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="review-settings-title">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="review-settings-title" class="text-lg font-bold">Configuración</h2><p class="text-sm text-slate-600">Las opiniones requieren aprobación manual antes de publicarse.</p></div><Button label="Guardar configuración" icon="pi pi-save" :loading="savingSettings" :disabled="savingSettings" @click="saveSettings" /></div>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <label class="flex items-start gap-3 rounded-lg border p-3"><input v-model="settings.show_reviews_publicly" type="checkbox" class="mt-1"><span><b>Mostrar opiniones</b><small class="mt-1 block text-slate-600">Muestra solo las publicadas en productos habilitados.</small></span></label>
                    <label class="flex items-start gap-3 rounded-lg border p-3"><input v-model="settings.accept_new_reviews" type="checkbox" class="mt-1"><span><b>Aceptar nuevas opiniones</b><small class="mt-1 block text-slate-600">Permite crear y editar opiniones verificadas.</small></span></label>
                    <label class="flex items-start gap-3 rounded-lg border bg-slate-50 p-3"><input v-model="settings.require_moderation" type="checkbox" class="mt-1"><span><b>Requerir moderación</b><small class="mt-1 block text-slate-600">Recomendado para revisar contenido antes de publicar.</small></span></label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Listado de opiniones">
                <div class="mb-4 grid gap-3 sm:grid-cols-4">
                    <select v-model="filters.status" class="rounded-lg border p-2" aria-label="Filtrar por estado" @change="load(1)"><option value="">Todos los estados</option><option v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</option></select>
                    <input v-model="filters.product_id" class="rounded-lg border p-2" inputmode="numeric" placeholder="ID de producto" aria-label="Filtrar por producto" @change="load(1)">
                    <select v-model="filters.rating" class="rounded-lg border p-2" aria-label="Filtrar por calificación" @change="load(1)"><option value="">Todas las calificaciones</option><option v-for="rating in [5,4,3,2,1]" :key="rating" :value="rating">{{ rating }} estrellas</option></select>
                    <input v-model="filters.search" class="rounded-lg border p-2" placeholder="Buscar opinión, producto o cliente" aria-label="Buscar opiniones" @keyup.enter="load(1)">
                </div>
                <div v-if="loadError" class="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ loadError }} <Button label="Reintentar" text @click="load(page)" /></div>
                <DataTable :value="rows" lazy paginator :first="(page - 1) * perPage" :rows="perPage" :total-records="total" :loading="loading" data-key="id" responsive-layout="scroll" @page="onPage">
                    <Column field="product.name" header="Producto"><template #body="slotProps">{{ slotProps.data.product?.name || 'Producto eliminado' }}</template></Column>
                    <Column field="customer_name" header="Cliente" />
                    <Column field="rating" header="Estrellas"><template #body="slotProps"><span :aria-label="`${slotProps.data.rating} de 5 estrellas`">{{ '★'.repeat(slotProps.data.rating) }}<span class="sr-only">{{ slotProps.data.rating }} de 5</span></span></template></Column>
                    <Column field="title" header="Título / resumen"><template #body="slotProps"><button type="button" class="max-w-52 truncate text-left font-semibold text-blue-700 underline" @click="openDetail(slotProps.data)">{{ slotProps.data.title || slotProps.data.comment }}</button></template></Column>
                    <Column field="verified_purchase" header="Compra"><template #body="slotProps"><Tag :value="slotProps.data.verified_purchase ? 'Verificada' : 'No disponible'" :severity="slotProps.data.verified_purchase ? 'success' : 'secondary'" /></template></Column>
                    <Column field="status" header="Estado"><template #body="slotProps"><Tag :value="statusLabel(slotProps.data.status)" :severity="statusSeverity(slotProps.data.status)" /></template></Column>
                    <Column field="created_at" header="Fecha"><template #body="slotProps">{{ formatDate(slotProps.data.created_at) }}</template></Column>
                    <Column header="Acciones"><template #body="slotProps"><div class="flex flex-wrap gap-2">
                        <Button v-if="slotProps.data.status === 'pending' || slotProps.data.status === 'hidden' || slotProps.data.status === 'rejected'" label="Publicar" icon="pi pi-check" size="small" severity="success" outlined :loading="busy.has(slotProps.data.id)" :disabled="busy.has(slotProps.data.id)" @click="confirmAction(slotProps.data, 'published')" />
                        <Button v-if="slotProps.data.status === 'pending'" label="Rechazar" icon="pi pi-times" size="small" severity="warn" outlined :loading="busy.has(slotProps.data.id)" :disabled="busy.has(slotProps.data.id)" @click="beginReject(slotProps.data)" />
                        <Button v-if="slotProps.data.status === 'published'" label="Ocultar" icon="pi pi-eye-slash" size="small" severity="secondary" outlined :loading="busy.has(slotProps.data.id)" :disabled="busy.has(slotProps.data.id)" @click="confirmAction(slotProps.data, 'hidden')" />
                        <Button v-if="slotProps.data.status === 'rejected'" label="Volver a pendiente" icon="pi pi-replay" size="small" severity="secondary" outlined :loading="busy.has(slotProps.data.id)" :disabled="busy.has(slotProps.data.id)" @click="confirmAction(slotProps.data, 'pending')" />
                    </div></template></Column>
                    <template #empty><div class="py-8 text-center text-slate-600">No hay opiniones para los filtros seleccionados.</div></template>
                </DataTable>
            </section>

            <div v-if="detail" class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" role="presentation" @click.self="detail = null">
                <section class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="review-detail-title">
                    <div class="flex items-start justify-between gap-3"><h2 id="review-detail-title" class="text-xl font-black">{{ detail.title || 'Opinión' }}</h2><Button icon="pi pi-times" text rounded aria-label="Cerrar detalle" @click="detail = null" /></div>
                    <p class="mt-2 text-sm text-slate-600">{{ detail.product?.name }} · {{ detail.customer_name }} · {{ detail.rating }} de 5 estrellas</p>
                    <p class="mt-5 whitespace-pre-wrap break-words leading-7 text-slate-800">{{ detail.comment }}</p>
                    <p v-if="detail.rejection_reason" class="mt-4 rounded bg-amber-50 p-3 text-sm text-amber-900">Motivo de rechazo: {{ detail.rejection_reason }}</p>
                </section>
            </div>
            <div v-if="rejectReview" class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" role="presentation">
                <section class="w-full max-w-lg rounded-2xl bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="reject-title">
                    <h2 id="reject-title" class="text-xl font-bold">Rechazar opinión</h2><label class="mt-4 block text-sm font-semibold" for="review-rejection-reason">Motivo</label><textarea id="review-rejection-reason" v-model="rejectionReason" maxlength="500" rows="4" class="mt-1 w-full rounded-lg border p-3" />
                    <div class="mt-4 flex justify-end gap-2"><Button label="Cancelar" severity="secondary" outlined :disabled="savingReview" @click="rejectReview = null" /><Button label="Confirmar rechazo" severity="warn" :loading="savingReview" :disabled="savingReview || !rejectionReason.trim()" @click="submitReject" /></div>
                </section>
            </div>
        </main>
    </AdminLayout>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import AdminLayout from '@/layouts/AdminLayout.vue';
import api from '@/api';

const toast = useToast();
const confirm = useConfirm();
const rows = ref([]), loading = ref(false), loadError = ref(''), page = ref(1), perPage = ref(15), total = ref(0);
const savingSettings = ref(false), savingReview = ref(false), detail = ref(null), rejectReview = ref(null), rejectionReason = ref('');
const busy = reactive(new Set());
const settings = reactive({ show_reviews_publicly: false, accept_new_reviews: false, require_moderation: true });
const filters = reactive({ status: '', product_id: '', rating: '', search: '' });
const statuses = [{ value: 'pending', label: 'Pendiente' }, { value: 'published', label: 'Publicado' }, { value: 'hidden', label: 'Oculto' }, { value: 'rejected', label: 'Rechazado' }];
const safeError = () => 'No se pudo completar la operación. Inténtalo nuevamente.';

async function load(targetPage = page.value) {
    loading.value = true; loadError.value = '';
    try {
        const params = Object.fromEntries(Object.entries({ ...filters, page: targetPage, per_page: perPage.value }).filter(([, value]) => value !== ''));
        const { data } = await api.get('/admin/product-reviews', { params });
        rows.value = Array.isArray(data?.data) ? data.data : [];
        page.value = Number(data?.meta?.current_page || targetPage); total.value = Number(data?.meta?.total || 0); perPage.value = Number(data?.meta?.per_page || perPage.value);
    } catch { loadError.value = safeError(); }
    finally { loading.value = false; }
}
async function loadSettings() {
    try { Object.assign(settings, (await api.get('/admin/product-reviews/settings')).data?.data || {}); }
    catch { toast.add({ severity: 'error', summary: safeError(), life: 3500 }); }
}
async function saveSettings() {
    if (savingSettings.value) return;
    savingSettings.value = true;
    try { Object.assign(settings, (await api.put('/admin/product-reviews/settings', { ...settings })).data.data); toast.add({ severity: 'success', summary: 'Configuración guardada', life: 2500 }); }
    catch { toast.add({ severity: 'error', summary: safeError(), life: 3500 }); }
    finally { savingSettings.value = false; }
}
function onPage(event) { perPage.value = event.rows; load(Math.floor(event.first / event.rows) + 1); }
function statusLabel(value) { return statuses.find(item => item.value === value)?.label || 'Estado'; }
function statusSeverity(value) { return ({ pending: 'warn', published: 'success', hidden: 'secondary', rejected: 'danger' })[value] || 'secondary'; }
function formatDate(value) { return value ? new Date(value).toLocaleString('es-PE') : '—'; }
function openDetail(row) { detail.value = row; }
function confirmAction(row, status) {
    const label = status === 'published' ? 'publicar' : status === 'hidden' ? 'ocultar' : 'devolver a pendiente';
    confirm.require({ header: 'Confirmar moderación', message: `¿Deseas ${label} esta opinión?`, acceptLabel: 'Confirmar', rejectLabel: 'Cancelar', accept: () => moderate(row, status) });
}
function beginReject(row) { rejectReview.value = row; rejectionReason.value = ''; }
async function submitReject() { if (rejectReview.value) await moderate(rejectReview.value, 'rejected', rejectionReason.value.trim()); }
async function moderate(row, status, reason = null) {
    if (busy.has(row.id)) return;
    busy.add(row.id); savingReview.value = true;
    try {
        await api.patch(`/admin/product-reviews/${row.id}/moderation`, { status, ...(reason ? { rejection_reason: reason } : {}) });
        rejectReview.value = null; detail.value = null; toast.add({ severity: 'success', summary: 'Opinión actualizada', life: 2500 }); await load(page.value);
    } catch { toast.add({ severity: 'error', summary: safeError(), life: 3500 }); }
    finally { busy.delete(row.id); savingReview.value = false; }
}
onMounted(() => { void load(); void loadSettings(); });
</script>
