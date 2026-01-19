<template>
    <div class="flex min-h-screen bg-gray-100">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-900 text-white flex-shrink-0 hidden md:block">
            <div class="h-16 flex items-center justify-center border-b border-gray-800">
                <span class="text-xl font-bold">Admin Panel</span>
            </div>
            <nav class="mt-4">
                <router-link to="/admin/dashboard" active-class="bg-gray-800 text-blue-400" class="block py-3 px-6 hover:bg-gray-800">
                    <i class="pi pi-home mr-3"></i> Dashboard
                </router-link>
                <router-link to="/admin/products" active-class="bg-gray-800 text-blue-400" class="block py-3 px-6 hover:bg-gray-800">
                    <i class="pi pi-box mr-3"></i> Productos
                </router-link>
                <router-link to="/admin/categories" active-class="bg-gray-800 text-blue-400" class="block py-3 px-6 hover:bg-gray-800">
                    <i class="pi pi-tags mr-3"></i> Categorías
                </router-link>
                <router-link to="/admin/news" active-class="bg-gray-800 text-blue-400" class="block py-3 px-6 hover:bg-gray-800">
                    <i class="pi pi-megaphone mr-3"></i> Noticias
                </router-link>
                <router-link to="/admin/orders" active-class="bg-gray-800 text-blue-400" class="block py-3 px-6 hover:bg-gray-800">
                    <i class="pi pi-shopping-cart mr-3"></i> Pedidos
                </router-link>
                <router-link to="/admin/sliders" active-class="bg-gray-800 text-blue-400" class="block py-3 px-6 hover:bg-gray-800">
                    <i class="pi pi-images mr-3"></i> Sliders
                </router-link>
                <button @click="logout" class="block w-full text-left py-3 px-6 hover:bg-red-900 text-red-400 mt-8">
                    <i class="pi pi-sign-out mr-3"></i> Cerrar Sesión
                </button>
            </nav>
        </aside>

        <!-- Mobile Header -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <header class="h-16 bg-white shadow flex items-center justify-between px-6 md:hidden">
                <span class="font-bold text-lg">Admin Panel</span>
                <button @click="toggleSidebar"><i class="pi pi-bars"></i></button>
            </header>

            <!-- Main Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>

<script setup>
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();

const logout = async () => {
    await authStore.logout();
    router.push('/login');
};
</script>
