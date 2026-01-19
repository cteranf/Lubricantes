<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8">
            <h1 class="text-3xl font-bold mb-8">Tu Carrito</h1>
            
            <div v-if="cartStore.items.length > 0" class="flex flex-col md:flex-row gap-8">
                <!-- Cart Items -->
                <div class="w-full md:w-2/3 bg-white rounded-lg shadow overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-100 border-b">
                            <tr>
                                <th class="text-left py-4 px-6">Producto</th>
                                <th class="text-center py-4 px-6">Precio</th>
                                <th class="text-center py-4 px-6">Cantidad</th>
                                <th class="text-center py-4 px-6">Total</th>
                                <th class="py-4 px-6"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in cartStore.items" :key="item.product_id" class="border-b">
                                <td class="py-4 px-6 flex items-center">
                                    <img v-if="item.image" :src="item.image" class="h-16 w-16 object-cover rounded mr-4">
                                    <span class="font-medium">{{ item.name }}</span>
                                </td>
                                <td class="py-4 px-6 text-center">S/ {{ item.price }}</td>
                                <td class="py-4 px-6 text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <button 
                                            @click="decrementQuantity(item)" 
                                            :disabled="item.quantity <= 1"
                                            class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 font-bold text-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            -
                                        </button>
                                        <input 
                                            type="number" 
                                            :value="item.quantity" 
                                            @input="updateQuantityInput(item, $event)"
                                            @blur="validateQuantity(item)"
                                            min="1"
                                            :max="item.product?.stock || 999"
                                            class="w-16 text-center border rounded py-1 focus:ring-2 focus:ring-blue-500 outline-none"
                                        />
                                        <button 
                                            @click="incrementQuantity(item)" 
                                            :disabled="item.quantity >= (item.product?.stock || 999)"
                                            class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 font-bold text-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            +
                                        </button>
                                    </div>
                                    <div v-if="item.product?.stock" class="text-xs text-gray-500 mt-1">
                                        Stock: {{ item.product?.stock }}
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-center font-bold">S/ {{ (item.price * item.quantity).toFixed(2) }}</td>
                                <td class="py-4 px-6 text-center">
                                    <button @click="cartStore.removeItem(item.product_id)" class="text-red-500 hover:text-red-700">
                                        <i class="pi pi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Summary -->
                <div class="w-full md:w-1/3">
                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="text-xl font-bold mb-4">Resumen del Pedido</h3>
                        <div class="flex justify-between mb-2 text-gray-600">
                            <span>Subtotal</span>
                            <span>S/ {{ cartStore.total.toFixed(2) }}</span>
                        </div>
                        <div class="flex justify-between mb-4 text-gray-600">
                            <span>Envío</span>
                            <span>Calculado en checkout</span>
                        </div>
                        <div class="border-t pt-4 flex justify-between font-bold text-xl mb-6">
                            <span>Total</span>
                            <span>S/ {{ cartStore.total.toFixed(2) }}</span>
                        </div>
                        <router-link to="/checkout" class="block w-full bg-blue-600 text-white text-center py-3 rounded-full font-bold hover:bg-blue-700 shadow-lg">
                            Proceder al Pago
                        </router-link>
                         <router-link to="/catalog" class="block w-full text-center mt-4 text-blue-600 hover:underline text-sm">
                            Seguir comprando
                        </router-link>
                    </div>
                </div>
            </div>
            <div v-else class="text-center py-20 bg-white rounded-lg shadow">
                <i class="pi pi-shopping-cart text-6xl text-gray-300 mb-4"></i>
                <p class="text-xl text-gray-500 mb-6">Tu carrito está vacío.</p>
                <router-link to="/catalog" class="bg-blue-600 text-white px-8 py-3 rounded-full hover:bg-blue-700 font-bold">
                    Ir al Catálogo
                </router-link>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useCartStore } from '@/stores/cart';
import { useToast } from 'primevue/usetoast';

const cartStore = useCartStore();
const toast = useToast();

const incrementQuantity = (item) => {
    if (!item.product?.stock || item.quantity >= item.product?.stock) {
        toast.add({
            severity: 'warn',
            summary: 'Stock Limitado',
            detail: `Solo hay ${item.product?.stock} unidades disponibles de "${item.name}".`,
            life: 3000
        });
        return;
    }
    cartStore.updateQuantity(item.product_id, item.quantity + 1);
};

const decrementQuantity = (item) => {
    if (item.quantity > 1) {
        cartStore.updateQuantity(item.product_id, item.quantity - 1);
    }
};

const updateQuantityInput = (item, event) => {
    const newQty = parseInt(event.target.value) || 1;
    
    // Don't update yet, just validate on blur
    if (newQty < 1) {
        event.target.value = 1;
    } else if (item.product?.stock && newQty > item.product?.stock) {
        event.target.value = item.product?.stock;
    }
};

const validateQuantity = (item) => {
    const input = event.target;
    let newQty = parseInt(input.value) || 1;
    
    if (newQty < 1) {
        newQty = 1;
        toast.add({
            severity: 'warn',
            summary: 'Cantidad Inválida',
            detail: 'La cantidad mínima es 1.',
            life: 2000
        });
    } else if (item.product?.stock && newQty > item.product?.stock) {
        newQty = item.product?.stock;
        toast.add({
            severity: 'warn',
            summary: 'Stock Limitado',
            detail: `Solo hay ${item.product?.stock} unidades disponibles de "${item.name}".`,
            life: 3000
        });
    }
    
    input.value = newQty;
    cartStore.updateQuantity(item.product_id, newQty);
};
</script>
