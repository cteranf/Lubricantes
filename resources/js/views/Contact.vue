<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-12">
            <h1 class="text-4xl font-bold text-center mb-12">Contáctanos</h1>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 max-w-6xl mx-auto">
                <!-- Informacion -->
                <div>
                    <h2 class="text-2xl font-bold mb-6">Estamos aquí para ayudarte</h2>
                    <p class="text-gray-600 mb-8">
                        ¿Tienes dudas sobre qué aceite usar? ¿Consultas sobre tu pedido? Escríbenos y te responderemos a la brevedad.
                    </p>
                    
                    <div class="space-y-6">
                        <div class="flex items-start">
                            <div class="bg-blue-100 p-3 rounded-full mr-4">
                                <i class="pi pi-map-marker text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-lg">Ubicación</h3>
                                <p class="text-gray-600">Av. Principal 123, La Victoria<br>Lima, Perú</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-green-100 p-3 rounded-full mr-4">
                                <i class="pi pi-whatsapp text-green-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-lg">WhatsApp / Teléfono</h3>
                                <p class="text-gray-600">+51 999 999 999</p>
                                <p class="text-sm text-gray-500">Lines a Sábado: 9am - 6pm</p>
                            </div>
                        </div>

                        <div class="flex items-start">
                            <div class="bg-red-100 p-3 rounded-full mr-4">
                                <i class="pi pi-envelope text-red-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-lg">Correo Electrónico</h3>
                                <p class="text-gray-600">contacto@lubristore.com</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulario -->
                <div class="bg-white p-8 rounded-lg shadow-lg">
                    <h2 class="text-2xl font-bold mb-6">Envíanos un mensaje</h2>
                    <form @submit.prevent="submitForm">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                            <input v-model="form.name" type="text" class="w-full border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input v-model="form.email" type="email" class="w-full border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none" required>
                        </div>
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mensaje</label>
                            <textarea v-model="form.message" rows="4" class="w-full border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none" required></textarea>
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded hover:bg-blue-700 transition">
                            Enviar Mensaje
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { ref } from 'vue';
import { useToast } from 'primevue/usetoast';

const toast = useToast();

const form = ref({ name: '', email: '', message: '' });

const submitForm = () => {
    alert("Mensaje enviado (Simulado). Pronto nos pondremos en contacto.");
    form.value = { name: '', email: '', message: '' };
};
</script>
