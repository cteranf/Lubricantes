<template>
  <AdminLayout>
    <main class="mx-auto max-w-7xl space-y-6" aria-labelledby="drivers-title">
      <AdminPageHeader eyebrow="OPERACIÓN DE ENTREGA" title="Repartidores" title-id="drivers-title" description="Administra el personal habilitado para reparto propio.">
        <template #action><button class="primary" type="button" @click="open()"><i class="pi pi-plus mr-2"/>Nuevo repartidor</button></template>
      </AdminPageHeader>
      <section class="metric"><span>Total de repartidores</span><strong>{{meta.total||0}}</strong><small>Resultado global del listado</small></section>
      <form class="panel" @submit.prevent="load(1)">
        <label>Buscar<input v-model.trim="search" class="field" placeholder="Código o nombre"></label>
        <label>Estado<select v-model="status" class="field"><option value="">Todos</option><option value="available">Disponible</option><option value="unavailable">No disponible</option><option value="inactive">Inactivo</option></select></label>
        <div><button class="primary" :disabled="loading">Aplicar</button><button class="secondary" type="button" @click="clear">Limpiar</button><button class="icon" type="button" aria-label="Actualizar repartidores" title="Actualizar" @click="load()"><i class="pi pi-refresh"/></button></div>
      </form>
      <section v-if="error" class="error" role="alert">{{error}}<button class="secondary" @click="load()">Reintentar</button></section>
      <section v-else class="overflow-hidden rounded-2xl bg-white shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[700px] text-sm">
            <thead><tr><th scope="col">Repartidor</th><th scope="col">Código</th><th scope="col">Entregas</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
              <template v-if="loading"><tr v-for="n in 5" :key="n"><td v-for="c in 5" :key="c"><span class="skeleton"/></td></tr></template>
              <template v-else-if="items.length"><tr v-for="item in items" :key="item.id"><td><b>{{item.first_name}} {{item.last_name}}</b></td><td class="font-mono">{{item.code}}</td><td>{{item.active_deliveries_count||0}} activas · {{item.deliveries_count||0}} total</td><td><AdminStatusBadge :active="!!item.is_active" :active-label="item.is_available?'Disponible':'No disponible'" inactive-label="Inactivo"/></td><td><AdminIconButton icon="pi pi-pencil" label="Editar repartidor" @click="open(item)"/><AdminIconButton :icon="item.is_available?'pi pi-pause-circle':'pi pi-check-circle'" :label="item.is_available?'Marcar no disponible':'Marcar disponible'" tone="text-amber-700" :disabled="!item.is_active" @click="toggleAvailability(item)"/><AdminIconButton :icon="item.is_active?'pi pi-ban':'pi pi-check-circle'" :label="item.is_active?'Desactivar':'Activar'" :tone="item.is_active?'text-red-700':'text-emerald-700'" @click="toggleActive(item)"/></td></tr></template>
              <tr v-else><td colspan="5"><AdminEmptyState icon="pi pi-users" title="No hay repartidores" description="No se encontraron registros para los filtros aplicados."/></td></tr>
            </tbody>
          </table>
        </div>
        <AdminPagination :page="meta.current_page||1" :last-page="meta.last_page||1" :summary="summary" :loading="loading" @change="load"/>
      </section>

      <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 p-4">
        <form class="modal max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="driver-modal-title" @submit.prevent="save">
          <header class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700"><i class="pi pi-user" aria-hidden="true"/></span>
            <div class="min-w-0 flex-1">
              <h2 id="driver-modal-title" class="text-lg font-bold text-slate-900">{{ form.id ? 'Editar repartidor' : 'Nuevo repartidor' }}</h2>
              <p class="mt-1 text-sm text-slate-500">{{ form.id ? 'Actualiza sus datos de identificación, licencia y disponibilidad.' : 'Registra un repartidor habilitado para la operación de entrega.' }}</p>
            </div>
            <button type="button" class="modal-close" aria-label="Cerrar modal de repartidor" :disabled="saving" @click="close"><i class="pi pi-times" aria-hidden="true"/></button>
          </header>

          <div class="space-y-6 p-5 sm:p-6">
            <section class="modal-section">
              <div><h3>Cuenta y repartidor</h3><p>Datos de identificación y contacto autorizados para la operación.</p></div>
              <div class="grid gap-4 sm:grid-cols-2">
                <label for="driver-code">Código <span aria-hidden="true">*</span><input id="driver-code" v-model="form.code" required class="field"><small v-if="fieldError('code')" class="field-error">{{ fieldError('code') }}</small></label>
                <label for="driver-phone">Teléfono <span aria-hidden="true">*</span><input id="driver-phone" v-model="form.phone" required class="field"><small v-if="fieldError('phone')" class="field-error">{{ fieldError('phone') }}</small></label>
                <label for="driver-first-name">Nombres <span aria-hidden="true">*</span><input id="driver-first-name" v-model="form.first_name" required class="field"><small v-if="fieldError('first_name')" class="field-error">{{ fieldError('first_name') }}</small></label>
                <label for="driver-last-name">Apellidos <span aria-hidden="true">*</span><input id="driver-last-name" v-model="form.last_name" required class="field"><small v-if="fieldError('last_name')" class="field-error">{{ fieldError('last_name') }}</small></label>
                <label for="driver-document-type">Tipo de documento<input id="driver-document-type" v-model="form.document_type" class="field"><small v-if="fieldError('document_type')" class="field-error">{{ fieldError('document_type') }}</small></label>
                <label for="driver-document-number">Número de documento<input id="driver-document-number" v-model="form.document_number" class="field"><small v-if="fieldError('document_number')" class="field-error">{{ fieldError('document_number') }}</small></label>
                <label for="driver-email">Correo<input id="driver-email" v-model="form.email" type="email" class="field"><small v-if="fieldError('email')" class="field-error">{{ fieldError('email') }}</small></label>
                <label for="driver-notes">Notas<textarea id="driver-notes" v-model="form.notes" class="field"></textarea><small v-if="fieldError('notes')" class="field-error">{{ fieldError('notes') }}</small></label>
              </div>
            </section>
            <section class="modal-section">
              <div><h3>Información de conducción</h3><p>Datos de licencia usados para validar asignaciones motorizadas.</p></div>
              <div class="grid gap-4 sm:grid-cols-2">
                <label for="driver-license-number">Licencia<input id="driver-license-number" v-model="form.license_number" class="field"><small v-if="fieldError('license_number')" class="field-error">{{ fieldError('license_number') }}</small></label>
                <label for="driver-license-category">Categoría de licencia<input id="driver-license-category" v-model="form.license_category" class="field"><small v-if="fieldError('license_category')" class="field-error">{{ fieldError('license_category') }}</small></label>
                <label for="driver-license-expires-at">Vencimiento de licencia<input id="driver-license-expires-at" v-model="form.license_expires_at" type="date" class="field"><small v-if="fieldError('license_expires_at')" class="field-error">{{ fieldError('license_expires_at') }}</small></label>
              </div>
            </section>
            <section class="modal-section">
              <div><h3>Disponibilidad operativa</h3><p>Un repartidor inactivo no puede asignarse a nuevas entregas.</p></div>
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="check-field" for="driver-is-active"><input id="driver-is-active" v-model="form.is_active" type="checkbox"> <span><b>Activo</b><small>Habilita al repartidor para la operación.</small></span></label>
                <label class="check-field" for="driver-is-available"><input id="driver-is-available" v-model="form.is_available" type="checkbox"> <span><b>Disponible</b><small>Permite asignarlo a nuevas entregas.</small></span></label>
              </div>
            </section>
          </div>

          <footer class="modal-footer flex flex-col-reverse gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
            <button class="secondary" type="button" :disabled="saving" @click="close">Cancelar</button>
            <button class="primary" :disabled="saving"><i :class="saving ? 'pi pi-spin pi-spinner mr-2' : 'pi pi-save mr-2'" aria-hidden="true"/>{{ saving ? 'Guardando…' : (form.id ? 'Guardar cambios' : 'Crear repartidor') }}</button>
          </footer>
        </form>
      </div>
    </main>
  </AdminLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminStatusBadge from '@/components/admin/AdminStatusBadge.vue';
