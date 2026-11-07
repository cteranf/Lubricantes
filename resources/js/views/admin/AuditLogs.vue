<template>
  <AdminLayout>
    <section class="mx-auto max-w-7xl space-y-5">
      <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-sm font-bold uppercase tracking-wide text-blue-700">Seguridad y trazabilidad</p>
          <h1 class="mt-1 text-2xl font-black text-slate-900">Auditoría</h1>
          <p class="mt-1 text-sm text-slate-600">Consulta de solo lectura. La referencia histórica del actor es su ID.</p>
        </div>
        <button type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" :disabled="loading" @click="load(page)">Actualizar</button>
      </header>

      <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
        <label class="text-sm font-semibold text-slate-700">Desde<input v-model="filters.date_from" type="date" class="field mt-1" /></label>
        <label class="text-sm font-semibold text-slate-700">Hasta<input v-model="filters.date_to" type="date" class="field mt-1" /></label>
        <label class="text-sm font-semibold text-slate-700">Acción<select v-model="filters.action" class="field mt-1"><option value="">Todas</option><option v-for="item in actions" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
        <label class="text-sm font-semibold text-slate-700">Categoría<select v-model="filters.category" class="field mt-1"><option value="">Todas</option><option v-for="item in categories" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
        <label class="text-sm font-semibold text-slate-700">Resultado<select v-model="filters.result" class="field mt-1"><option value="">Todos</option><option v-for="item in results" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
        <label class="text-sm font-semibold text-slate-700">Tipo de actor<select v-model="filters.actor_type" class="field mt-1"><option value="">Todos</option><option value="user">Usuario</option><option value="system">Sistema</option><option value="command">Comando</option></select></label>
        <label class="text-sm font-semibold text-slate-700">ID del actor<input v-model.trim="filters.actor_id" inputmode="numeric" class="field mt-1" maxlength="20" /></label>
        <label class="text-sm font-semibold text-slate-700">Tipo de entidad<select v-model="filters.auditable_type" class="field mt-1"><option value="">Todos</option><option v-for="item in entityTypes" :key="item" :value="item">{{ item }}</option></select></label>
        <label class="text-sm font-semibold text-slate-700">ID de entidad<input v-model.trim="filters.auditable_id" class="field mt-1" maxlength="120" /></label>
        <label class="text-sm font-semibold text-slate-700">Request ID<input v-model.trim="filters.request_id" class="field mt-1" maxlength="36" /></label>
        <label class="text-sm font-semibold text-slate-700">Correlation ID<input v-model.trim="filters.correlation_id" class="field mt-1" maxlength="36" /></label>
        <label class="text-sm font-semibold text-slate-700">Filas por página<select v-model.number="perPage" class="field mt-1"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4"><button class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800" :disabled="loading">Aplicar filtros</button><button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" :disabled="loading" @click="clearFilters">Limpiar</button></div>
      </form>

      <div v-if="loading" class="rounded-2xl bg-white p-10 text-center text-sm font-semibold text-slate-600" role="status">Cargando registros de auditoría…</div>
      <div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-800" role="alert">{{ error }}</div>
      <section v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="Registros de auditoría">
        <div class="overflow-x-auto"><table class="min-w-[1050px] w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="p-4">Fecha y hora</th><th class="p-4">Acción</th><th class="p-4">Categoría</th><th class="p-4">Resultado</th><th class="p-4">Actor</th><th class="p-4">Entidad</th><th class="p-4">Método / ruta</th><th class="p-4 text-right">Detalle</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in rows" :key="row.id" class="align-top hover:bg-slate-50"><td class="whitespace-nowrap p-4 text-slate-600">{{ formatDate(row.occurred_at) }}</td><td class="p-4 font-semibold text-slate-900">{{ row.action }}</td><td class="p-4 text-slate-600">{{ row.category }}</td><td class="p-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="row.result === 'succeeded' ? 'bg-emerald-50 text-emerald-800' : row.result === 'denied' ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-800'">{{ resultLabel(row.result) }}</span></td><td class="p-4 text-slate-600">{{ actorLabel(row) }}</td><td class="p-4 text-slate-600">{{ entityLabel(row) }}</td><td class="p-4 text-slate-600">{{ row.http_method || '—' }}<span v-if="row.route_name"> / {{ row.route_name }}</span></td><td class="p-4 text-right"><button type="button" class="rounded-lg border border-blue-200 px-3 py-2 text-xs font-bold text-blue-800 hover:bg-blue-50" @click="openDetail(row, $event)">Ver detalle</button></td></tr>
            <tr v-if="rows.length === 0"><td colspan="8" class="p-12 text-center"><p class="font-bold text-slate-700">No hay registros para mostrar</p><p class="mt-1 text-sm text-slate-500">Ajusta los filtros o vuelve más tarde.</p></td></tr>
          </tbody>
        </table></div>
        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 p-4 text-sm text-slate-600"><span>{{ paginationSummary }}</span><div class="flex items-center gap-2"><button type="button" class="pager" :disabled="loading || page <= 1" @click="load(page - 1)">Anterior</button><span>Página {{ page }} de {{ lastPage }}</span><button type="button" class="pager" :disabled="loading || page >= lastPage" @click="load(page + 1)">Siguiente</button></div></footer>
      </section>
    </section>

    <div v-if="detailOpen" class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-3" role="presentation" @mousedown.self="closeDetail">
      <section ref="dialogRef" tabindex="-1" class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="audit-detail-title" @keydown="handleDialogKeydown">
        <header class="flex items-start justify-between gap-4 border-b border-slate-100 p-5"><div><h2 id="audit-detail-title" class="text-xl font-black text-slate-900">Detalle de auditoría</h2><p v-if="detail" class="mt-1 text-sm text-slate-600">Registro #{{ detail.id }}</p></div><button ref="closeDetailButton" type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700" @click="closeDetail">Cerrar</button></header>
        <div class="space-y-4 p-5">
          <p v-if="detailLoading" class="text-sm text-slate-600" role="status">Cargando detalle…</p>
          <p v-else-if="detailError" class="rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ detailError }}</p>
          <template v-else-if="detail">
            <dl class="grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2"><div><dt class="font-bold text-slate-500">Actor histórico</dt><dd class="mt-1 text-slate-900">{{ detail.actor_type }}<span v-if="detail.actor_id"> #{{ detail.actor_id }}</span></dd></div><div><dt class="font-bold text-slate-500">Nombre actual del actor</dt><dd class="mt-1 text-slate-900">{{ detail.actor?.name || 'No disponible' }}</dd></div><div><dt class="font-bold text-slate-500">Referencia</dt><dd class="mt-1 break-all text-slate-900">{{ detail.request_id }}</dd></div><div><dt class="font-bold text-slate-500">Correlación</dt><dd class="mt-1 break-all text-slate-900">{{ detail.correlation_id || '—' }}</dd></div><div><dt class="font-bold text-slate-500">Dirección anonimizada</dt><dd class="mt-1 text-slate-900">{{ detail.ip_address || '—' }}</dd></div><div><dt class="font-bold text-slate-500">Fuente especializada</dt><dd class="mt-1 break-all text-slate-900">{{ detail.specialized_log_type ? `${detail.specialized_log_type} #${detail.specialized_log_id}` : '—' }}</dd></div></dl>
            <p class="text-xs text-slate-500">El ID del actor es la referencia histórica. El nombre mostrado corresponde al usuario actual y puede diferir del estado histórico.</p>
            <div v-for="section in jsonSections" :key="section.key" class="rounded-xl border border-slate-200"><h3 class="border-b border-slate-100 px-4 py-3 text-sm font-bold text-slate-800">{{ section.label }}</h3><pre class="overflow-x-auto whitespace-pre-wrap break-words p-4 text-xs text-slate-700">{{ formatJson(detail[section.key]) }}</pre></div>
          </template>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, nextTick, reactive, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import api from '@/api';

