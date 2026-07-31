<template>
    <AdminLayout>
        <div class="container mx-auto px-6 py-8">
            <h3 class="text-gray-700 text-3xl font-medium mb-6">Gestión de Pedidos</h3>

            <div v-if="loading" class="bg-white shadow-md rounded-lg p-8 text-center text-gray-500">
                <i class="pi pi-spin pi-spinner text-3xl text-blue-600"></i>
                <p class="mt-3">Cargando pedidos...</p>
            </div>

            <div v-else-if="loadError" class="bg-white shadow-md rounded-lg p-8 text-center border border-red-100">
                <p class="text-red-600">{{ loadError }}</p>
                <button @click="fetchOrders" class="mt-3 text-blue-600 font-bold hover:underline">Reintentar</button>
            </div>

            <div v-else class="bg-white shadow-md rounded-lg overflow-hidden">
                <table class="min-w-full leading-normal">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ID</th>
                             <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Cliente</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Total</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Método Pago</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Estado</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">#{{ order.id }}</td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="font-bold">{{ order.user?.name || 'Invitado' }}</p>
                                <p class="text-xs text-gray-500">{{ formatDate(order.created_at) }}</p>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm font-bold">
                                S/ {{ order.total }}
                            </td>
                             <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center uppercase text-xs">
                                {{ order.payment_method }}
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <span :class="statusClass(order.status)" class="px-2 py-1 leading-tight rounded-full text-xs font-semibold capitalize">
                                    {{ order.status }}
                                </span>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <button @click="openOrder(order)" class="text-blue-600 hover:text-blue-900 mr-2" title="Ver Detalles">
                                    <i class="pi pi-eye"></i>
                                </button>
                                <select @change="updateStatus(order, $event.target.value)" :value="order.status" class="border rounded text-xs p-1 ml-2">
                                    <option v-for="option in commercialStatusOptions(order)" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal Details -->
            <div v-if="selectedOrder" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center bg-black bg-opacity-50">
                <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Detalle Pedido #{{ selectedOrder.id }}</h3>
                        <button @click="selectedOrder = null" class="text-gray-500 hover:text-gray-700 font-bold text-xl">&times;</button>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-6 mb-6">
                        <div>
                            <h4 class="font-bold mb-2">Cliente</h4>
                            <p>{{ selectedOrder.user?.name }}</p>
                            <p>{{ selectedOrder.user?.email }}</p>
                            <p class="mt-2 text-sm text-gray-600">
                                <strong>Dirección:</strong> {{ selectedOrder.shipping_info?.address }}<br>
                                <strong>Ciudad:</strong> {{ selectedOrder.shipping_info?.city }}<br>
                                <strong>Teléfono:</strong> {{ selectedOrder.shipping_info?.phone }}
                            </p>
                        </div>
                        <div>
                            <h4 class="font-bold mb-2">Información de Pago</h4>
                            <p class="capitalize">Método: {{ selectedOrder.payment_method }}</p>
                            <p class="font-bold text-xl mt-2">Total: S/ {{ selectedOrder.total }}</p>
                             <div class="mt-4">
                                <label class="block text-sm font-bold text-gray-700">Cambiar Estado:</label>
                                <select :value="selectedOrder.status" @change="updateStatus(selectedOrder, $event.target.value)" class="w-full border rounded p-2 mt-1">
                                    <option v-for="option in commercialStatusOptions(selectedOrder)" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">
                                    * Cancelar/Rechazar restaurará el stock automáticamente.
                                </p>
                            </div>

                            <div v-if="selectedOrder.status !== 'canceled' && selectedOrder.status !== 'rejected'" class="mt-6 border-t pt-4">
                                <h4 class="font-bold mb-4 flex items-center gap-2">
                                    <i class="pi pi-map-marker text-blue-600"></i>
                                    Seguimiento (Tracking)
                                </h4>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700">Estado de Tracking:</label>
                                        <select v-model="trackingForm.tracking_status" class="w-full border rounded p-2 mt-1">
                                            <option v-for="option in trackingOptions(selectedOrder)" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-bold text-gray-700">Fecha Estimada:</label>
                                        <input type="date" v-model="trackingForm.estimated_delivery_date" class="w-full border rounded p-2 mt-1">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-bold text-gray-700">Notas de Tracking:</label>
                                        <textarea v-model="trackingForm.tracking_notes" placeholder="Ej: El paquete está en la oficina de Olva..." class="w-full border rounded p-2 mt-1 h-20"></textarea>
                                    </div>

                                    <button 
                                        @click="saveTracking" 
                                        :disabled="updatingTracking"
                                        class="w-full bg-blue-600 text-white font-bold py-2 rounded hover:bg-blue-700 transition disabled:opacity-50"
                                    >
                                        {{ updatingTracking ? 'Guardando...' : 'Actualizar Seguimiento' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h4 class="font-bold mb-2 border-b pb-2">Items</h4>
                    <table class="w-full text-sm mb-6">
                        <thead>
                            <tr class="text-left">
                                <th class="py-2">Producto</th>
                                <th class="py-2">Cant.</th>
                                <th class="py-2">Precio</th>
                                <th class="py-2">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in selectedOrder.items" :key="item.id">
                                <td class="py-2">{{ item.product?.name || 'Producto Eliminado' }}</td>
                                <td class="py-2">{{ item.quantity }}</td>
                                <td class="py-2">S/ {{ item.price }}</td>
                                <td class="py-2 font-bold">S/ {{ item.subtotal }}</td>
                            </tr>
                        </tbody>
                    </table>

                     <div class="flex justify-end space-x-3">
                        <button @click="selectedOrder = null" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ref, onMounted } from 'vue';
import api from '@/api';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';

const toast = useToast();
const confirm = useConfirm();

const orders = ref([]);
const selectedOrder = ref(null);
const updatingTracking = ref(false);
const loading = ref(true);
const loadError = ref(null);

const trackingForm = ref({
    tracking_status: '',
    tracking_notes: '',
    estimated_delivery_date: ''
});

const trackingLabels = {
    pending: 'Pedido Recibido',
    confirmed: 'Pago Confirmado',
    processing: 'Preparando Pedido',
    shipped: 'En Camino',
    delivered: 'Entregado',
    ready_for_pickup: 'Listo para Recoger',
    picked_up: 'Recogido',
    canceled: 'Cancelado'
};

const commercialLabels = {
    pending: 'Pendiente',
    confirmed: 'Confirmado',
    shipped: 'Enviado',
    delivered: 'Entregado',
    canceled: 'Cancelado',
    rejected: 'Rechazado'
};

const trackingFlow = (order) => order.delivery_type === 'delivery'
    ? ['pending', 'confirmed', 'processing', 'shipped', 'delivered']
    : ['pending', 'confirmed', 'ready_for_pickup', 'picked_up'];

const trackingOptions = (order) => {
    const terminal = ['delivered', 'picked_up', 'canceled'].includes(order.tracking_status);
    const values = [order.tracking_status];

    if (!terminal) {
        const flow = trackingFlow(order);
        const currentIndex = flow.indexOf(order.tracking_status);
        const nextStatus = flow[currentIndex + 1];
        const paymentAllowsConfirmation = nextStatus !== 'confirmed'
            || order.payment_method !== 'card'
            || order.payment_status === 'approved';

        if (nextStatus && paymentAllowsConfirmation) values.push(nextStatus);
        values.push('canceled');
    }

    return [...new Set(values)].map(value => ({ value, label: trackingLabels[value] || value }));
};

const commercialStatusOptions = (order) => {
    const terminal = ['delivered', 'canceled', 'rejected'].includes(order.status);
    const values = [order.status];

    if (!terminal) {
        const nextByTracking = {
            pending: 'confirmed',
            processing: 'shipped',
            shipped: 'delivered',
            ready_for_pickup: 'delivered'
        };
        const nextStatus = nextByTracking[order.tracking_status];
        const paymentAllowsConfirmation = nextStatus !== 'confirmed'
            || order.payment_method !== 'card'
            || order.payment_status === 'approved';

        if (nextStatus && paymentAllowsConfirmation) values.push(nextStatus);
        values.push('canceled');
        if (order.payment_status !== 'approved') values.push('rejected');
    }

    return [...new Set(values)].map(value => ({ value, label: commercialLabels[value] || value }));
};

const fetchOrders = async () => {
    loading.value = true;
    loadError.value = null;
    try {
        const response = await api.get('/admin/orders');
        orders.value = response.data.data;
    } catch(e) {
        loadError.value = 'No se pudo cargar la lista de pedidos.';
    } finally {
        loading.value = false;
    }
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-PE', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
};

const statusClass = (status) => {
    switch(status) {
        case 'pending': return 'bg-yellow-200 text-yellow-800';
        case 'confirmed': return 'bg-blue-200 text-blue-800';
        case 'shipped': return 'bg-indigo-200 text-indigo-800';
        case 'delivered': return 'bg-green-200 text-green-800';
        case 'canceled': return 'bg-red-200 text-red-800';
        case 'rejected': return 'bg-gray-200 text-gray-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};

const openOrder = (order) => {
    selectedOrder.value = order;
    // Initialize tracking form
    trackingForm.value = {
        tracking_status: order.tracking_status || 'pending',
        tracking_notes: order.tracking_notes || '',
        estimated_delivery_date: order.estimated_delivery_date ? order.estimated_delivery_date.split('T')[0] : ''
    };
};

const updateStatus = async (order, newStatus) => {
    confirm.require({
        message: `¿Estás seguro de cambiar el estado a "${newStatus}"? Esto afectará el stock si es Cancelado/Rechazado.`,
        header: 'Confirmación de Estado',
        icon: 'pi pi-exclamation-triangle',
        accept: async () => {
             try {
                const response = await api.put(`/admin/orders/${order.id}`, { status: newStatus });
                // Update local list
                const index = orders.value.findIndex(o => o.id === order.id);
                if(index !== -1) orders.value[index] = response.data;
                
                if(selectedOrder.value && selectedOrder.value.id === order.id) {
                    selectedOrder.value = response.data;
                }
                toast.add({ severity: 'success', summary: 'Éxito', detail: 'Estado actualizado correctamente.', life: 3000 });
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || e.message, life: 3000 });
                fetchOrders(); // Revert on failure
            }
        },
        reject: () => {
             fetchOrders(); // Revert UI selection
        }
    });
};

const saveTracking = async () => {
    if (!selectedOrder.value) return;
    
    updatingTracking.value = true;
    try {
        const response = await api.put(`/admin/orders/${selectedOrder.value.id}/tracking`, trackingForm.value);
        const updatedOrder = response.data.order;
        
        // Update local state
        const index = orders.value.findIndex(o => o.id === selectedOrder.value.id);
        if(index !== -1) {
            orders.value[index] = updatedOrder;
        }

        selectedOrder.value = updatedOrder;

        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Seguimiento actualizado.', life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || e.message, life: 3000 });
    } finally {
        updatingTracking.value = false;
    }
};

onMounted(() => {
    fetchOrders();
});
</script>
