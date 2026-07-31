<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8" v-if="product">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                <!-- Image Gallery -->
                <div class="bg-white rounded-lg p-4 shadow flex flex-col items-center">
                    <img :src="product.image_path || mainImage" alt="" class="w-full max-h-96 object-contain mb-4">
                    <!-- Thumbnails if multiple images -->
                </div>

                <!-- Info -->
                <div>
                    <div class="text-sm text-blue-600 font-bold mb-2">{{ product.category?.name }} / {{ product.brand?.name }}</div>
                    <h1 class="text-3xl font-bold mb-4">{{ product.name }}</h1>
                    
                    <div class="text-2xl font-bold text-gray-900 mb-6">
                        <span v-if="product.sale_price" class="text-lg text-gray-400 line-through mr-3">S/ {{ product.price }}</span>
                         S/ {{ product.sale_price || product.price }}
                    </div>

                    <div class="prose max-w-none text-gray-600 mb-8" v-html="product.description || 'Sin descripción'"></div>
                    
                    <!-- Tech Specs -->
                    <div class="bg-gray-50 p-4 rounded mb-8" v-if="product.specifications">
                         <h3 class="font-bold mb-2">Especificaciones Técnicas</h3>
                         <p class="whitespace-pre-line text-sm">{{ product.specifications }}</p>
                    </div>

                    <!-- Add to Cart -->
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center border rounded">
                            <button @click="qty > 1 && qty--" class="px-3 py-2 hover:bg-gray-100">-</button>
                            <span class="px-3">{{ qty }}</span>
                            <button @click="qty++" class="px-3 py-2 hover:bg-gray-100">+</button>
                        </div>
                        <button @click="addToCart" class="bg-blue-600 text-white px-8 py-3 rounded-full hover:bg-blue-700 font-bold shadow-lg flex-1">
                            Añadir al Carrito
                        </button>
                    </div>

                    <!-- Stock Warning -->
                    <div v-if="product.stock < 5" class="mt-4 text-red-500 text-sm font-semibold">
                        ¡Últimas {{ product.stock }} unidades!
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="container mx-auto px-4 py-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                <!-- Skeleton Image -->
                <div class="bg-white rounded-lg p-4 shadow flex flex-col items-center">
                    <Skeleton width="100%" height="400px" class="mb-4"></Skeleton>
                </div>
                <!-- Skeleton Details -->
                <div>
                     <Skeleton width="30%" height="1rem" class="mb-4"></Skeleton>
                     <Skeleton width="80%" height="3rem" class="mb-6"></Skeleton>
                     <Skeleton width="20%" height="2rem" class="mb-8"></Skeleton>
                     <Skeleton width="100%" height="150px" class="mb-8"></Skeleton>
                     <div class="flex gap-4">
                         <Skeleton width="100px" height="3rem"></Skeleton>
                         <Skeleton width="200px" height="3rem" borderRadius="2rem"></Skeleton>
                     </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import Skeleton from 'primevue/skeleton';
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useCartStore } from '@/stores/cart';
import { useToast } from 'primevue/usetoast';
import api from '@/api';

const route = useRoute();
const cartStore = useCartStore();
const toast = useToast();
const product = ref(null);
const qty = ref(1);

onMounted(async () => {
    const slug = Array.isArray(route.params.slug) ? route.params.slug[0] : route.params.slug;
    if (typeof slug !== 'string' || !slug.trim() || slug === 'undefined') {
        toast.add({ severity: 'error', summary: 'Producto no disponible', detail: 'El enlace del producto no es valido.', life: 3000 });
        if (import.meta.env.DEV) console.warn('ProductDetail se abrio sin un slug valido.', route.params);
        return;
    }
    try {
        const response = await api.get(`/products/${encodeURIComponent(slug)}`);
        product.value = response.data;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Producto no disponible', detail: e.response?.status === 404 ? 'El producto solicitado no existe.' : 'No se pudo cargar el producto.', life: 3500 });
        if (import.meta.env.DEV) console.error('Error al cargar el detalle del producto.', e);
    }
});

const addToCart = () => {
    console.log('addToCart called', { product: product.value, toast: toast });
    
    if (!product.value) return;
    
    // Check if product has stock
    if (!product.value.stock || product.value.stock === 0) {
        console.log('No stock, showing error toast');
        toast.add({
            severity: 'error',
            summary: 'Sin Stock',
            detail: `Lo sentimos, "${product.value.name}" no está disponible en este momento.`,
            life: 3000
        });
        return;
    }

    // Check if adding qty would exceed available stock
    const currentQtyInCart = cartStore.items.find(item => item.product_id === product.value.id)?.quantity || 0;
    if (currentQtyInCart + qty.value > product.value.stock) {
        console.log('Stock limit reached, showing warning toast');
        toast.add({
            severity: 'warn',
            summary: 'Stock Limitado',
            detail: `Solo quedan ${product.value.stock - currentQtyInCart} unidades disponibles de "${product.value.name}".`,
            life: 3000
        });
        return;
    }

    // Add to cart
    for(let i=0; i<qty.value; i++) {
        cartStore.addItem(product.value);
    }
    
    console.log('Product added, showing success toast');
    // Show success toast
    toast.add({
        severity: 'success',
        summary: 'Producto Agregado',
        detail: `${qty.value} unidad(es) de "${product.value.name}" agregadas al carrito.`,
        life: 3000
    });
};
</script>
