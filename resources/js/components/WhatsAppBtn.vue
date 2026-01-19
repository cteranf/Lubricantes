<template>
    <a 
        :href="whatsappLink" 
        target="_blank" 
        class="fixed bottom-6 right-6 z-50 bg-green-500 hover:bg-green-600 text-white rounded-full p-4 shadow-lg flex items-center justify-center transition-transform hover:scale-110"
    >
        <i class="pi pi-whatsapp text-3xl"></i>
    </a>
</template>

<script setup>
import { computed } from 'vue';

const phone = import.meta.env.VITE_WHATSAPP_PHONE || '51999999999';
const message = "Hola, estoy interesado en productos de la tienda.";
const whatsappLink = computed(() => `https://wa.me/${phone}?text=${encodeURIComponent(message)}`);
</script>
