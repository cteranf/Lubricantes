<template>
    <div class="flex min-h-screen flex-col bg-slate-50 text-slate-900">
        <Navbar />
        <main id="main-content" class="flex-grow" tabindex="-1">
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
