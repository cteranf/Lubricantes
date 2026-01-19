<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8">
            <h1 class="text-3xl font-bold mb-8">Mi Perfil</h1>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Sidebar Menu (could be a component) -->
                <div class="bg-white rounded-lg shadow h-fit">
                    <ul class="text-sm">
                        <li><router-link to="/profile" class="block px-6 py-4 border-l-4 border-blue-600 bg-blue-50 text-blue-700 font-bold">Información Personal</router-link></li>
                        <li><router-link to="/orders" class="block px-6 py-4 border-l-4 border-transparent hover:bg-gray-50 hover:text-blue-600">Mis Pedidos</router-link></li>
                        <li><button @click="authStore.logout(); router.push('/login')" class="w-full text-left px-6 py-4 border-l-4 border-transparent hover:bg-red-50 hover:text-red-600 text-red-500">Cerrar Sesión</button></li>
                    </ul>
                </div>

                <!-- Content -->
                <div class="col-span-1 md:col-span-2">
                    <div class="bg-white rounded-lg shadow-lg p-8">
                         <h2 class="text-xl font-bold mb-6 border-b pb-4">Datos de la Cuenta</h2>
                         
                         <form @submit.prevent class="space-y-6">
                             <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                 <div>
                                     <label class="block text-gray-700 font-medium mb-2">Nombre Completo</label>
                                     <input :value="authStore.user?.name" disabled class="w-full bg-gray-100 border rounded px-4 py-2 cursor-not-allowed text-gray-500">
                                 </div>
                                  <div>
                                     <label class="block text-gray-700 font-medium mb-2">Email</label>
                                     <input :value="authStore.user?.email" disabled class="w-full bg-gray-100 border rounded px-4 py-2 cursor-not-allowed text-gray-500">
                                 </div>
                             </div>

                             <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                                <p class="text-sm text-yellow-700">Para actualizar tus datos sensibles, por favor contacta a soporte.</p>
                             </div>
                         </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';
import { onMounted } from 'vue';

const authStore = useAuthStore();
const router = useRouter();

onMounted(() => {
    if (!authStore.isAuthenticated) {
        router.push('/login');
    } else {
        authStore.fetchUser();
    }
});
</script>