const rows = ref([]), loading = ref(false), error = ref(''), page = ref(1), perPage = ref(25), lastPage = ref(1), total = ref(0);
const detailOpen = ref(false), detailLoading = ref(false), detailError = ref(''), detail = ref(null);
const selectedDetailId = ref(null), dialogRef = ref(null), closeDetailButton = ref(null);
let detailRequestSequence = 0, detailOpener = null;
const filters = reactive({ date_from: '', date_to: '', action: '', category: '', result: '', actor_type: '', actor_id: '', auditable_type: '', auditable_id: '', request_id: '', correlation_id: '' });
const actions = [
  ['auth.login.succeeded', 'Inicio de sesión correcto'], ['auth.login.failed', 'Inicio de sesión fallido'], ['auth.login.denied', 'Inicio de sesión denegado'], ['auth.logout', 'Cierre de sesión'], ['auth.session.revoked', 'Sesiones revocadas'],
  ['user.created', 'Usuario creado'], ['user.updated', 'Usuario actualizado'], ['user.role.changed', 'Rol cambiado'], ['user.status.changed', 'Estado cambiado'], ['user.password.changed', 'Contraseña cambiada'], ['user.password.reset', 'Contraseña restablecida'], ['security.access.denied', 'Acceso denegado'], ['system.command.completed', 'Comando completado'], ['audit.logs.viewed', 'Listado de auditoría consultado'], ['audit.log.viewed', 'Detalle de auditoría consultado'],
].map(([value, label]) => ({ value, label }));
const categories = [{ value: 'authentication', label: 'Autenticación' }, { value: 'access', label: 'Acceso' }, { value: 'user_security', label: 'Seguridad de usuario' }, { value: 'system', label: 'Sistema' }];
const results = [{ value: 'succeeded', label: 'Correcto' }, { value: 'failed', label: 'Fallido' }, { value: 'denied', label: 'Denegado' }];
const entityTypes = ['user', 'order', 'product', 'category', 'brand', 'branch', 'warehouse', 'payment_setting', 'payment_receiving_account'];
const jsonSections = [{ key: 'old_values', label: 'Valores anteriores' }, { key: 'new_values', label: 'Valores nuevos' }, { key: 'metadata', label: 'Metadata permitida' }];
const paginationSummary = computed(() => total.value === 0 ? '0 registros' : `Mostrando ${(page.value - 1) * perPage.value + 1}–${Math.min(page.value * perPage.value, total.value)} de ${total.value}`);

