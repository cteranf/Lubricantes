<template>
    <div class="bg-white rounded-lg shadow hover:shadow-xl transition overflow-hidden flex flex-col">
        <div class="h-48 bg-gray-200 flex items-center justify-center relative">
            <img v-if="product.image_path" :src="product.image_path" :alt="product.name" class="h-full w-full object-cover">
            <i v-else class="pi pi-image text-4xl text-gray-400"></i>
            
            <span v-if="product.sale_price" class="absolute top-2 right-2 bg-red-500 text-white text-xs font-bold px-2 py-1 rounded">
                OFERTA
            </span>
        </div>
        <div class="p-4 flex-grow flex flex-col">
            <p class="text-sm text-gray-500 mb-1">{{ product.brand?.name }}</p>
            <h3 class="text-lg font-bold mb-2 flex-grow hover:text-blue-600 cursor-pointer" @click="goToDetail">
                {{ product.name }}
            </h3>
            
            <div class="flex items-end justify-between mt-4">
                <div>
                     <span v-if="product.sale_price" class="text-gray-400 line-through text-sm mr-2">S/ {{ product.price }}</span>
                     <span class="text-xl font-bold text-gray-900">S/ {{ product.sale_price || product.price }}</span>
                </div>
                <button 
                    @click="addToCart"
                    class="bg-blue-600 text-white p-2 rounded-full hover:bg-blue-700 transition flex items-center justify-center h-10 w-10"
                >
                    <i class="pi pi-cart-plus"></i>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { useCartStore } from '@/stores/cart';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    product: {
        type: Object,
        required: true
    }
});

const router = useRouter();
const cartStore = useCartStore();
const toast = useToast();

const goToDetail = () => {
    router.push(`/product/${props.product.slug}`);
};

const addToCart = () => {
    console.log('ProductCard addToCart called', { product: props.product, toast });
    
    // Check if product has stock
    if (!props.product.stock || props.product.stock === 0) {
        toast.add({
            severity: 'error',
            summary: 'Sin Stock',
            detail: `Lo sentimos, "${props.product.name}" no está disponible.`,
            life: 3000
        });
        return;
    }

    // Check if adding more would exceed available stock
    const currentQtyInCart = cartStore.items.find(item => item.product_id === props.product.id)?.quantity || 0;
    if (currentQtyInCart >= props.product.stock) {
        toast.add({
            severity: 'warn',
            summary: 'Stock Limitado',
            detail: `Ya tienes el máximo disponible de "${props.product.name}" en tu carrito.`,
            life: 3000
        });
        return;
    }

    // Add to cart
    cartStore.addItem(props.product);
    
    console.log('Product added to cart, showing toast');
    // Show success toast
    toast.add({
        severity: 'success',
        summary: 'Agregado al Carrito',
        detail: `"${props.product.name}" se agregó correctamente.`,
        life: 2500
    });
};
</script>
