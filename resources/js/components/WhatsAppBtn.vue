<template>
    <a v-if="whatsappLink" :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="whatsapp-button" aria-label="Consultar por WhatsApp" title="Consultar por WhatsApp">
        <i class="pi pi-whatsapp text-2xl sm:text-3xl" aria-hidden="true"></i><span class="sr-only">Consultar por WhatsApp</span>
    </a>
</template>

<script setup>
const phone = String(import.meta.env.VITE_WHATSAPP_PHONE || '').replace(/\D+/g, '');
const whatsappLink = phone ? `https://wa.me/${phone}?text=${encodeURIComponent('Hola, necesito asesoría para elegir un producto de LubriStore.')}` : '';
</script>

<style scoped>
.whatsapp-button { position: fixed; right: max(1rem, env(safe-area-inset-right)); bottom: max(1rem, env(safe-area-inset-bottom)); z-index: 40; display: flex; width: 3.25rem; height: 3.25rem; align-items: center; justify-content: center; border-radius: 9999px; background: #16a34a; color: white; box-shadow: 0 12px 28px rgba(15, 23, 42, .28); transition: transform .2s ease, background-color .2s ease; }
.whatsapp-button:hover { background: #15803d; transform: translateY(-2px); }
.whatsapp-button:focus-visible { outline: 3px solid white; outline-offset: 3px; box-shadow: 0 0 0 6px #2563eb; }
@media (min-width: 640px) { .whatsapp-button { right: max(1.5rem, env(safe-area-inset-right)); bottom: max(1.5rem, env(safe-area-inset-bottom)); width: 3.75rem; height: 3.75rem; } }
@media (prefers-reduced-motion: reduce) { .whatsapp-button { transition: none; } }
</style>
