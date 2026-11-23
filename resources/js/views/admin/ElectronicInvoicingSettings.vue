<template>
  <AdminLayout>
    <main class="mx-auto max-w-5xl space-y-6" aria-labelledby="einvoice-title">
      <header class="rounded-2xl bg-slate-900 p-6 text-white"><p class="text-xs font-bold uppercase tracking-widest text-blue-300">CONFIGURACIÓN ADMINISTRATIVA</p><h1 id="einvoice-title" class="mt-2 text-3xl font-black">Facturación electrónica</h1><p class="mt-2 max-w-2xl text-sm text-slate-300">Conexión directa únicamente al ambiente beta de SUNAT. Las credenciales se cifran y el certificado queda en almacenamiento privado.</p></header>
      <p v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ error }}</p>
      <p v-if="message" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ message }}</p>
      <section class="rounded-2xl border bg-white p-5 shadow-sm sm:p-7" aria-labelledby="connection-heading">
        <div v-if="loading" role="status" class="py-8 text-center">Cargando configuración…</div>
        <form v-else class="space-y-5" @submit.prevent="save">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="connection-heading" class="text-xl font-bold">Conexión operativa</h2><p class="text-sm text-slate-600">El modo de prueba se establece por defecto y no implica conexión real.</p></div><Tag :severity="state.commercial_enabled ? 'success' : 'secondary'" :value="state.commercial_enabled ? 'Módulo habilitado' : 'Módulo no habilitado'" /></div>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-semibold">Mecanismo<select v-model="form.connection_type" class="field mt-1"><option value="direct_sunat">Conexión directa con SUNAT</option><option value="provider_api">API de proveedor</option></select></label>
            <label v-if="form.connection_type === 'provider_api'" class="text-sm font-semibold">Identificador del proveedor<input v-model.trim="form.provider_name" class="field mt-1" maxlength="80" autocomplete="off" /><small class="block font-normal text-slate-500">Identificador técnico, no es una credencial.</small></label>
            <label class="text-sm font-semibold">Entorno<select v-model="form.environment" class="field mt-1"><option value="testing">Pruebas SUNAT beta</option></select></label>
          </div>
          <div class="grid gap-4 rounded-xl border bg-slate-50 p-4 sm:grid-cols-2">
            <label class="text-sm font-semibold">Usuario SOL de pruebas<input v-model.trim="form.sunat_username" class="field mt-1" autocomplete="off" /><small class="block font-normal text-slate-600">Opcional: vacío usa el usuario de pruebas MODDATOS.</small></label>
            <label class="text-sm font-semibold">Clave SOL<input v-model="form.sunat_password" class="field mt-1" type="password" autocomplete="new-password" /><small class="block font-normal text-slate-600">Vacío conserva la clave cifrada; sin clave configurada, beta usa MODDATOS.</small></label>
            <label class="text-sm font-semibold">Certificado de firma (.p12/.pfx)<input class="field mt-1" type="file" accept=".p12,.pfx,application/x-pkcs12" @change="selectCertificate" /><small class="block font-normal text-slate-600">Máximo 4 MB. Se valida la contraseña, vigencia, RUC y clave.</small></label>
            <label v-if="certificateFile" class="text-sm font-semibold">Contraseña del certificado<input v-model="form.certificate_password" class="field mt-1" type="password" autocomplete="new-password" /></label>
            <label v-if="state.certificate_present" class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.remove_certificate" type="checkbox" /> Retirar el certificado guardado</label>
            <p v-if="state.certificate_present" class="text-xs text-slate-600 sm:col-span-2">Certificado registrado{{ state.certificate_expires_at ? `; vence ${formatDate(state.certificate_expires_at)}` : '' }}. El archivo y secretos no se devuelven.</p>
          </div>
          <section class="rounded-xl border p-4" aria-labelledby="series-title"><h3 id="series-title" class="font-bold">Serie y correlativo de pruebas</h3><p class="text-sm text-slate-600">La numeración se bloquea después de asignar documentos o registrar intentos.</p><div class="mt-3 grid gap-3 sm:grid-cols-4"><label class="text-sm font-semibold">Tipo<select v-model="seriesForm.document_type" class="field mt-1"><option value="factura">Factura</option><option value="boleta">Boleta</option></select></label><label class="text-sm font-semibold">Serie<input v-model.trim="seriesForm.series" class="field mt-1" maxlength="4" /></label><label class="text-sm font-semibold">Siguiente correlativo<input v-model.number="seriesForm.next_number" class="field mt-1" type="number" min="1" max="99999999" /></label><div class="flex items-end"><Button type="button" label="Guardar numeración" icon="pi pi-save" size="small" :loading="seriesSaving" :disabled="seriesSaving" @click="saveSeries" /></div></div></section>
          <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">{{ state.connection_available ? 'Requisitos técnicos disponibles para generar boletas firmadas localmente y preparar resúmenes simulados; estos no se comunican con SUNAT.' : 'Generación local bloqueada hasta contar con certificado vigente, runtime XML/OpenSSL, extensión ZIP, cola durable y esquema oficial UBL 2.1 local.' }}</p>
          <div><h3 class="font-bold">Requisitos pendientes</h3><ul class="mt-2 grid gap-2 sm:grid-cols-2"><li v-for="(ready, key) in state.readiness || {}" :key="key" class="flex gap-2 rounded-lg border p-3 text-sm"><i :class="ready ? 'pi pi-check text-emerald-600' : 'pi pi-clock text-amber-600'" aria-hidden="true"></i><span>{{ labels[key] || key }}: {{ ready ? 'listo' : 'pendiente' }}</span></li></ul></div>
          <div class="flex justify-end"><Button type="submit" label="Guardar configuración" icon="pi pi-save" :loading="saving" :disabled="saving" /></div>
        </form>
      </section>
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="tax-heading">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="tax-heading" class="text-xl font-bold">Cálculo tributario</h2><p class="mt-1 text-sm text-slate-600">Configura tasas para precios finales con impuestos incluidos. Zona horaria: {{ businessTimezone }}.</p></div><Tag :severity="taxForm.configured ? 'success' : 'warn'" :value="taxForm.configured ? 'Configuración vigente' : taxForm.scheduled ? 'Programada' : 'Pendiente'" /></div>
        <p v-if="taxError" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-900" role="alert">{{ taxError }}</p><p v-if="taxMessage" class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900" role="status">{{ taxMessage }}</p>
        <form class="mt-5 space-y-4" @submit.prevent="saveTax">
          <div class="grid gap-4 sm:grid-cols-3"><label class="text-sm font-semibold">IGV (%)<input v-model.trim="taxForm.igv_rate" inputmode="decimal" required class="field mt-1" placeholder="15.50" /></label><label class="text-sm font-semibold">IPM (%)<input v-model.trim="taxForm.ipm_rate" inputmode="decimal" required class="field mt-1" placeholder="2.50" /></label><label class="text-sm font-semibold">Vigente desde<input v-model="taxForm.effective_from" type="date" required class="field mt-1" /></label></div>
          <p class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950">El cálculo solo aplica a productos clasificados explícitamente. El total comercial confirmado no cambia. El envío cobrado con afectaciones mixtas queda pendiente de revisión contable; envío gratuito y recojo tienen importe cero.</p>
          <div class="flex justify-end"><Button type="submit" label="Guardar tasas tributarias" icon="pi pi-save" :loading="taxSaving" :disabled="taxSaving" /></div>
        </form>
      </section>
      <section class="rounded-2xl border border-blue-200 bg-blue-50 p-5 text-sm text-blue-950"><h2 class="font-bold">Alcance y bloqueos</h2><p class="mt-1">Las facturas pueden procesarse en ambiente Beta. Las boletas se generan y firman localmente y su resumen usa únicamente LOCAL-SIM; no se envía a SUNAT ni constituye aceptación. Anticipos, envío cobrado con afectación mixta e información histórica incompleta permanecen bloqueados. Pagos, reservas e inventario no se modifican.</p></section>
    </main>
  </AdminLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const toast = useToast();
