<template>
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5" aria-labelledby="commercial-documents-title">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-800"><i class="pi pi-file-pdf" aria-hidden="true" /></span>
            <div class="min-w-0 flex-1">
                <h2 id="commercial-documents-title" class="font-bold text-slate-950">Documentos comerciales</h2>
                <p class="mt-1 text-sm text-slate-600">Descargas privadas informativas. No son comprobantes de pago tributarios.</p>
            </div>
        </div>
        <p v-if="error" class="mt-3 text-sm text-rose-800" role="alert">{{ error }}</p>
        <div v-else-if="loading" class="mt-3 text-sm text-slate-600" role="status">Consultando disponibilidad…</div>
        <div v-else class="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <Button type="button" label="Descargar resumen del pedido" icon="pi pi-download" size="small" outlined :disabled="busy" @click="download('summary')" />
            <Button v-if="availability?.payment_constancy_available" type="button" label="Descargar constancia de pago" icon="pi pi-download" size="small" :disabled="busy" @click="download('payment-constancy')" />
            <p v-else class="self-center text-sm text-slate-600" role="status">{{ availability?.payment_constancy_unavailable_reason || 'La constancia estará disponible cuando exista un pago aprobado verificable.' }}</p>
        </div>
        <p v-if="!loading && availability?.payment_constancy_available" class="mt-3 text-sm text-slate-700">{{ availability.payment_progress_label }} · Aprobado: S/ {{ money(availability.approved_amount) }}<template v-if="Number(availability.refunded_amount) > 0"> · Devuelto: S/ {{ money(availability.refunded_amount) }}</template><template v-if="Number(availability.pending_refund_amount) > 0"> · Devolución en revisión: S/ {{ money(availability.pending_refund_amount) }}</template><template v-if="availability.payment_progress_label === 'Pago parcial'"> · Saldo pendiente: S/ {{ money(availability.remaining_amount) }}</template></p>
        <p v-if="downloadError" class="mt-3 text-sm text-rose-800" role="alert">{{ downloadError }}</p>
    </section>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import Button from 'primevue/button';
import api from '@/api';

const props = defineProps({ orderId: { type: [Number, String], required: true }, scope: { type: String, default: 'customer' } });
const availability = ref(null), loading = ref(false), busy = ref(false), error = ref(''), downloadError = ref('');
let requestSequence = 0;
let activeUrl = null;
function money(value) { return Number(value || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function basePath() {
    const prefix = props.scope === 'customer' ? '' : `/${props.scope}`;
    return `${prefix}/orders/${encodeURIComponent(props.orderId)}/commercial-documents`;
}

watch(() => [props.orderId, props.scope], async () => {
    const sequence = ++requestSequence;
    availability.value = null; error.value = ''; downloadError.value = '';
    if (!props.orderId) return;
    loading.value = true;
    try {
        const response = await api.get(basePath());
        if (sequence === requestSequence) availability.value = response.data;
    } catch {
        if (sequence === requestSequence) error.value = 'No se pudo consultar la disponibilidad de los documentos.';
    } finally {
        if (sequence === requestSequence) loading.value = false;
    }
}, { immediate: true });

async function download(kind) {
    if (busy.value) return;
    busy.value = true; downloadError.value = '';
    try {
        const response = await api.get(`${basePath()}/${kind}`, { responseType: 'blob' });
        if (activeUrl) URL.revokeObjectURL(activeUrl);
        activeUrl = URL.createObjectURL(response.data);
        const downloadUrl = activeUrl;
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = `${kind === 'summary' ? 'resumen-pedido' : 'constancia-pago'}-${props.orderId}.pdf`;
        link.click();
        window.setTimeout(() => {
            URL.revokeObjectURL(downloadUrl);
            if (activeUrl === downloadUrl) activeUrl = null;
        }, 1000);
    } catch {
        downloadError.value = 'No se pudo descargar el documento. Actualiza la información e inténtalo nuevamente.';
    } finally { busy.value = false; }
}

onBeforeUnmount(() => { requestSequence++; if (activeUrl) URL.revokeObjectURL(activeUrl); });
</script>
