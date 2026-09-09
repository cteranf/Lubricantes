<template>
    <AdminLayout>
        <main class="mx-auto max-w-7xl space-y-6" aria-labelledby="payment-settings-title">
            <AdminPageHeader eyebrow="CONFIGURACIÓN COMERCIAL" title="Métodos de pago" title-id="payment-settings-title" description="Configura las alternativas de pago disponibles para los clientes.">
                <template #action>
                <button
                    type="button"
                    :disabled="saving"
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50 transition"
                    @click="saveSettings"
                >
                    <i v-if="saving" class="pi pi-spin pi-spinner"></i>
                    <i v-else class="pi pi-save"></i>
                    <span>{{ saving ? 'Guardando...' : 'Guardar Cambios' }}</span>
                </button>
                </template>
            </AdminPageHeader>

            <div v-if="loading" class="bg-white rounded-2xl p-12 text-center text-slate-500 shadow-sm">
                <i class="pi pi-spin pi-spinner text-3xl text-blue-600 mb-3"></i>
                <p>Cargando configuración de pagos...</p>
            </div>

            <section v-else-if="loadError" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-900" role="alert">
                <p class="font-bold"><i class="pi pi-exclamation-circle mr-2" aria-hidden="true"></i>No se pudo cargar la configuración</p>
                <p class="mt-1 text-sm">{{ loadError }}</p>
                <button type="button" class="mt-3 rounded-lg border border-red-300 px-3 py-2 text-sm font-bold" @click="loadSettings">Reintentar</button>
            </section>

            <div v-else class="space-y-6">
                <!-- 1. Card / Online Gateway Card -->
                <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-lg">
                                <i class="pi pi-credit-card"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">Tarjeta de Crédito / Débito (Pasarela Online)</h2>
                                <p class="text-xs text-slate-500">Compatible tanto con envíos a domicilio como recojo en sede.</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input v-model="form.card_enabled" type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            <span class="ml-3 text-sm font-semibold" :class="form.card_enabled ? 'text-blue-700' : 'text-slate-500'">{{ form.card_enabled ? 'Habilitado' : 'Deshabilitado' }}</span>
                        </label>
                    </div>

                    <div v-if="form.card_enabled" class="space-y-4 pt-2">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Título en Checkout</label>
                                <input v-model="form.card_title" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Descripción breve</label>
                                <input v-model="form.card_description" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Pasarela Activa</label>
                            <select v-model="form.card_gateway" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-medium">
                                <option value="mock">Simulador Visa / Mastercard (Entorno de pruebas y desarrollo)</option>
                                <option value="mercadopago">Mercado Pago (Producción / Sandbox)</option>
                            </select>
                        </div>

                        <!-- Mock notice -->
                        <div v-if="form.card_gateway === 'mock'" class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-amber-900 text-xs space-y-1">
                            <strong class="font-bold flex items-center gap-1.5"><i class="pi pi-info-circle text-amber-600"></i> Modo Simulación Visa Activo</strong>
                            <p>Permite procesar compras de prueba interactivas con tarjetas virtuales simuladas en entornos locales o de test sin credenciales bancarias reales.</p>
                        </div>

                        <!-- MercadoPago Fields -->
                        <div v-if="form.card_gateway === 'mercadopago'" class="rounded-xl bg-slate-50 border border-slate-200 p-4.5 space-y-4">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Credenciales de Mercado Pago</h3>
                            <div class="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Public Key</label>
                                    <input v-model="form.mercadopago_public_key" type="text" placeholder="TEST-xxxx o APP_USR-xxxx" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1 flex items-center justify-between">
                                        <span>Access Token</span>
                                        <span v-if="form.has_mercadopago_access_token" class="text-[10px] text-emerald-700 font-semibold bg-emerald-100 px-1.5 py-0.5 rounded">Configurado</span>
                                    </label>
                                    <input v-model="form.mercadopago_access_token" type="password" :placeholder="form.has_mercadopago_access_token ? '•••••••••••••••• (Dejar vacío para conservar)' : 'TEST-xxxx o APP_USR-xxxx'" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono">
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                                <input v-model="form.mercadopago_sandbox" type="checkbox" class="rounded h-4 w-4 text-blue-600">
                                <span>Activar Modo Sandbox (Entorno de pruebas de Mercado Pago)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 2. Bank Transfer / Digital Wallets Card -->
                <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-lg">
                                <i class="pi pi-building-columns"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">Transferencia Bancaria & Billeteras Digitales (Yape / Plin)</h2>
                                <p class="text-xs text-slate-500">Permite a los clientes pagar a tus cuentas y enviar constancia de pago.</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input v-model="form.transfer_enabled" type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                            <span class="ml-3 text-sm font-semibold" :class="form.transfer_enabled ? 'text-emerald-700' : 'text-slate-500'">{{ form.transfer_enabled ? 'Habilitado' : 'Deshabilitado' }}</span>
                        </label>
                    </div>

                    <div v-if="form.transfer_enabled" class="space-y-4 pt-2">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Título en Checkout</label>
                                <input v-model="form.transfer_title" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Descripción breve</label>
                                <input v-model="form.transfer_description" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4.5 space-y-4">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Datos Bancarios Visibles al Cliente</h3>
                            <div class="grid sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Banco</label>
                                    <input v-model="form.transfer_bank_name" type="text" placeholder="Ej: BCP / Interbank / BBVA" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Titular de Cuenta</label>
                                    <input v-model="form.transfer_account_holder" type="text" placeholder="Nombre de la empresa o titular" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Número de Cuenta</label>
                                    <input v-model="form.transfer_account_number" type="text" placeholder="Ej: 193-XXXXXXXX-0-XX" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Código Interbancario (CCI)</label>
                                    <input v-model="form.transfer_cci" type="text" placeholder="Ej: 002193XXXXXXXXXXXXXX" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Número Yape</label>
                                    <input v-model="form.transfer_yape_phone" type="text" placeholder="Ej: 999888777" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Número Plin</label>
                                    <input v-model="form.transfer_plin_phone" type="text" placeholder="Ej: 999888777" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Instrucciones para el cliente</label>
                                <textarea v-model="form.transfer_instructions" rows="2" placeholder="Instrucciones para que el cliente envíe su comprobante..." class="w-full rounded-lg border border-slate-300 p-2.5 text-xs"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Cash on Delivery (Delivery Only) -->
                <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold text-lg">
                                <i class="pi pi-truck"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">Pago Contra Entrega (Envíos a Domicilio)</h2>
                                <p class="text-xs text-slate-500">Exclusivo para la modalidad de entrega a domicilio.</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input v-model="form.cash_on_delivery_enabled" type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                            <span class="ml-3 text-sm font-semibold" :class="form.cash_on_delivery_enabled ? 'text-purple-700' : 'text-slate-500'">{{ form.cash_on_delivery_enabled ? 'Habilitado' : 'Deshabilitado' }}</span>
                        </label>
                    </div>

                    <div v-if="form.cash_on_delivery_enabled" class="space-y-4 pt-2">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Título en Checkout</label>
                                <input v-model="form.cash_on_delivery_title" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Descripción breve</label>
                                <input v-model="form.cash_on_delivery_description" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Instrucciones o Condiciones</label>
                            <textarea v-model="form.cash_on_delivery_instructions" rows="2" placeholder="Ej: Ten el monto exacto en efectivo al recibir..." class="w-full rounded-lg border border-slate-300 p-2.5 text-xs"></textarea>
                        </div>
                    </div>
                </div>

                <!-- 4. Cash at Pickup (Pickup Only) -->
                <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-lg">
                                <i class="pi pi-map-marker"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">Pago al Recoger en Sede (Recojo en Tienda)</h2>
                                <p class="text-xs text-slate-500">Exclusivo para la modalidad de recojo en sede física.</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input v-model="form.cash_at_pickup_enabled" type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600"></div>
                            <span class="ml-3 text-sm font-semibold" :class="form.cash_at_pickup_enabled ? 'text-amber-700' : 'text-slate-500'">{{ form.cash_at_pickup_enabled ? 'Habilitado' : 'Deshabilitado' }}</span>
                        </label>
                    </div>

                    <div v-if="form.cash_at_pickup_enabled" class="space-y-4 pt-2">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Título en Checkout</label>
                                <input v-model="form.cash_at_pickup_title" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Descripción breve</label>
                                <input v-model="form.cash_at_pickup_description" type="text" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Instrucciones o Condiciones</label>
                            <textarea v-model="form.cash_at_pickup_instructions" rows="2" placeholder="Ej: Puedes pagar en caja en efectivo, POS o transferencia al retirar..." class="w-full rounded-lg border border-slate-300 p-2.5 text-xs"></textarea>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </AdminLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';

