<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-16 text-center">
            <div class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-lg border border-green-100">
                <div class="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="pi pi-check text-4xl font-bold"></i>
                </div>
                
                <h1 class="text-3xl font-bold text-gray-800 mb-2">¡Pedido Confirmado!</h1>
                <p class="text-gray-600 mb-8">Gracias por tu compra. Tu pedido está siendo procesado.</p>
                
                <div class="bg-gray-50 rounded-lg p-4 mb-8 text-left">
                    <p class="text-sm text-gray-500 mb-1">Número de Pedido:</p>
                    <p class="font-bold text-lg text-gray-800">#{{ $route.params.id }}</p>
                </div>
                
                <div class="space-y-4">
                    <router-link 
                        :to="`/orders/${$route.params.id}/tracking`" 
                        class="block w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition flex items-center justify-center gap-2"
                    >
                        <i class="pi pi-map-marker"></i>
                        Seguir mi Pedido
                    </router-link>
                    
                    <router-link 
                        to="/catalog" 
                        class="block w-full bg-gray-100 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-200 transition"
                    >
                        Continuar Comprando
                    </router-link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
</script>
