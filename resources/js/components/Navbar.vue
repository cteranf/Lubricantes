<template>
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <!-- Logo -->
            <router-link to="/" class="text-2xl font-bold text-blue-700 flex items-center">
                <span>LubriStore</span>
            </router-link>

            <!-- Search Bar (Desktop) -->
            <div class="hidden md:flex flex-1 mx-8 relative">
                <input 
                    v-model="searchQuery" 
                    @keyup.enter="handleSearch"
                    type="text" 
                    placeholder="Buscar producto..." 
                    class="w-full border rounded-full py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-gray-100"
                />
                <button @click="handleSearch" class="absolute right-3 top-2 text-gray-500 hover:text-blue-600">
                    <i class="pi pi-search"></i>
                </button>
            </div>

            <!-- Icons -->
            <div class="flex items-center space-x-4">
                <router-link to="/cart" class="relative text-gray-700 hover:text-blue-600">
                    <i class="pi pi-shopping-cart text-xl"></i>
                    <span v-if="cartStore.count > 0" class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                        {{ cartStore.count }}
                    </span>
                </router-link>

                <div v-if="authStore.isAuthenticated" class="relative">
                    <button @click="toggleMenu" class="flex items-center space-x-2 text-gray-700 hover:text-blue-600">
                        <span class="font-medium">{{ authStore.user?.name }}</span>
                        <i class="pi pi-user"></i>
                    </button>
                     <!-- Dropdown -->
                     <div v-if="menuOpen" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 border">
                         <router-link to="/profile" class="block px-4 py-2 hover:bg-gray-100 text-sm">Mi Perfil</router-link>
                         <router-link to="/orders" class="block px-4 py-2 hover:bg-gray-100 text-sm">Mis Pedidos</router-link>
                         <a v-if="authStore.isAdmin" href="/admin/dashboard" class="block px-4 py-2 hover:bg-gray-100 text-sm text-blue-600 font-bold">Panel Admin</a>
                         <button @click="logout" class="block w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600">Cerrar Sesión</button>
                     </div>
                </div>
                <div v-else class="space-x-2">
                    <router-link to="/login" class="text-sm font-medium hover:text-blue-600">Ingresar</router-link>
                    <router-link to="/register" class="bg-blue-600 text-white px-4 py-2 rounded-full text-sm hover:bg-blue-700">Registro</router-link>
                </div>
            </div>
        </div>
        <!-- Mobile Search -->
         <div class="md:hidden px-4 pb-2">
            <input v-model="searchQuery" @keyup.enter="handleSearch" type="text" placeholder="Buscar..." class="w-full border rounded-full py-2 px-4 bg-gray-100" />
        </div>
    </nav>
</template>

<script setup>
import { ref } from 'vue';
import { useCartStore } from '@/stores/cart';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';

const cartStore = useCartStore();
const authStore = useAuthStore();
const router = useRouter();

const searchQuery = ref('');
const menuOpen = ref(false);

const handleSearch = () => {
    if (searchQuery.value) {
        router.push({ path: '/catalog', query: { search: searchQuery.value } });
    }
};

const toggleMenu = () => menuOpen.value = !menuOpen.value;

const logout = async () => {
    await authStore.logout();
    router.push('/login');
    menuOpen.value = false;
};
</script>