const toast = useToast();
const loading = ref(true);
const saving = ref(false);
const loadError = ref('');

const form = ref({
    card_enabled: true,
    card_gateway: 'mock',
    card_title: 'Tarjeta de crédito o débito',
    card_description: 'Pago seguro en línea mediante pasarela',
    mercadopago_public_key: '',
    mercadopago_access_token: '',
    mercadopago_sandbox: true,
    has_mercadopago_access_token: false,

    transfer_enabled: true,
    transfer_title: 'Transferencia bancaria / Yape / Plin',
    transfer_description: 'Paga mediante transferencia bancaria o billetera digital',
    transfer_instructions: '',
    transfer_bank_name: '',
    transfer_account_number: '',
    transfer_cci: '',
    transfer_account_holder: '',
    transfer_yape_phone: '',
    transfer_plin_phone: '',

    cash_on_delivery_enabled: true,
    cash_on_delivery_title: 'Pago contra entrega',
    cash_on_delivery_description: 'Paga en efectivo al recibir tu pedido en tu domicilio',
    cash_on_delivery_instructions: '',

    cash_at_pickup_enabled: true,
    cash_at_pickup_title: 'Pago al recoger en sede',
    cash_at_pickup_description: 'Paga en efectivo o POS al retirar tus productos en la sede seleccionada',
    cash_at_pickup_instructions: '',
});

async function loadSettings() {
    loading.value = true;
    loadError.value = '';
    try {
        const response = await api.get('/admin/payment-settings');
        Object.assign(form.value, response.data);
        form.value.mercadopago_access_token = '';
    } catch (e) {
        loadError.value = e.response?.data?.message || 'No se pudo cargar la configuración de pagos.';
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'No se pudo cargar la configuración de pagos.',
            life: 4000,
        });
    } finally {
        loading.value = false;
    }
}

async function saveSettings() {
    saving.value = true;
    try {
        const response = await api.put('/admin/payment-settings', form.value);
        Object.assign(form.value, response.data.settings);
        form.value.mercadopago_access_token = '';
        toast.add({
            severity: 'success',
            summary: 'Guardado',
            detail: response.data.message || 'Configuración guardada exitosamente.',
            life: 3500,
        });
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'No se pudo guardar',
            detail: e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || e.message,
            life: 5000,
        });
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    loadSettings();
});
</script>
