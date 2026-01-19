<template>
    <div class="flex flex-col min-h-screen">
        <Navbar />
        <main class="flex-grow">
            <slot />
        </main>
        <Footer />
        <WhatsAppBtn />
    </div>
</template>

<script setup>
import Navbar from '@/components/Navbar.vue';
import Footer from '@/components/Footer.vue';
import WhatsAppBtn from '@/components/WhatsAppBtn.vue';
</script>
