<template>
  <AdminLayout>
    <main class="mx-auto max-w-7xl space-y-6">
      <AdminPageHeader eyebrow="TESORERÍA Y COBROS" title="Cuentas receptoras" description="Configura los destinos autorizados para Yape, Plin y transferencias bancarias.">
        <template #action><button class="primary" @click="open()"><i class="pi pi-plus mr-2" />Nueva cuenta</button></template>
      </AdminPageHeader>
      <section class="metric">Total de cuentas <b>{{ meta.total || 0 }}</b></section>
      <form class="panel" @submit.prevent="load(1)">
        <select v-model="filters.channel" class="field"><option value="">Todos los canales</option><option value="yape">Yape</option><option value="plin">Plin</option><option value="bank_transfer">Transferencia bancaria</option></select>
        <select v-model="filters.is_active" class="field"><option value="">Todos los estados</option><option value="1">Activas</option><option value="0">Inactivas</option></select>
        <button class="primary">Aplicar</button><button type="button" class="secondary" @click="clear">Limpiar</button><button type="button" class="secondary" @click="load()">Actualizar</button>
      </form>
      <div v-if="error" class="error" role="alert">{{ error }} <button @click="load()">Reintentar</button></div>
      <section v-else class="rounded-2xl bg-white shadow-sm">
        <div class="overflow-x-auto"><table class="min-w-[1000px] w-full text-sm"><thead><tr><th v-for="header in ['Código', 'Canal', 'Cuenta receptora', 'Datos de destino', 'QR', 'Predeterminada', 'Estado', 'Acciones']" :key="header" scope="col">{{ header }}</th></tr></thead>
          <tbody><template v-if="loading"><tr v-for="n in 5" :key="n"><td v-for="c in 8" :key="c"><span class="skeleton" /></td></tr></template>
            <template v-else-if="items.length"><tr v-for="item in items" :key="item.id"><td><b>{{ item.code }}</b></td><td><AdminStatusBadge :active="true" :active-label="label(item.channel)" /></td><td><b>{{ item.display_name }}</b><small>{{ item.holder_name }}</small></td><td>{{ destination(item) }}</td><td><AdminStatusBadge :active="item.has_qr" active-label="Configurado" inactive-label="Sin QR" /></td><td><AdminStatusBadge :active="item.is_default" active-label="Predeterminada" inactive-label="No predeterminada" /></td><td><AdminStatusBadge :active="item.is_active" active-label="Activa" inactive-label="Inactiva" /></td><td><AdminIconButton icon="pi pi-pencil" :label="`Editar ${item.code}`" @click="open(item)" /><AdminIconButton icon="pi pi-upload" :label="`Cargar QR ${item.code}`" @click="chooseQr(item)" /><AdminIconButton v-if="item.has_qr" icon="pi pi-eye" :label="`Ver QR ${item.code}`" @click="preview(item)" /><AdminIconButton :icon="item.is_active ? 'pi pi-ban' : 'pi pi-check'" :label="item.is_active ? 'Desactivar' : 'Activar'" :disabled="['yape', 'plin'].includes(item.channel) && !item.has_qr && !item.is_active" @click="status(item)" /></td></tr></template>
            <tr v-else><td colspan="8"><AdminEmptyState title="No hay cuentas receptoras" :description="hasFilters ? 'No encontramos cuentas con los filtros seleccionados.' : 'Configura una cuenta para comenzar.'" /></td></tr>
          </tbody></table></div>
        <AdminPagination :page="meta.current_page || 1" :last-page="meta.last_page || 1" :loading="loading" :summary="summary" @change="load" />
      </section>
      <input ref="file" class="hidden" type="file" accept="image/png,image/jpeg,image/webp" @change="upload">
      <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"><form class="modal" @submit.prevent="save"><h2>{{ form.id ? 'Editar cuenta' : 'Nueva cuenta' }}</h2><label>Código<input v-model="form.code" class="field" :disabled="!!form.id" required></label><label>Canal<select v-model="form.channel" class="field" :disabled="!!form.id"><option value="yape">Yape</option><option value="plin">Plin</option><option value="bank_transfer">Transferencia bancaria</option></select></label><label>Nombre<input v-model="form.display_name" class="field" required></label><label>Titular<input v-model="form.holder_name" class="field" required></label><template v-if="form.channel === 'bank_transfer'"><label>Banco<input v-model="form.bank_name" class="field"></label><label>Cuenta <small>{{ form.account_number_masked }}</small><input v-model="form.account_number" class="field"></label><label>CCI <small>{{ form.cci_masked }}</small><input v-model="form.cci" class="field"></label></template><template v-else><label>Teléfono <small>{{ form.phone_masked }}</small><input v-model="form.phone" class="field"></label><p>Guarda la cuenta inactiva, carga QR y luego actívala.</p></template><label><input v-model="form.is_active" type="checkbox" :disabled="['yape', 'plin'].includes(form.channel) && !form.has_qr"> Activa</label><label><input v-model="form.is_default" type="checkbox" :disabled="!form.is_active"> Predeterminada</label><footer><button type="button" class="secondary" @click="closeModal">Cancelar</button><button class="primary" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button></footer></form></div>
      <div v-if="qrUrl" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"><div class="modal"><button class="secondary" @click="closePreview">Cerrar</button><img :src="qrUrl" alt="QR de cuenta receptora" class="max-h-[70vh] max-w-full"></div></div>
    </main>
  </AdminLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api from '@/api'
