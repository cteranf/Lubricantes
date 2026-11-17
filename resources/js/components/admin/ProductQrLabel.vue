<template>
    <Dialog :visible="modelValue" modal header="Etiqueta QR del producto" :style="{ width: 'min(28rem, 95vw)' }" @update:visible="$emit('update:modelValue', $event)">
        <div class="qr-label-content rounded-xl border border-slate-200 bg-white p-5 text-center" aria-live="polite">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Código interno LubriStore · no es GS1</p>
            <h3 class="mt-2 break-words text-lg font-bold text-slate-900">{{ product?.name }}</h3>
            <p class="mt-1 font-mono text-sm text-slate-600">{{ product?.sku || 'Sin SKU' }}</p>
            <div class="mx-auto mt-4 flex min-h-64 w-64 items-center justify-center rounded-lg bg-white p-2" role="img" :aria-label="`Código QR interno del producto ${product?.name || ''}`">
                <img v-if="qrDataUrl" :src="qrDataUrl" alt="" width="240" height="240" class="h-60 w-60" />
                <span v-else-if="qrError" class="text-sm text-red-700" role="alert">{{ qrError }}</span>
                <span v-else class="text-sm text-slate-500" role="status">Generando código…</span>
            </div>
            <p class="mt-3 text-xs text-slate-500">Escanea el código desde una recepción para identificar este producto.</p>
        </div>
        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <Button label="Cerrar" severity="secondary" text @click="$emit('update:modelValue', false)" />
                <Button label="Regenerar QR" icon="pi pi-refresh" severity="secondary" text :loading="qrGenerating" :disabled="!product?.id" @click="generateQr(true, product.id)" />
                <Button label="Imprimir etiqueta" icon="pi pi-print" severity="secondary" outlined :disabled="!qrDataUrl" @click="printLabel" />
                <a v-if="qrDataUrl" class="inline-flex min-h-10 items-center gap-2 rounded-md bg-blue-700 px-3 text-sm font-semibold text-white hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-800" :href="qrDataUrl" :download="`producto-${product?.id || 'qr'}.png`"><i class="pi pi-download" aria-hidden="true" />Descargar PNG</a>
            </div>
        </template>
    </Dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import QRCode from 'qrcode';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    product: { type: Object, default: null },
});
defineEmits(['update:modelValue']);

const qrDataUrl = ref('');
const qrError = ref('');
const qrGenerating = ref(false);
let generation = 0;

watch(() => [props.modelValue, props.product?.id], ([visible, productId]) => generateQr(visible, productId), { immediate: true });

async function generateQr(visible, productId) {
    const current = ++generation;
    qrDataUrl.value = '';
    qrError.value = '';
    if (!visible || !Number.isSafeInteger(Number(productId)) || Number(productId) < 1) {
        qrGenerating.value = false;
        return;
    }

    qrGenerating.value = true;
    try {
        qrDataUrl.value = await QRCode.toDataURL(`LS1:PRODUCT:${Number(productId)}`, {
            width: 360,
            margin: 4,
            errorCorrectionLevel: 'M',
            color: { dark: '#000000', light: '#FFFFFF' },
        });
        if (current !== generation) qrDataUrl.value = '';
    } catch {
        if (current === generation) qrError.value = 'No se pudo generar la etiqueta. Inténtalo nuevamente.';
    } finally {
        if (current === generation) qrGenerating.value = false;
    }
}

function printLabel() {
    if (qrDataUrl.value) window.print();
}
</script>

<style>
@media print {
    body * { visibility: hidden !important; }
    .qr-label-content, .qr-label-content * { visibility: visible !important; }
    .qr-label-content { position: absolute !important; inset: 0 auto auto 0 !important; width: 80mm !important; border: 1px solid #111 !important; }
}
</style>
