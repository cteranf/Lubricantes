<template>
    <AdminLayout>
        <main class="mx-auto max-w-6xl space-y-6" aria-labelledby="business-profile-title">
            <AdminPageHeader eyebrow="ADMINISTRACIÓN" title="Datos del negocio" title-id="business-profile-title" description="Mantén la identidad del vendedor de esta instalación para futuros documentos comerciales.">
            </AdminPageHeader>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-600" role="status">
                <i class="pi pi-spin pi-spinner mr-2" aria-hidden="true"></i>Cargando datos del negocio…
            </div>
            <section v-else-if="loadError" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-900" role="alert">
                <p class="font-bold">No se pudieron cargar los datos del negocio</p>
                <p class="mt-1 text-sm">{{ loadError }}</p>
                <Button class="mt-3" label="Reintentar" severity="secondary" outlined @click="load" />
            </section>
            <form v-else class="space-y-6" @submit.prevent="save">
                <p v-if="formError" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">{{ formError }}</p>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="identity-heading">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h2 id="identity-heading" class="text-lg font-bold text-slate-900">Identidad del negocio</h2>
                        <p class="mt-1 text-sm text-slate-600">Los campos son opcionales durante la configuración progresiva.</p>
                    </div>
                    <div class="grid gap-5 md:grid-cols-2">
                        <FieldInput class="form-control" v-model="form.commercial_name" label="Nombre comercial" :error="fieldError('commercial_name')" autocomplete="organization" />
                        <FieldInput v-model="form.legal_name" label="Razón social o nombre legal" :error="fieldError('legal_name')" autocomplete="organization" />
                        <div>
                            <label for="tax-id-type" class="mb-1.5 block text-sm font-semibold text-slate-700">Tipo de identificación tributaria</label>
                            <select id="tax-id-type" v-model="form.tax_id_type" class="form-control">
                                <option value="">Sin configurar</option><option value="RUC">RUC (Perú)</option>
                            </select>
                            <p v-if="fieldError('tax_id_type')" class="field-error">{{ fieldError('tax_id_type') }}</p>
                        </div>
                        <div>
                            <label for="tax-id-number" class="mb-1.5 block text-sm font-semibold text-slate-700">Número de identificación tributaria</label>
                            <input id="tax-id-number" v-model="form.tax_id_number" type="text" inputmode="numeric" maxlength="32" autocomplete="off" class="form-control" :aria-invalid="Boolean(fieldError('tax_id_number'))">
                            <p class="mt-1 text-xs text-slate-500">El RUC se conserva como texto. La comprobación de formato es local y no consulta SUNAT.</p>
                            <p v-if="fieldError('tax_id_number')" class="field-error">{{ fieldError('tax_id_number') }}</p>
                        </div>
                        <div>
                            <label for="country-code" class="mb-1.5 block text-sm font-semibold text-slate-700">País</label>
                            <select id="country-code" v-model="form.country_code" class="form-control"><option value="PE">Perú</option></select>
                            <p v-if="fieldError('country_code')" class="field-error">{{ fieldError('country_code') }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="contact-heading">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h2 id="contact-heading" class="text-lg font-bold text-slate-900">Dirección y contacto</h2>
                    </div>
                    <div class="grid gap-5 md:grid-cols-2">
                        <div class="md:col-span-2"><FieldInput v-model="form.fiscal_address" label="Dirección fiscal" :error="fieldError('fiscal_address')" autocomplete="street-address" /></div>
                        <FieldInput v-model="form.contact_email" label="Correo de contacto" type="email" :error="fieldError('contact_email')" autocomplete="email" />
                        <FieldInput v-model="form.contact_phone" label="Teléfono de contacto" type="tel" :error="fieldError('contact_phone')" autocomplete="tel" />
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="logo-heading">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h2 id="logo-heading" class="text-lg font-bold text-slate-900">Logo</h2>
                        <p class="mt-1 text-sm text-slate-600">JPEG, PNG o WebP; máximo {{ logoLimitText }} y dimensiones de hasta {{ logoLimits.max_width }} × {{ logoLimits.max_height }} px. La imagen se guarda en el almacenamiento público para usos de marca.</p>
                    </div>
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                        <div class="flex h-32 w-48 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <img v-if="previewUrl" :src="previewUrl" alt="Vista previa del logo del negocio" class="max-h-full max-w-full object-contain">
                            <span v-else class="text-sm text-slate-400">Sin logo</span>
                        </div>
                        <div class="space-y-3">
                            <label for="business-logo" class="block text-sm font-semibold text-slate-700">Seleccionar un logo</label>
                            <input id="business-logo" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full max-w-md text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:font-semibold file:text-blue-800 hover:file:bg-blue-100" @change="selectLogo">
                            <p v-if="fieldError('logo')" class="field-error">{{ fieldError('logo') }}</p>
                            <button v-if="localLogo" type="button" class="text-sm font-semibold text-slate-700 underline" @click="clearLocalLogo">Retirar vista previa nueva</button>
                            <button v-else-if="form.logo_url && !removeLogo" type="button" class="text-sm font-semibold text-red-700 underline" @click="removeSavedLogo">Eliminar logo guardado</button>
                            <p v-if="removeLogo" class="text-sm text-amber-800" role="status">El logo guardado se eliminará al guardar.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-blue-100 bg-blue-50 p-5 sm:p-7" aria-labelledby="operational-heading">
                    <h2 id="operational-heading" class="text-lg font-bold text-slate-900">Configuración operativa de consulta</h2>
                    <p class="mt-1 text-sm text-slate-600">Estos valores se muestran desde la configuración vigente; no se editan en esta ficha.</p>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl bg-white p-4"><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Moneda</dt><dd class="mt-1 font-semibold text-slate-900">{{ operational.currency }}</dd><dd class="text-xs text-slate-500">Origen: {{ operational.currency_source }}</dd></div>
                        <div class="rounded-xl bg-white p-4"><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Zona horaria</dt><dd class="mt-1 font-semibold text-slate-900">{{ operational.timezone }}</dd><dd class="text-xs text-slate-500">Origen: {{ operational.timezone_source }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="receipt-settings-heading">
                    <div class="mb-4 border-b border-slate-100 pb-4"><h2 id="receipt-settings-heading" class="text-lg font-bold text-slate-900">Documentos que se pueden solicitar</h2><p class="mt-1 text-sm text-slate-600">Habilitar un documento permite que el cliente lo solicite durante la compra. No significa que se emita automáticamente.</p></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex min-h-14 items-center gap-3 rounded-xl border p-4" :class="receiptSettings.boleta_enabled ? 'border-blue-300 bg-blue-50' : 'border-slate-200'"><input v-model="receiptSettings.boleta_enabled" type="checkbox" :disabled="receiptSettings.boleta_enabled && !receiptSettings.factura_enabled" class="h-5 w-5 accent-blue-700"><span><b>Boleta de venta</b><span class="block text-xs text-slate-500">Habilitada por defecto</span></span></label>
                        <label class="flex min-h-14 items-center gap-3 rounded-xl border p-4" :class="receiptSettings.factura_enabled ? 'border-blue-300 bg-blue-50' : 'border-slate-200'"><input v-model="receiptSettings.factura_enabled" type="checkbox" :disabled="receiptSettings.factura_enabled && !receiptSettings.boleta_enabled" class="h-5 w-5 accent-blue-700"><span><b>Factura</b><span class="block text-xs text-slate-500">Deshabilitada por defecto</span></span></label>
                    </div>
                    <label class="mt-4 block max-w-md text-sm font-semibold text-slate-700">Documento inicial del checkout<select v-model="receiptSettings.default_receipt_type" class="form-control mt-1"><option v-if="receiptSettings.boleta_enabled" value="boleta">Boleta de venta</option><option v-if="receiptSettings.factura_enabled" value="factura">Factura</option></select></label>
                    <p v-if="fieldError('boleta_enabled') || fieldError('default_receipt_type')" class="field-error">{{ fieldError('boleta_enabled') || fieldError('default_receipt_type') }}</p>
                </section>

                <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5" aria-labelledby="pending-heading">
                    <h2 id="pending-heading" class="font-bold text-amber-950">Información pendiente</h2>
                    <p v-if="pendingFields.length === 0" class="mt-1 text-sm text-amber-900">La ficha tiene completos los datos identificados para esta etapa.</p>
                    <ul v-else class="mt-2 grid gap-1 text-sm text-amber-900 sm:grid-cols-2"><li v-for="item in pendingFields" :key="item.field">• {{ item.label }}</li></ul>
                </section>

                <div class="flex justify-end">
                    <Button type="submit" label="Guardar datos del negocio" icon="pi pi-save" :loading="saving" :disabled="saving" class="w-full sm:w-auto" />
                </div>
            </form>
        </main>
    </AdminLayout>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, reactive, ref, watch } from 'vue';
import Button from 'primevue/button';
import { useToast } from 'primevue/usetoast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import api from '@/api';

const FieldInput = defineComponent({
    inheritAttrs: false,
    props: { modelValue: { type: String, default: '' }, label: { type: String, required: true }, error: { type: String, default: '' }, type: { type: String, default: 'text' }, autocomplete: { type: String, default: 'off' } },
    emits: ['update:modelValue'],
    setup(props, { emit, attrs }) {
        const id = `business-${props.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
        return () => h('div', [
            h('label', { for: id, class: 'mb-1.5 block text-sm font-semibold text-slate-700' }, props.label),
            h('input', { ...attrs, id, type: props.type, autocomplete: props.autocomplete, value: props.modelValue, class: ['form-control', attrs.class], 'aria-invalid': Boolean(props.error), onInput: event => emit('update:modelValue', event.target.value) }),
            props.error ? h('p', { class: 'field-error' }, props.error) : null,
        ]);
    },
});

const loading = ref(true);
const saving = ref(false);
const toast = useToast();
const loadError = ref('');
const formError = ref('');
const errors = ref({});
const form = reactive({ id: null, commercial_name: '', legal_name: '', tax_id_type: 'RUC', tax_id_number: '', country_code: 'PE', fiscal_address: '', contact_email: '', contact_phone: '', logo_url: null });
const receiptSettings = reactive({ boleta_enabled: true, factura_enabled: false, default_receipt_type: 'boleta' });
const operational = reactive({ currency: 'PEN', currency_source: 'config/payment.php', timezone: 'UTC', timezone_source: 'config/app.php' });
const logoLimits = reactive({ max_kilobytes: 2048, max_width: 4000, max_height: 2500 });
const logoLimitText = computed(() => `${logoLimits.max_kilobytes / 1024} MB`);
const pendingFields = ref([]);
const localLogo = ref(null);
const localObjectUrl = ref('');
const removeLogo = ref(false);
const previewUrl = computed(() => localObjectUrl.value || (removeLogo.value ? '' : form.logo_url) || '');

function setPayload(payload) {
    const data = payload?.data ?? {};
    const profile = data.profile ?? {};
    Object.assign(form, { id: profile.id ?? null, commercial_name: profile.commercial_name ?? '', legal_name: profile.legal_name ?? '', tax_id_type: profile.tax_id_type ?? data.defaults?.tax_id_type ?? 'RUC', tax_id_number: profile.tax_id_number ?? '', country_code: profile.country_code ?? data.defaults?.country_code ?? 'PE', fiscal_address: profile.fiscal_address ?? '', contact_email: profile.contact_email ?? '', contact_phone: profile.contact_phone ?? '', logo_url: profile.logo_url ?? null });
    Object.assign(receiptSettings, data.receipt_settings ?? {});
    Object.assign(operational, data.operational_settings ?? {});
    Object.assign(logoLimits, data.logo_limits ?? {});
    pendingFields.value = data.pending_fields ?? [];
    removeLogo.value = false;
}
async function load() {
    loading.value = true; loadError.value = '';
    try { setPayload((await api.get('/admin/business-profile')).data); }
    catch { loadError.value = 'Verifica tu conexión e inténtalo nuevamente.'; }
    finally { loading.value = false; }
}
function fieldError(field) { return errors.value[field]?.[0] ?? ''; }
function selectLogo(event) {
    const selected = event.target.files?.[0] ?? null;
    revokePreview();
    localLogo.value = selected;
    localObjectUrl.value = selected ? URL.createObjectURL(selected) : '';
    removeLogo.value = false;
    errors.value = { ...errors.value, logo: undefined };
}
function revokePreview() { if (localObjectUrl.value) URL.revokeObjectURL(localObjectUrl.value); localObjectUrl.value = ''; }
function clearLocalLogo() { revokePreview(); localLogo.value = null; const input = document.getElementById('business-logo'); if (input) input.value = ''; }
function removeSavedLogo() { removeLogo.value = true; form.logo_url = null; }
async function save() {
    if (saving.value) return;
    saving.value = true; formError.value = ''; errors.value = {};
    const body = new FormData();
    for (const key of ['commercial_name', 'legal_name', 'tax_id_type', 'tax_id_number', 'country_code', 'fiscal_address', 'contact_email', 'contact_phone']) body.append(key, form[key] ?? '');
    for (const key of ['boleta_enabled', 'factura_enabled']) body.append(key, receiptSettings[key] ? '1' : '0');
    body.append('default_receipt_type', receiptSettings.default_receipt_type);
    if (localLogo.value) body.append('logo', localLogo.value);
    if (removeLogo.value) body.append('remove_logo', '1');
    body.append('_method', 'PUT');
    try {
        const response = await api.post('/admin/business-profile', body);
        setPayload(response.data);
        clearLocalLogo();
        toast.add({ severity: 'success', summary: 'Datos del negocio guardados', detail: response.data.message ?? 'Los cambios se guardaron correctamente.', life: 4000 });
    } catch (error) {
        if (error.response?.status === 422 && error.response.data?.errors) {
            errors.value = error.response.data.errors;
            formError.value = errors.value.request?.[0] ?? '';
        } else formError.value = 'No se pudieron guardar los datos. Revisa tu conexión e inténtalo nuevamente.';
    } finally { saving.value = false; }
}
onBeforeUnmount(revokePreview);
watch(() => [receiptSettings.boleta_enabled, receiptSettings.factura_enabled], ([boleta, factura]) => {
    if (receiptSettings.default_receipt_type === 'boleta' && !boleta && factura) receiptSettings.default_receipt_type = 'factura';
    if (receiptSettings.default_receipt_type === 'factura' && !factura && boleta) receiptSettings.default_receipt_type = 'boleta';
});
load();
</script>

<style scoped>
:deep(.form-control) { width: 100%; border: 1px solid #cbd5e1; border-radius: .75rem; padding: .7rem .85rem; font-size: .9rem; color: #0f172a; background: white; }
:deep(.form-control:focus) { outline: 2px solid #1d4ed8; outline-offset: 2px; }
.field-error { margin-top: .35rem; color: #b91c1c; font-size: .8rem; }
</style>
