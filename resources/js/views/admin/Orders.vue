<template>
    <AdminLayout>
        <div class="container mx-auto px-6 py-8">
            <h3 class="text-gray-700 text-3xl font-medium mb-6">Gestión de Pedidos</h3>

            <div class="bg-white shadow-md rounded-lg overflow-hidden">
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
                                    <option value="pending">Pendiente</option>
                                    <option value="confirmed">Confirmado</option>
                                    <option value="shipped">Enviado</option>
                                    <option value="delivered">Entregado</option>
                                    <option value="canceled">Cancelado</option>
                                    <option value="rejected">Rechazado</option>
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
                                <select v-model="selectedOrder.status" @change="updateStatus(selectedOrder, selectedOrder.status)" class="w-full border rounded p-2 mt-1">
                                    <option value="pending">Pendiente</option>
                                    <option value="confirmed">Confirmado</option>
                                    <option value="shipped">Enviado</option>
                                    <option value="delivered">Entregado</option>
                                    <option value="canceled">Cancelado</option>
                                    <option value="rejected">Rechazado</option>
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
                                            <template v-if="selectedOrder.delivery_type === 'delivery'">
                                                <option value="pending">Pedido Recibido</option>
                                                <option value="confirmed">Pago Confirmado</option>
                                                <option value="processing">Preparando Pedido</option>
                                                <option value="shipped">En Camino</option>
                                                <option value="delivered">Entregado</option>
                                            </template>
                                            <template v-else>
                                                <option value="pending">Pedido Recibido</option>
                                                <option value="confirmed">Pago Confirmado</option>
                                                <option value="ready_for_pickup">Listo para Recoger</option>
                                                <option value="picked_up">Recogido</option>
                                            </template>
                                            <option value="canceled">Cancelado</option>
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

const trackingForm = ref({
    tracking_status: '',
    tracking_notes: '',
    estimated_delivery_date: ''
});

const fetchOrders = async () => {
    try {
        const response = await api.get('/admin/orders');
        orders.value = response.data.data;
    } catch(e) {
        console.error(e);
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
                    selectedOrder.value.status = newStatus;
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
        
        // Update local state
        const index = orders.value.findIndex(o => o.id === selectedOrder.value.id);
        if(index !== -1) {
            orders.value[index].tracking_status = trackingForm.value.tracking_status;
            orders.value[index].tracking_notes = trackingForm.value.tracking_notes;
            orders.value[index].estimated_delivery_date = trackingForm.value.estimated_delivery_date;
        }
        
        selectedOrder.value.tracking_status = trackingForm.value.tracking_status;
        selectedOrder.value.tracking_notes = trackingForm.value.tracking_notes;
        selectedOrder.value.estimated_delivery_date = trackingForm.value.estimated_delivery_date;

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
