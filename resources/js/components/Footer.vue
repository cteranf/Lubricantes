<template>
    <footer class="bg-slate-950 pb-[calc(6.5rem+env(safe-area-inset-bottom))] pt-12 text-white">
        <div class="site-container grid grid-cols-1 gap-10 md:grid-cols-2 lg:grid-cols-4">
            <div class="md:col-span-2 lg:col-span-1">
                <router-link to="/" class="focus-ring inline-block rounded-lg text-2xl font-black">Lubri<span class="text-blue-400">Store</span></router-link>
                <p class="mt-4 max-w-sm leading-relaxed text-slate-400">Lubricantes y productos para el cuidado y mantenimiento de tu vehículo.</p>
            </div>
            <div>
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-200">Enlaces rápidos</h2>
                <ul class="mt-4 space-y-3 text-sm text-slate-400">
                    <li><router-link to="/" class="footer-link">Inicio</router-link></li>
                    <li><router-link to="/catalog" class="footer-link">Catálogo</router-link></li>
                    <li><router-link :to="{ path: '/', hash: '#categories' }" class="footer-link">Categorías</router-link></li>
                    <li><router-link to="/about" class="footer-link">Nosotros</router-link></li>
                    <li><router-link to="/contact" class="footer-link">Contacto</router-link></li>
                </ul>
            </div>
            <div>
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-200">Atención</h2>
                <ul class="mt-4 space-y-3 text-sm text-slate-400">
                    <li v-if="whatsappLink"><a :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="footer-link inline-flex items-start gap-2"><i class="pi pi-whatsapp mt-0.5" aria-hidden="true"></i><span>Asesoría por WhatsApp</span></a></li>
                    <li><router-link to="/contact" class="footer-link inline-flex items-start gap-2"><i class="pi pi-map-marker mt-0.5" aria-hidden="true"></i><span>Información de contacto y ubicación</span></router-link></li>
                </ul>
            </div>
            <div>
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-200">Tu compra</h2>
                <ul class="mt-4 space-y-3 text-sm text-slate-400"><li><router-link to="/cart" class="footer-link">Carrito</router-link></li><li><router-link to="/orders" class="footer-link">Mis pedidos</router-link></li></ul>
            </div>
        </div>
        <div class="site-container mt-10 border-t border-slate-800 pt-6 text-sm leading-relaxed text-slate-500"><p>© {{ currentYear }} LubriStore. Todos los derechos reservados.</p></div>
    </footer>
</template>

<script setup>
const currentYear = new Date().getFullYear();
const phone = String(import.meta.env.VITE_WHATSAPP_PHONE || '').replace(/\D+/g, '');
const whatsappLink = phone ? `https://wa.me/${phone}?text=${encodeURIComponent('Hola, necesito asesoría para elegir un producto de LubriStore.')}` : '';
</script>
