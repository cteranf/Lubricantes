<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8 max-w-4xl">
            <div v-if="loading" class="text-center py-20">
                <i class="pi pi-spin pi-spinner text-4xl text-blue-600"></i>
                <p class="mt-4 text-gray-500">Cargando información de seguimiento...</p>
            </div>

            <div v-else-if="error" class="text-center py-20 bg-white rounded-lg shadow">
                <i class="pi pi-exclamation-triangle text-6xl text-red-500 mb-4"></i>
                <p class="text-xl font-bold">{{ error }}</p>
                <router-link to="/orders" class="mt-6 inline-block text-blue-600 hover:underline">
                    Golver a mis pedidos
                </router-link>
            </div>

            <div v-else-if="order" class="space-y-8">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h1 class="text-3xl font-bold">Seguimiento de Pedido</h1>
                        <p class="text-gray-500">Pedido {{ order.order_number }} • Realizado el {{ order.created_at }}</p>
                    </div>
                    <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded-full font-bold">
                        {{ order.tracking_status_label }}
                    </div>
                </div>

                <!-- Timeline Section -->
                <div class="bg-white rounded-lg shadow-lg p-8">
                    <h3 class="text-xl font-bold mb-8 border-b pb-4">Timeline del Pedido</h3>
                    
                    <div class="relative">
                        <!-- Horizontal bar (desktop) / Vertical bar (mobile) -->
                        <div class="hidden md:block absolute top-5 left-8 right-8 h-1 bg-gray-200"></div>
                        
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center relative z-10 gap-8 md:gap-0">
                            <div 
                                v-for="(step, index) in timeline" 
                                :key="index"
                                class="flex md:flex-col items-center gap-4 md:gap-2 flex-1 text-center"
                            >
                                <div 
                                    :class="[
                                        'w-10 h-10 rounded-full flex items-center justify-center transition-colors duration-500',
                                        step.completed ? 'bg-green-600 text-white' : 'bg-gray-200 text-gray-500',
                                        step.active ? 'ring-4 ring-blue-200 bg-blue-600 text-white' : ''
                                    ]"
                                >
                                    <i :class="step.icon"></i>
                                </div>
                                <div class="text-left md:text-center">
                                    <p :class="['font-bold text-sm', step.completed || step.active ? 'text-gray-900' : 'text-gray-400']">
                                        {{ step.label }}
                                    </p>
                                    <p v-if="step.date" class="text-xs text-gray-500">{{ step.date }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Estimated Delivery -->
                    <div v-if="order.estimated_delivery_date" class="mt-12 p-4 bg-blue-50 border border-blue-100 rounded-lg flex items-center gap-4">
                        <i class="pi pi-calendar text-2xl text-blue-600"></i>
                        <div>
                            <p class="text-sm text-blue-800 font-medium">Fecha estimada de {{ order.delivery_type === 'delivery' ? 'entrega' : 'recojo' }}</p>
                            <p class="text-lg font-bold text-blue-900">{{ formatDate(order.estimated_delivery_date) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Details Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Shipping Info -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                            <i :class="order.delivery_type === 'delivery' ? 'pi pi-truck' : 'pi pi-shopping-bag'" class="text-blue-600"></i>
                            Información de {{ order.delivery_type === 'delivery' ? 'Envío' : 'Recojo' }}
                        </h3>
                        <div class="space-y-2 text-gray-700">
                            <p v-if="order.delivery_type === 'delivery'"><span class="font-medium">Dirección:</span> {{ order.shipping_info.address }}</p>
                            <p v-else class="font-bold text-blue-700">Recojo en Tienda Principal</p>
                            <p><span class="font-medium">Ciudad:</span> {{ order.shipping_info.city }}</p>
                            <p><span class="font-medium">Teléfono:</span> {{ order.shipping_info.phone }}</p>
                            <div v-if="order.tracking_notes" class="mt-4 p-3 bg-gray-50 rounded italic text-sm border-l-4 border-blue-400">
                                "{{ order.tracking_notes }}"
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                            <i class="pi pi-list text-blue-600"></i>
                            Resumen de Productos
                        </h3>
                        <div class="space-y-4">
                            <div v-for="(item, index) in order.items" :key="index" class="flex gap-4 items-center">
                                <img :src="item.image" class="w-12 h-12 rounded object-cover border" alt="">
                                <div class="flex-1">
                                    <p class="font-medium text-sm">{{ item.name }}</p>
                                    <p class="text-xs text-gray-500">{{ item.quantity }} unidades x S/ {{ item.price }}</p>
                                </div>
                                <p class="font-bold">S/ {{ (item.quantity * item.price).toFixed(2) }}</p>
                            </div>
                            <div class="border-t pt-4 flex justify-between font-bold text-xl">
                                <span>Total Pagado</span>
                                <span class="text-blue-600">S/ {{ order.total }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/api';

const route = useRoute();
const order = ref(null);
const timeline = ref([]);
const loading = ref(true);
const error = ref(null);

const fetchTracking = async () => {
    try {
        const response = await api.get(`/orders/${route.params.id}/tracking`);
        order.value = response.data.order;
        timeline.value = response.data.timeline;
        
        // Add current label for badge
        const activeStep = timeline.value.find(s => s.active);
        order.value.tracking_status_label = activeStep ? activeStep.label : 'Pendiente';
        
    } catch (e) {
        error.value = e.response?.status === 404
            ? 'El pedido no existe o no pertenece a tu cuenta.'
            : 'No se pudo cargar la información de seguimiento.';
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('es-ES', options);
};

onMounted(fetchTracking);
</script>

<style scoped>
/* Mobile adjustments for timeline vertical line */
@media (max-width: 767px) {
    .flex-col::before {
        content: '';
        position: absolute;
        left: 20px;
        top: 20px;
        bottom: 20px;
        width: 2px;
        background-color: #e5e7eb;
        z-index: -1;
    }
}
</style>
