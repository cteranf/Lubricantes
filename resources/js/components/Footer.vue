<template>
    <footer class="bg-gray-800 text-white mt-12 py-10">
        <div class="container mx-auto px-4 grid grid-cols-1 md:grid-cols-3 gap-8">
            <div>
                <h3 class="text-xl font-bold mb-4">LubriStore</h3>
                <p class="text-gray-400">
                    Tu tienda de confianza para lubricantes y repuestos vehiculares.
                    Calidad y garantía asegurada.
                </p>
            </div>
            <div>
                <h3 class="text-lg font-bold mb-4">Enlaces Rápidos</h3>
                <ul class="space-y-2 text-gray-400 text-sm">
                    <li><router-link to="/" class="hover:text-white">Inicio</router-link></li>
                    <li><router-link to="/catalog" class="hover:text-white">Catálogo</router-link></li>
                    <li><router-link to="/about" class="hover:text-white">Quiénes Somos</router-link></li>
                    <li><router-link to="/contact" class="hover:text-white">Contacto</router-link></li>
                </ul>
            </div>
            <div>
                <h3 class="text-lg font-bold mb-4">Contacto</h3>
                <ul class="space-y-2 text-gray-400 text-sm">
                    <li><i class="pi pi-phone mr-2"></i> +51 999 999 999</li>
                    <li><i class="pi pi-envelope mr-2"></i> contacto@lubristore.com</li>
                    <li><i class="pi pi-map-marker mr-2"></i> Lima, Perú</li>
                </ul>
            </div>
        </div>
        <div class="container mx-auto px-4 mt-8 pt-8 border-t border-gray-700 text-center text-gray-500 text-sm">
            &copy; 2026 LubriStore. Todos los derechos reservados.
        </div>
    </footer>
</template>
