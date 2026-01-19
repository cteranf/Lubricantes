<template>
    <AppLayout>
         <div class="container mx-auto px-4 py-8">
            <h1 class="text-3xl font-bold mb-8">Mis Pedidos</h1>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Sidebar (Should be component in real app) -->
                <div class="bg-white rounded-lg shadow h-fit">
                     <ul class="text-sm">
                        <li><router-link to="/profile" class="block px-6 py-4 border-l-4 border-transparent hover:bg-gray-50 hover:text-blue-600">Información Personal</router-link></li>
                        <li><router-link to="/orders" class="block px-6 py-4 border-l-4 border-blue-600 bg-blue-50 text-blue-700 font-bold">Mis Pedidos</router-link></li>
                        <li><button @click="authStore.logout(); router.push('/login')" class="w-full text-left px-6 py-4 border-l-4 border-transparent hover:bg-red-50 hover:text-red-600 text-red-500">Cerrar Sesión</button></li>
                    </ul>
                </div>

                <!-- Orders List -->
                <div class="col-span-1 md:col-span-2 space-y-6">
                    <div v-if="loading" class="text-center py-10">Cargando pedidos...</div>
                    <div v-else-if="orders.length === 0" class="bg-white rounded-lg shadow p-8 text-center">
                        <i class="pi pi-inbox text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">Aún no has realizado ningún pedido.</p>
                        <router-link to="/catalog" class="mt-4 inline-block text-blue-600 hover:underline">Ir a comprar</router-link>
                    </div>
                    
                    <div v-for="order in orders" :key="order.id" class="bg-white rounded-lg shadow overflow-hidden border">
                        <div class="bg-gray-50 px-6 py-4 border-b flex justify-between items-center">
                            <div>
                                <h3 class="font-bold text-gray-700">Pedido #{{ order.id }}</h3>
                                <p class="text-sm text-gray-500">{{ formatDate(order.created_at) }}</p>
                            </div>
                            <span :class="statusClass(order.status)" class="px-3 py-1 rounded-full text-xs font-bold uppercase">{{ order.status }}</span>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4 mb-4">
                                <div v-for="item in order.items" :key="item.id" class="flex justify-between items-center">
                                    <div class="flex items-center">
                                        <span class="text-gray-500 text-sm font-bold mr-3">{{ item.quantity }}x</span>
                                        <span>{{ item.product?.name || 'Producto' }}</span>
                                    </div>
                                    <span class="font-medium text-sm">S/ {{ item.subtotal }}</span>
                                </div>
                            </div>
                            <div class="border-t pt-4 flex justify-between items-center">
                                <router-link 
                                    :to="`/orders/${order.id}/tracking`" 
                                    class="text-blue-600 hover:text-blue-800 font-bold text-sm flex items-center gap-2"
                                >
                                    <i class="pi pi-map-marker"></i>
                                    Seguir Pedido
                                </router-link>
                                <div class="text-right">
                                    <span class="block text-gray-500 text-xs">Total del Pedido</span>
                                    <span class="font-bold text-xl text-blue-600">S/ {{ order.total }}</span>
                                </div>
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
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';
import { ref, onMounted } from 'vue';
import api from '@/api';

const authStore = useAuthStore();
const router = useRouter();
const orders = ref([]);
const loading = ref(true);

const fetchOrders = async () => {
    try {
        const response = await api.get('/orders');
        orders.value = response.data;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString();
};

const statusClass = (status) => {
    const classes = {
        pending: 'bg-yellow-100 text-yellow-700',
        paid: 'bg-blue-100 text-blue-700',
        shipped: 'bg-indigo-100 text-indigo-700',
        delivered: 'bg-green-100 text-green-700',
        canceled: 'bg-red-100 text-red-700',
    };
    return classes[status] || 'bg-gray-100 text-gray-700';
};

onMounted(() => {
    if (!authStore.isAuthenticated) {
        router.push('/login');
    } else {
        fetchOrders();
    }
});
</script>