import AdminLayout from '@/layouts/AdminLayout.vue'
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue'
import AdminStatusBadge from '@/components/admin/AdminStatusBadge.vue'
import AdminIconButton from '@/components/admin/AdminIconButton.vue'
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue'
import AdminPagination from '@/components/admin/AdminPagination.vue'

const items = ref([]), meta = ref({}), loading = ref(false), error = ref('')
const modal = ref(false), saving = ref(false), file = ref(), selected = ref(), qrUrl = ref('')
const filters = ref({ channel: '', is_active: '' })
const form = ref({})
const hasFilters = computed(() => Object.values(filters.value).some(Boolean))
const summary = computed(() => meta.value.total ? `Mostrando ${meta.value.from}–${meta.value.to} de ${meta.value.total} cuentas` : 'Sin registros')
const label = c => ({ yape: 'Yape', plin: 'Plin', bank_transfer: 'Transferencia bancaria' }[c] || c)
const destination = i => i.phone_masked || [i.bank_name, i.account_number_masked || i.cci_masked].filter(Boolean).join(' · ') || 'Sin datos'
const message = e => e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || 'No se pudo completar la operación.'
let request = 0

async function load(page = meta.value.current_page || 1) { const id = ++request; loading.value = true; error.value = ''; try { const { data } = await api.get('/admin/treasury/receiving-accounts', { params: { page, ...Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value !== '')) } }); if (id === request) { items.value = data.data || []; meta.value = data } } catch (e) { if (id === request) error.value = message(e) } finally { if (id === request) loading.value = false } }
function clear() { filters.value = { channel: '', is_active: '' }; load(1) }
function closeModal() { modal.value = false; form.value = {}; error.value = '' }
function open(item) { form.value = item ? { ...item, phone: '', account_number: '', cci: '' } : { code: '', channel: 'yape', display_name: '', holder_name: '', currency: 'PEN', phone: '', bank_name: '', account_number: '', cci: '', sort_order: 0, is_active: false, is_default: false, has_qr: false }; modal.value = true }
function payload() { const data = { ...form.value }; ['phone_masked', 'account_number_masked', 'cci_masked', 'has_qr', 'qr_path', 'qr_disk', 'created_at', 'updated_at'].forEach(key => delete data[key]); if (form.value.id) { delete data.code; delete data.channel; ['phone', 'account_number', 'cci'].forEach(key => { if (!data[key]) delete data[key] }) } if (!data.is_active) data.is_default = false; return data }
async function save() { saving.value = true; try { form.value.id ? await api.put(`/admin/treasury/receiving-accounts/${form.value.id}`, payload()) : await api.post('/admin/treasury/receiving-accounts', payload()); closeModal(); load() } catch (e) { error.value = message(e) } finally { saving.value = false } }
function chooseQr(item) { selected.value = item; file.value.click() }
async function upload(e) { const selectedFile = e.target.files?.[0]; if (!selectedFile) return; const data = new FormData(); data.append('qr', selectedFile); try { await api.post(`/admin/treasury/receiving-accounts/${selected.value.id}/qr`, data); load() } catch (err) { error.value = message(err) } finally { e.target.value = '' } }
async function preview(item) { try { const response = await api.get(`/admin/treasury/receiving-accounts/${item.id}/qr`, { responseType: 'blob' }); if (!['image/png', 'image/jpeg', 'image/webp'].some(type => response.headers['content-type']?.includes(type))) throw Error(); closePreview(); qrUrl.value = URL.createObjectURL(response.data) } catch { error.value = 'No se pudo cargar el QR.' } }
function closePreview() { if (qrUrl.value) URL.revokeObjectURL(qrUrl.value); qrUrl.value = '' }
async function status(item) { try { await api.patch(`/admin/treasury/receiving-accounts/${item.id}/status`, { is_active: !item.is_active }); load() } catch (e) { error.value = message(e) } }
onMounted(load); onBeforeUnmount(closePreview)
</script>

<style scoped>.metric,.panel,.modal{padding:1rem;border-radius:1rem;background:white}.panel{display:flex;gap:.75rem;flex-wrap:wrap}.field{border:1px solid #cbd5e1;border-radius:.5rem;padding:.6rem}.primary,.secondary{padding:.6rem .9rem;border-radius:.5rem}.primary{background:#0f766e;color:white}.secondary{background:#e2e8f0}.skeleton{display:block;height:1rem;background:#e2e8f0;border-radius:.25rem}.modal{width:min(100%,42rem);max-height:90vh;overflow:auto;display:grid;gap:.75rem}footer{display:flex;gap:.75rem;justify-content:flex-end}.error{padding:1rem;background:#fee2e2;color:#991b1b}@media(max-width:640px){footer{flex-direction:column}}</style>
