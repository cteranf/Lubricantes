<template>
    <AppLayout>
        <section class="bg-blue-600 text-white py-16">
            <div class="container mx-auto px-4 text-center">
                <h1 class="text-4xl font-bold mb-4">Sobre Nosotros</h1>
                <p class="text-xl max-w-2xl mx-auto">Conoce más sobre LubriStore y nuestra misión de mantener tu motor en movimiento.</p>
            </div>
        </section>

        <section class="py-16">
            <div class="container mx-auto px-4 flex flex-col md:flex-row items-center gap-12">
                <div class="w-full md:w-1/2">
                    <img src="https://images.unsplash.com/photo-1517524008697-84bbe3c3fd98?q=80&w=1600&auto=format&fit=crop" alt="Taller" class="rounded-lg shadow-xl">
                </div>
                <div class="w-full md:w-1/2">
                    <h2 class="text-3xl font-bold mb-6 text-gray-800">Nuestra Historia</h2>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Fundada en 2020, LubriStore nació con la visión de ofrecer los mejores lubricantes y aditivos vehiculares del mercado peruano en un solo lugar. Nos especializamos en brindar productos originales de marcas líderes mundialmente reconocidas.
                    </p>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Creemos que el mantenimiento preventivo es la clave para la longevidad de tu vehículo. Nuestro equipo de expertos está siempre dispuesto a asesorarte para que elijas el producto exacto que tu motor necesita.
                    </p>
                    <div class="grid grid-cols-2 gap-4 mt-8">
                        <div class="bg-gray-100 p-4 rounded text-center">
                            <h3 class="font-bold text-2xl text-blue-600">5+</h3>
                            <p class="text-sm text-gray-500">Años de experiencia</p>
                        </div>
                        <div class="bg-gray-100 p-4 rounded text-center">
                            <h3 class="font-bold text-2xl text-blue-600">10k+</h3>
                            <p class="text-sm text-gray-500">Clientes satisfechos</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-gray-50 py-16">
            <div class="container mx-auto px-4 text-center">
                 <h2 class="text-3xl font-bold mb-12">Nuestros Valores</h2>
                 <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                     <div class="bg-white p-8 rounded-lg shadow hover:shadow-lg transition">
                         <i class="pi pi-check-circle text-4xl text-green-500 mb-4"></i>
                         <h3 class="font-bold text-xl mb-2">Calidad Original</h3>
                         <p class="text-gray-600">Garantizamos la autenticidad de todos nuestros productos.</p>
                     </div>
                     <div class="bg-white p-8 rounded-lg shadow hover:shadow-lg transition">
                         <i class="pi pi-users text-4xl text-blue-500 mb-4"></i>
                         <h3 class="font-bold text-xl mb-2">Atención Experta</h3>
                         <p class="text-gray-600">Asesoría técnica personalizada para cada cliente.</p>
                     </div>
                     <div class="bg-white p-8 rounded-lg shadow hover:shadow-lg transition">
                         <i class="pi pi-bolt text-4xl text-yellow-500 mb-4"></i>
                         <h3 class="font-bold text-xl mb-2">Rapidez</h3>
                         <p class="text-gray-600">Envíos rápidos y seguros a todo el país.</p>
                     </div>
                 </div>
            </div>
        </section>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
</script>
