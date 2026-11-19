<template>
  <AdminLayout>
    <main class="mx-auto max-w-5xl space-y-6" aria-labelledby="einvoice-title">
      <header class="rounded-2xl bg-slate-900 p-6 text-white"><p class="text-xs font-bold uppercase tracking-widest text-blue-300">CONFIGURACIÓN ADMINISTRATIVA</p><h1 id="einvoice-title" class="mt-2 text-3xl font-black">Facturación electrónica</h1><p class="mt-2 max-w-2xl text-sm text-slate-300">Selecciona la conexión y entorno para preparar la siguiente fase. No se almacenan credenciales ni se envían documentos desde esta base.</p></header>
      <p v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ error }}</p>
      <p v-if="message" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ message }}</p>
      <section class="rounded-2xl border bg-white p-5 shadow-sm sm:p-7" aria-labelledby="connection-heading">
        <div v-if="loading" role="status" class="py-8 text-center">Cargando configuración…</div>
        <form v-else class="space-y-5" @submit.prevent="save">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="connection-heading" class="text-xl font-bold">Conexión operativa</h2><p class="text-sm text-slate-600">El modo de prueba se establece por defecto y no implica conexión real.</p></div><Tag :severity="state.commercial_enabled ? 'success' : 'secondary'" :value="state.commercial_enabled ? 'Módulo habilitado' : 'Módulo no habilitado'" /></div>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-semibold">Mecanismo<select v-model="form.connection_type" class="field mt-1"><option value="direct_sunat">Conexión directa con SUNAT</option><option value="provider_api">API de proveedor</option></select></label>
            <label v-if="form.connection_type === 'provider_api'" class="text-sm font-semibold">Identificador del proveedor<input v-model.trim="form.provider_name" class="field mt-1" maxlength="80" autocomplete="off" /><small class="block font-normal text-slate-500">Identificador técnico, no es una credencial.</small></label>
            <label class="text-sm font-semibold">Entorno<select v-model="form.environment" class="field mt-1"><option value="testing">Pruebas</option><option value="production">Producción (sin conexión habilitada)</option></select></label>
          </div>
          <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ state.connection_available ? 'Conexión disponible.' : 'Adaptador no implementado. La emisión y consulta de estado responderán con un error controlado; no se simulará aceptación.' }}</p>
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
      <section class="rounded-2xl border border-blue-200 bg-blue-50 p-5 text-sm text-blue-950"><h2 class="font-bold">Alcance de esta etapa</h2><p class="mt-1">Se permite preparar borradores y corregirlos con control de versión. No hay conexión ni emisión real a SUNAT o proveedores. Pedidos, pagos, reservas e inventario conservan sus importes.</p></section>
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
const state = reactive({ commercial_enabled: false, connection_available: false, readiness: {}, version: 1 });
const form = reactive({ connection_type: 'direct_sunat', provider_name: '', environment: 'testing' });
const taxForm = reactive({ igv_rate: '15.50', ipm_rate: '2.50', effective_from: '', version: 0, configured: false, scheduled: false, business_timezone: '' });
const businessTimezone = computed(() => taxForm.business_timezone || 'zona del negocio');
const labels = { seller_name: 'Nombre del emisor', seller_tax_id: 'Identificación tributaria', fiscal_address: 'Dirección fiscal', provider_adapter: 'Adaptador de conexión', tax_rules: 'Reglas tributarias', credentials: 'Credenciales' };
async function load() { loading.value = true; error.value = ''; try { const { data } = (await api.get('/admin/electronic-invoicing/settings')).data; Object.assign(state, data); Object.assign(form, { connection_type: data.connection_type, provider_name: data.provider_name || '', environment: data.environment }); } catch (exception) { error.value = exception.response?.data?.message || 'No se pudo cargar la configuración.'; } finally { loading.value = false; } }
async function save() { saving.value = true; error.value = ''; message.value = ''; try { const { data } = (await api.put('/admin/electronic-invoicing/settings', { ...form, provider_name: form.provider_name || null, version: state.version })).data; Object.assign(state, data); message.value = 'Configuración guardada. No se inició ninguna conexión.'; toast.add({ severity: 'success', summary: 'Configuración guardada', detail: message.value, life: 4000 }); } catch (exception) { error.value = exception.response?.status === 409 ? 'La configuración cambió o hay una operación incierta. Recarga y vuelve a revisar.' : exception.response?.data?.message || 'No se pudo guardar la configuración.'; } finally { saving.value = false; } }
async function loadTax() { try { const { data } = (await api.get('/admin/electronic-invoicing/tax-configuration')).data; Object.assign(taxForm, data); } catch (exception) { taxError.value = exception.response?.data?.message || 'No se pudo cargar la configuración tributaria.'; } }
async function saveTax() { taxSaving.value = true; taxError.value = ''; taxMessage.value = ''; try { const { data } = (await api.put('/admin/electronic-invoicing/tax-configuration', { igv_rate: taxForm.igv_rate, ipm_rate: taxForm.ipm_rate, effective_from: taxForm.effective_from, prices_include_tax: true, version: taxForm.version })).data; Object.assign(taxForm, data); taxMessage.value = 'Tasas guardadas. La afectación de cada producto se conserva por separado.'; } catch (exception) { taxError.value = exception.response?.status === 409 ? 'La configuración cambió. Recárgala antes de guardar.' : exception.response?.data?.message || 'No se pudo guardar la configuración tributaria.'; } finally { taxSaving.value = false; } }
onMounted(async () => { await Promise.all([load(), loadTax()]); });
</script>

<style scoped>
.field{min-height:2.75rem;width:100%;border:1px solid #cbd5e1;border-radius:.625rem;background:#fff;padding:.625rem .75rem;color:#0f172a}.field:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
</style>