function queryParams(targetPage) {
  const params = { page: targetPage, per_page: perPage.value };
  for (const [key, value] of Object.entries(filters)) if (value !== '') params[key] = value;
  return params;
}
function errorMessage(exception) {
  const status = exception.response?.status;
  if (status === 401) return 'Tu sesión no está disponible. Inicia sesión nuevamente.';
  if (status === 403) return 'No tienes permiso para consultar la auditoría.';
  if (status === 422) return 'Revisa los filtros: alguno no es válido.';
  return 'No se pudo cargar la auditoría. Inténtalo nuevamente.';
}
async function load(targetPage = 1) {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try {
    const { data } = await api.get('/admin/audit-logs', { params: queryParams(targetPage) });
    rows.value = Array.isArray(data.data) ? data.data : [];
    page.value = data.meta?.current_page || targetPage;
    perPage.value = data.meta?.per_page || perPage.value;
    lastPage.value = Math.max(1, data.meta?.last_page || 1);
    total.value = data.meta?.total || 0;
  } catch (exception) { error.value = errorMessage(exception); }
  finally { loading.value = false; }
}
function applyFilters() { load(1); }
function clearFilters() { Object.keys(filters).forEach(key => { filters[key] = ''; }); perPage.value = 25; load(1); }
async function openDetail(row, event = null) {
  const requestedId = String(row.id);
  const sequence = ++detailRequestSequence;
  if (event?.currentTarget) detailOpener = event.currentTarget;
  else if (!detailOpen.value && typeof document !== 'undefined') detailOpener = document.activeElement;
  selectedDetailId.value = requestedId;
  detailOpen.value = true; detail.value = null; detailError.value = ''; detailLoading.value = true;
  await nextTick();
  if (isCurrentDetailRequest(sequence, requestedId)) closeDetailButton.value?.focus();

  try {
    const response = await api.get(`/admin/audit-logs/${encodeURIComponent(requestedId)}`);
    if (isCurrentDetailRequest(sequence, requestedId)) detail.value = response.data.data;
  } catch (exception) {
    if (isCurrentDetailRequest(sequence, requestedId)) detailError.value = errorMessage(exception);
  } finally {
    if (isCurrentDetailRequest(sequence, requestedId)) detailLoading.value = false;
  }
}
function isCurrentDetailRequest(sequence, requestedId) {
  return sequence === detailRequestSequence && detailOpen.value && selectedDetailId.value === requestedId;
}
function closeDetail() {
  const opener = detailOpener;
  const closingSequence = ++detailRequestSequence;
  detailOpener = null;
  detailOpen.value = false; selectedDetailId.value = null; detail.value = null; detailError.value = ''; detailLoading.value = false;
  nextTick(() => {
    if (closingSequence !== detailRequestSequence || detailOpen.value || !opener?.isConnected || opener.disabled) return;
    opener.focus?.();
  });
}
function handleDialogKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault();
    closeDetail();
    return;
  }
  if (event.key !== 'Tab') return;

  const dialog = dialogRef.value;
  const focusable = dialog ? [...dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    .filter(element => !element.hidden && element.getAttribute('aria-hidden') !== 'true') : [];
  if (focusable.length === 0) {
    event.preventDefault();
    dialog?.focus();
    return;
  }

  const first = focusable[0], last = focusable[focusable.length - 1];
  if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
    event.preventDefault();
    first.focus();
  }
}
function formatJson(value) { return value === null || value === undefined ? 'Sin datos' : JSON.stringify(value, null, 2); }
function formatDate(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'medium' }).format(date); }
function resultLabel(value) { return value === 'succeeded' ? 'Correcto' : value === 'denied' ? 'Denegado' : value === 'failed' ? 'Fallido' : '—'; }
function actorLabel(row) { return row.actor_id ? `${row.actor_type} #${row.actor_id}` : row.actor_type; }
function entityLabel(row) { return row.auditable_type ? `${row.auditable_type} #${row.auditable_id}` : '—'; }
onMounted(() => load(1));
onBeforeUnmount(() => {
  detailRequestSequence++;
  detailOpener = null;
  detailOpen.value = false;
  selectedDetailId.value = null;
});
</script>

<style scoped>
.field { min-height: 2.65rem; width: 100%; border: 1px solid #cbd5e1; border-radius: .625rem; background: white; padding: .5rem .65rem; color: #0f172a; }
.field:focus, .pager:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
.pager { min-height: 2.5rem; border: 1px solid #cbd5e1; border-radius: .625rem; background: white; padding: .5rem .75rem; font-weight: 700; }
.pager:disabled { cursor: not-allowed; opacity: .5; }
</style>