import AdminIconButton from '@/components/admin/AdminIconButton.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import api from '@/api';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const items = ref([]);
const search = ref('');
const status = ref('');
const meta = ref({});
const loading = ref(false);
const error = ref('');
const modal = ref(false);
const saving = ref(false);
const form = ref({});
const formErrors = ref({});

const empty = () => ({ code: '', first_name: '', last_name: '', phone: '', document_type: 'DNI', document_number: '', email: '', license_number: '', license_category: '', license_expires_at: '', notes: '', is_active: true, is_available: true });
const msg = e => e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || 'No se pudo completar la operación.';
const fieldError = field => formErrors.value[field]?.[0] || '';
const summary = computed(() => meta.value.total ? `Mostrando ${meta.value.from}–${meta.value.to} de ${meta.value.total}` : 'Sin registros');

async function load(page = meta.value.current_page || 1) { if (loading.value) return; loading.value = true; error.value = ''; try { const { data } = await api.get('/admin/delivery-drivers', { params: { page, search: search.value || undefined, status: status.value || undefined } }); items.value = data.data || []; meta.value = data; } catch (e) { error.value = msg(e); } finally { loading.value = false; } }
function clear() { search.value = ''; status.value = ''; load(1); }
function open(item = null) { formErrors.value = {}; form.value = item ? { ...item } : empty(); modal.value = true; }
function close() { if (!saving.value) { formErrors.value = {}; modal.value = false; } }
async function save() { saving.value = true; formErrors.value = {}; try { form.value.id ? await api.put(`/admin/delivery-drivers/${form.value.id}`, form.value) : await api.post('/admin/delivery-drivers', form.value); modal.value = false; await load(); } catch (e) { formErrors.value = e.response?.data?.errors || {}; toast.add({ severity: 'error', summary: 'No se pudo guardar', detail: msg(e), life: 5000 }); } finally { saving.value = false; } }
async function toggleActive(i) { try { await api.patch(`/admin/delivery-drivers/${i.id}/status`, { is_active: !i.is_active }); load(); } catch (e) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: msg(e), life: 4000 }); } }
async function toggleAvailability(i) { try { await api.patch(`/admin/delivery-drivers/${i.id}/availability`, { is_available: !i.is_available }); load(); } catch (e) { toast.add({ severity: 'warn', summary: 'Operación rechazada', detail: msg(e), life: 4000 }); } }

