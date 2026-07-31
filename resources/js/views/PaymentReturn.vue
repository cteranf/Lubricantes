<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-16 text-center">
            <div class="max-w-lg mx-auto bg-white p-8 rounded-lg shadow-lg">
                <div v-if="loading">
                    <i class="pi pi-spin pi-spinner text-5xl text-blue-600"></i>
                    <h1 class="text-2xl font-bold mt-6">Verificando el pago</h1>
                    <p class="text-gray-600 mt-2">Espera un momento mientras confirmamos el estado con la pasarela.</p>
                </div>

                <div v-else-if="status === 'pending'">
                    <i class="pi pi-clock text-5xl text-yellow-500"></i>
                    <h1 class="text-2xl font-bold mt-6">Pago pendiente</h1>
                    <p class="text-gray-600 mt-2">El pago todavía está siendo procesado. Puedes revisar el pedido nuevamente desde tu historial.</p>
                </div>

                <div v-else-if="status === 'rejected' || status === 'canceled'">
                    <i class="pi pi-times-circle text-5xl text-red-500"></i>
                    <h1 class="text-2xl font-bold mt-6">Pago no completado</h1>
                    <p class="text-gray-600 mt-2">
                        {{ status === 'canceled' ? 'El proceso fue cancelado o abandonado.' : 'La pasarela rechazó el pago.' }}
                    </p>
                </div>

                <div v-else>
                    <i class="pi pi-exclamation-triangle text-5xl text-red-500"></i>
                    <h1 class="text-2xl font-bold mt-6">No pudimos verificar el pago</h1>
                    <p class="text-gray-600 mt-2">{{ error }}</p>
                </div>

                <div v-if="!loading" class="mt-8 space-y-3">
                    <router-link v-if="orderId" :to="`/orders/${orderId}/tracking`" class="block w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700">
                        Ver seguimiento
                    </router-link>
                    <router-link to="/orders" class="block w-full bg-gray-100 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-200">
                        Ir a mis pedidos
                    </router-link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import api from '@/api';
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const status = ref(null);
const orderId = ref(null);
const error = ref('La respuesta recibida no permite identificar un pedido válido.');

onMounted(async () => {
    try {
        const response = await api.get('/payment/return', {
            params: {
                payment_id: route.query.payment_id,
                collection_id: route.query.collection_id,
                preference_id: route.query.preference_id,
                external_reference: route.query.external_reference,
                result: route.query.result,
            },
        });

        orderId.value = response.data.order_id;
        status.value = response.data.display_status;

        if (status.value === 'approved') {
            await router.replace({ name: 'OrderSuccess', params: { id: orderId.value } });
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'No se pudo verificar el retorno del pago.';
        status.value = 'error';
    } finally {
        loading.value = false;
    }
});
</script>