const loading = ref(true), saving = ref(false), error = ref(''), message = ref('');
const taxSaving = ref(false), taxError = ref(''), taxMessage = ref('');
const state = reactive({ commercial_enabled: false, connection_available: false, certificate_present: false, certificate_expires_at: null, readiness: {}, series: [], version: 1 });
const form = reactive({ connection_type: 'direct_sunat', provider_name: '', environment: 'testing', sunat_username: '', sunat_password: '', certificate_password: '', remove_certificate: false });
const seriesForm = reactive({ document_type: 'factura', series: 'F001', next_number: 1 });
const certificateFile = ref(null), seriesSaving = ref(false);
const taxForm = reactive({ igv_rate: '15.50', ipm_rate: '2.50', effective_from: '', version: 0, configured: false, scheduled: false, business_timezone: '' });
const businessTimezone = computed(() => taxForm.business_timezone || 'zona del negocio');
const labels = { seller_name: 'Nombre del emisor', seller_tax_id: 'Identificación tributaria', fiscal_address: 'Dirección fiscal', provider_adapter: 'Conexión directa', tax_rules: 'Reglas tributarias', credentials: 'Certificado de firma', xml_openssl_runtime: 'Runtime XML y OpenSSL', zip_runtime: 'Extensión ZIP de PHP', durable_queue: 'Cola durable', official_schema: 'Esquema oficial UBL 2.1', commercial_enabled: 'Módulo habilitado' };
function selectCertificate(event) { certificateFile.value = event.target.files?.[0] || null; form.remove_certificate = false; }
function formatDate(value) { return value ? new Date(value).toLocaleDateString('es-PE') : ''; }
async function load() { loading.value = true; error.value = ''; try { const { data } = (await api.get('/admin/electronic-invoicing/settings')).data; Object.assign(state, data); Object.assign(form, { connection_type: data.connection_type, provider_name: data.provider_name || '', environment: data.environment }); const invoice = data.series?.find(item => item.document_type === 'factura'); if (invoice) Object.assign(seriesForm, invoice); } catch (exception) { error.value = exception.response?.data?.message || 'No se pudo cargar la configuración.'; } finally { loading.value = false; } }
async function save() { saving.value = true; error.value = ''; message.value = ''; try { const payload = new FormData(); payload.append('_method', 'PUT'); payload.append('connection_type', form.connection_type); payload.append('provider_name', form.provider_name || ''); payload.append('environment', 'testing'); payload.append('version', String(state.version)); payload.append('sunat_username', form.sunat_username); payload.append('sunat_password', form.sunat_password); payload.append('remove_certificate', form.remove_certificate ? '1' : '0'); if (certificateFile.value) { payload.append('certificate', certificateFile.value); payload.append('certificate_password', form.certificate_password); } const { data } = (await api.post('/admin/electronic-invoicing/settings', payload)).data; Object.assign(state, data); form.sunat_username = ''; form.sunat_password = ''; form.certificate_password = ''; certificateFile.value = null; form.remove_certificate = false; message.value = 'Configuración de pruebas guardada. Los secretos no se muestran.'; toast.add({ severity: 'success', summary: 'Configuración guardada', detail: message.value, life: 4000 }); await load(); } catch (exception) { error.value = exception.response?.status === 409 ? 'La configuración cambió o hay un intento pendiente. Recarga y vuelve a revisar.' : exception.response?.data?.message || 'No se pudo guardar la configuración.'; } finally { saving.value = false; } }
async function saveSeries() { seriesSaving.value = true; error.value = ''; try { const { data } = (await api.put('/admin/electronic-invoicing/series', seriesForm)).data; message.value = `Serie ${data.series} guardada; siguiente correlativo ${data.next_number}.`; await load(); } catch (exception) { error.value = exception.response?.data?.message || 'No se pudo guardar la numeración.'; } finally { seriesSaving.value = false; } }
async function loadTax() { try { const { data } = (await api.get('/admin/electronic-invoicing/tax-configuration')).data; Object.assign(taxForm, data); } catch (exception) { taxError.value = exception.response?.data?.message || 'No se pudo cargar la configuración tributaria.'; } }
async function saveTax() { taxSaving.value = true; taxError.value = ''; taxMessage.value = ''; try { const { data } = (await api.put('/admin/electronic-invoicing/tax-configuration', { igv_rate: taxForm.igv_rate, ipm_rate: taxForm.ipm_rate, effective_from: taxForm.effective_from, prices_include_tax: true, version: taxForm.version })).data; Object.assign(taxForm, data); taxMessage.value = 'Tasas guardadas. La afectación de cada producto se conserva por separado.'; } catch (exception) { taxError.value = exception.response?.status === 409 ? 'La configuración cambió. Recárgala antes de guardar.' : exception.response?.data?.message || 'No se pudo guardar la configuración tributaria.'; } finally { taxSaving.value = false; } }
onMounted(async () => { await Promise.all([load(), loadTax()]); });
</script>

<style scoped>
.field{min-height:2.75rem;width:100%;border:1px solid #cbd5e1;border-radius:.625rem;background:#fff;padding:.625rem .75rem;color:#0f172a}.field:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
</style>
