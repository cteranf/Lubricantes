<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-16 text-center">
            <div v-if="loading" class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-lg">
                <i class="pi pi-spin pi-spinner text-4xl text-blue-600"></i>
                <p class="mt-4 text-gray-600">Cargando el pedido...</p>
            </div>

            <div v-else-if="error" class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-lg border border-red-100">
                <i class="pi pi-exclamation-triangle text-5xl text-red-500"></i>
                <h1 class="text-2xl font-bold mt-4">No se pudo mostrar el pedido</h1>
                <p class="text-gray-600 my-6">{{ error }}</p>
                <router-link to="/orders" class="text-blue-600 font-bold hover:underline">Ir a mis pedidos</router-link>
            </div>

            <div v-else class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-lg border border-green-100">
                <div class="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="pi pi-check text-4xl font-bold"></i>
                </div>

                <h1 class="text-3xl font-bold text-gray-800 mb-2">¡Pedido registrado!</h1>
                <p class="text-gray-600 mb-8">Tu pedido fue registrado correctamente. Puedes consultar su estado desde el seguimiento.</p>

                <div class="bg-gray-50 rounded-lg p-4 mb-8 text-left">
                    <p class="text-sm text-gray-500 mb-1">Número de Pedido:</p>
                    <p class="font-bold text-lg text-gray-800">#{{ order.id }}</p>
                    <p class="text-sm text-gray-500 mt-3">Estado de pago: <span class="font-semibold">{{ order.payment_status }}</span></p>
                </div>

                <div class="space-y-4">
                    <router-link :to="`/orders/${order.id}/tracking`" class="block w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition flex items-center justify-center gap-2">
                        <i class="pi pi-map-marker"></i>
                        Seguir mi Pedido
                    </router-link>
                    <router-link to="/catalog" class="block w-full bg-gray-100 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-200 transition">
                        Continuar Comprando
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
import { useRoute } from 'vue-router';

const route = useRoute();
const order = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    if (!route.params.id) {
        error.value = 'Falta el identificador del pedido.';
        loading.value = false;
        return;
    }

    try {
        const response = await api.get(`/orders/${route.params.id}`);
        order.value = response.data;
    } catch (e) {
        error.value = e.response?.status === 404
            ? 'El pedido no existe o no pertenece a tu cuenta.'
            : 'Ocurrió un error al cargar el pedido.';
    } finally {
        loading.value = false;
    }
});
</script>