onMounted(load);
</script>

<style scoped>
.metric,.panel{background:#fff;border-radius:1rem;padding:1rem;box-shadow:0 1px 2px #0f172a14}.metric span,.metric small{display:block;color:#64748b}.metric strong{font-size:1.7rem}.panel,.grid{display:grid;gap:.75rem}.panel{grid-template-columns:1fr 12rem auto}.field{display:block;width:100%;margin-top:.25rem;border:1px solid #cbd5e1;border-radius:.6rem;padding:.6rem}.field-error{display:block;margin-top:.35rem;color:#b91c1c;font-size:.8rem}.primary,.secondary,.icon{min-height:2.5rem;border-radius:.55rem;padding:.5rem .8rem;font-weight:700}.primary{background:#2563eb;color:#fff}.secondary,.icon{border:1px solid #cbd5e1}.icon{width:2.5rem}.error{background:#fef2f2;padding:1rem;color:#991b1b}.modal-section{border:1px solid #e2e8f0;border-radius:.85rem;padding:1rem}.modal-section h3{font-weight:700;color:#0f172a}.modal-section p{margin:.15rem 0 .85rem;color:#64748b;font-size:.875rem}.check-field{display:flex;align-items:flex-start;gap:.7rem;border:1px solid #cbd5e1;border-radius:.65rem;padding:.75rem;cursor:pointer}.check-field input{margin-top:.2rem}.check-field b,.check-field small{display:block}.check-field small{margin-top:.15rem;color:#64748b}.modal-close{display:inline-flex;height:2.5rem;width:2.5rem;align-items:center;justify-content:center;border-radius:.6rem;color:#475569}.modal-close:hover{background:#f1f5f9}.modal-close:focus-visible,.check-field:focus-within{outline:2px solid #2563eb;outline-offset:2px}th,td{padding:1rem;border-bottom:1px solid #e2e8f0;text-align:left}thead{background:#f1f5f9}@media(max-width:640px){.panel,.grid{grid-template-columns:1fr}}
</style>
