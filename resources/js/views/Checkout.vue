<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8">
            <h1 class="text-3xl font-bold mb-8 text-center">Finalizar Pedido</h1>
            
            <div class="bg-white shadow-lg rounded-lg max-w-4xl mx-auto overflow-hidden flex flex-col md:flex-row">
                 <!-- Form -->
                 <div class="w-full md:w-2/3 p-8 border-r">
                     <form @submit.prevent="submitOrder">
                        <h3 class="font-bold text-lg mb-6">Tipo de Entrega</h3>
                        <div class="grid grid-cols-2 gap-4 mb-8">
                            <button 
                                type="button"
                                @click="form.delivery_type = 'delivery'"
                                :class="form.delivery_type === 'delivery' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'"
                                class="py-3 px-4 rounded-lg font-bold transition flex items-center justify-center gap-2"
                            >
                                <i class="pi pi-truck"></i>
                                Envío a domicilio
                            </button>
                            <button 
                                type="button"
                                @click="form.delivery_type = 'pickup'"
                                :class="form.delivery_type === 'pickup' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'"
                                class="py-3 px-4 rounded-lg font-bold transition flex items-center justify-center gap-2"
                            >
                                <i class="pi pi-shopping-bag"></i>
                                Recojo en tienda
                            </button>
                        </div>

                        <h3 class="font-bold text-lg mb-6">
                            {{ form.delivery_type === 'delivery' ? 'Información de Envío' : 'Información de Contacto' }}
                        </h3>
                        <div class="grid grid-cols-1 gap-6 mb-6">
                            <div v-if="form.delivery_type === 'delivery'">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Dirección Completa</label>
                                <input v-model="form.address" type="text" :required="form.delivery_type === 'delivery'" class="w-full border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Ciudad</label>
                                    <input v-model="form.city" type="text" required class="w-full border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Teléfono de Contacto</label>
                                    <input v-model="form.phone" type="text" required class="w-full border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                            </div>
                        </div>

                        <h3 class="font-bold text-lg mb-6">Método de Pago</h3>
                        <div class="space-y-4 mb-8">
                            <label class="flex items-center p-4 border rounded cursor-pointer hover:bg-gray-50" :class="form.payment_method === 'card' ? 'bg-blue-50 border-blue-500' : 'bg-white'">
                                <input type="radio" v-model="form.payment_method" value="card" class="mr-4 h-5 w-5 text-blue-600">
                                <div class="flex-1">
                                    <div class="font-bold">Tarjeta de Crédito/Débito</div>
                                    <div class="text-sm text-gray-500">Visa, Mastercard, American Express</div>
                                </div>
                                <i class="pi pi-credit-card text-xl text-gray-400"></i>
                            </label>
                            <label class="flex items-center p-4 border rounded cursor-pointer hover:bg-gray-50" :class="form.payment_method === 'transferencia' ? 'bg-blue-50 border-blue-500' : 'bg-white'">
                                <input type="radio" v-model="form.payment_method" value="transferencia" class="mr-4 h-5 w-5 text-blue-600">
                                <div class="flex-1">
                                    <div class="font-bold">Transferencia Bancaria</div>
                                    <div class="text-sm text-gray-500">BCP / Interbank / BBVA</div>
                                </div>
                                <i class="pi pi-briefcase text-xl text-gray-400"></i>
                            </label>
                             <label class="flex items-center p-4 border rounded cursor-pointer hover:bg-gray-50" :class="form.payment_method === 'contra_entrega' ? 'bg-blue-50 border-blue-500' : 'bg-white'">
                                <input type="radio" v-model="form.payment_method" value="contra_entrega" class="mr-4 h-5 w-5 text-blue-600">
                                <div class="flex-1">
                                    <div class="font-bold">Pago contra Entrega</div>
                                    <div class="text-sm text-gray-500">Efectivo o Yape/Plin al recibir</div>
                                </div>
                                <i class="pi pi-wallet text-xl text-gray-400"></i>
                            </label>
                        </div>

                        <button type="submit" :disabled="processing" class="w-full bg-green-600 text-white font-bold py-4 rounded-lg hover:bg-green-700 transition disabled:opacity-50">
                            {{ processing ? 'Procesando...' : 'Confirmar Pedido' }}
                        </button>
                     </form>
                 </div>

                 <!-- Order Summary Sidebar -->
                 <div class="w-full md:w-1/3 bg-gray-50 p-8">
                     <h3 class="font-bold text-lg mb-6">Tu Pedido</h3>
                     <div class="space-y-4 mb-6 max-h-64 overflow-y-auto">
                         <div v-for="item in cartStore.items" :key="item.product_id" class="flex justify-between items-center text-sm">
                             <div class="flex items-center overflow-hidden">
                                 <span class="bg-gray-200 px-2 py-1 rounded text-xs mr-2 font-bold">{{ item.quantity }}x</span>
                                 <span class="truncate max-w-[10rem]">{{ item.name }}</span>
                             </div>
                             <span class="font-medium">S/ {{ (item.price * item.quantity).toFixed(2) }}</span>
                         </div>
                     </div>
                     <div class="border-t pt-6 space-y-2">
                         <div class="flex justify-between text-gray-600">
                             <span>Subtotal</span>
                             <span>S/ {{ cartStore.total.toFixed(2) }}</span>
                         </div>
                          <div class="flex justify-between text-gray-600">
                             <span>Envío</span>
                             <span class="text-green-600 font-bold">Gratis</span>
                         </div>
                         <div class="flex justify-between text-2xl font-bold mt-4 pt-4 border-t border-gray-200">
                             <span>Total</span>
                             <span>S/ {{ cartStore.total.toFixed(2) }}</span>
                         </div>
                     </div>
                 </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useCartStore } from '@/stores/cart';
import { useAuthStore } from '@/stores/auth';
import { ref } from 'vue';
import api from '@/api';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';

const cartStore = useCartStore();
const authStore = useAuthStore();
const router = useRouter();
const toast = useToast();

const form = ref({
    address: '',
    city: 'Lima',
    phone: '',
    payment_method: 'card',
    delivery_type: 'delivery' // Default to shipment
});

const processing = ref(false);

const submitOrder = async () => {
    if(!authStore.isAuthenticated) {
        router.push('/login?redirect=/checkout');
        return;
    }

    if (cartStore.items.length === 0) return;

    processing.value = true;
    try {
        const orderData = {
            items: cartStore.items,
            shipping_info: {
                address: form.value.delivery_type === 'delivery' ? form.value.address : 'RECOJO EN TIENDA',
                city: form.value.city,
                phone: form.value.phone
            },
            payment_method: form.value.payment_method,
            delivery_type: form.value.delivery_type
        };

        // Create the order first
        const orderResponse = await api.post('/orders', orderData);
        const orderId = orderResponse.data.id;

        // If payment method is card, initiate payment gateway
        if (form.value.payment_method === 'card') {
            const paymentResponse = await api.post('/payment/create', { order_id: orderId });
            
            // Clear cart BEFORE redirecting to payment
            cartStore.clear();
            
            // Redirect to payment gateway (MercadoPago or Mock)
            window.location.href = paymentResponse.data.checkout_url;
        } else {
            // For other payment methods, just clear cart and show success
            cartStore.clear();
            router.push({ name: 'OrderSuccess', params: { id: orderId } });
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || e.message, life: 3000 });
    } finally {
        processing.value = false;
    }
};
</script>
