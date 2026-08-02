<template>
    <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
        <a href="#main-content" class="skip-link">Saltar al contenido</a>
        <nav class="site-container" aria-label="Navegación principal">
            <div class="flex min-h-16 items-center gap-2 xl:gap-6">
                <router-link to="/" class="focus-ring shrink-0 rounded-lg text-xl font-black tracking-tight text-blue-800 sm:text-2xl" aria-label="LubriStore, inicio">Lubri<span class="text-blue-600">Store</span></router-link>

                <div class="hidden min-w-0 flex-1 items-center justify-center gap-1 xl:flex">
                    <router-link v-for="item in navigation" :key="item.label" :to="item.to" class="nav-link">{{ item.label }}</router-link>
                </div>

                <form class="relative ml-auto hidden w-full max-w-sm xl:block" role="search" @submit.prevent="handleSearch">
                    <label class="sr-only" for="desktop-product-search">Buscar productos</label>
                    <input id="desktop-product-search" v-model="searchQuery" type="search" placeholder="Buscar productos" class="search-input pr-12">
                    <button type="submit" class="search-button" :disabled="!searchQuery.trim()" aria-label="Buscar productos"><i class="pi pi-search" aria-hidden="true"></i></button>
                </form>

                <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2 xl:ml-0">
                    <button type="button" class="icon-button xl:hidden" :aria-expanded="mobileMenuOpen" aria-controls="mobile-navigation" :aria-label="mobileMenuOpen ? 'Cerrar menú' : 'Abrir menú'" @click="mobileMenuOpen = !mobileMenuOpen"><i :class="mobileMenuOpen ? 'pi pi-times' : 'pi pi-bars'" aria-hidden="true"></i></button>
                    <router-link to="/cart" class="icon-button relative" aria-label="Ver carrito">
                        <i class="pi pi-shopping-cart text-lg" aria-hidden="true"></i>
                        <span v-if="cartStore.count > 0" class="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold leading-none text-white" aria-label="Productos en el carrito">{{ cartCountLabel }}</span>
                    </router-link>

                    <div v-if="authStore.isAuthenticated" class="relative">
                        <button type="button" class="account-button" :aria-expanded="accountMenuOpen" aria-controls="account-menu" @click="accountMenuOpen = !accountMenuOpen"><i class="pi pi-user" aria-hidden="true"></i><span class="hidden max-w-32 truncate md:inline">{{ authStore.user?.name || 'Mi cuenta' }}</span><i class="pi pi-chevron-down hidden text-xs md:inline" aria-hidden="true"></i></button>
                        <div v-if="accountMenuOpen" id="account-menu" class="absolute right-0 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-2 shadow-xl">
                            <router-link to="/profile" class="dropdown-link">Mi perfil</router-link>
                            <router-link to="/orders" class="dropdown-link">Mis pedidos</router-link>
                            <router-link v-if="authStore.isAdmin" to="/admin/dashboard" class="dropdown-link font-bold text-blue-700">Panel administrativo</router-link>
                            <button type="button" class="dropdown-link w-full text-left text-red-700" @click="logout">Cerrar sesión</button>
                        </div>
                    </div>
                    <router-link v-else to="/login" class="account-button" aria-label="Ingresar a mi cuenta"><i class="pi pi-user" aria-hidden="true"></i><span class="hidden sm:inline">Ingresar</span></router-link>
                    <router-link v-if="!authStore.isAuthenticated" to="/register" class="btn-primary hidden xl:inline-flex">Registro</router-link>
                </div>
            </div>

            <form class="relative pb-3 xl:hidden" role="search" @submit.prevent="handleSearch">
                <label class="sr-only" for="mobile-product-search">Buscar productos</label>
                <input id="mobile-product-search" v-model="searchQuery" type="search" placeholder="Buscar productos" class="search-input pr-12">
                <button type="submit" class="search-button" :disabled="!searchQuery.trim()" aria-label="Buscar productos"><i class="pi pi-search" aria-hidden="true"></i></button>
            </form>

            <div v-if="mobileMenuOpen" id="mobile-navigation" class="border-t border-slate-200 pb-4 pt-3 xl:hidden">
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                    <router-link v-for="item in navigation" :key="item.label" :to="item.to" class="mobile-nav-link">{{ item.label }}</router-link>
                    <router-link v-if="!authStore.isAuthenticated" to="/register" class="mobile-nav-link font-bold text-blue-700">Crear una cuenta</router-link>
                </div>
            </div>
        </nav>
    </header>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useCartStore } from '@/stores/cart';

const cartStore = useCartStore();
const authStore = useAuthStore();
const router = useRouter();
const route = useRoute();
const searchQuery = ref('');
const mobileMenuOpen = ref(false);
const accountMenuOpen = ref(false);
const cartCountLabel = computed(() => cartStore.count > 99 ? '99+' : cartStore.count);
const navigation = [
    { label: 'Inicio', to: '/' },
    { label: 'Catálogo', to: '/catalog' },
    { label: 'Categorías', to: { path: '/', hash: '#categories' } },
    { label: 'Nosotros', to: '/about' },
    { label: 'Contacto', to: '/contact' },
];
const closeMenus = () => { mobileMenuOpen.value = false; accountMenuOpen.value = false; };
const handleSearch = () => {
    const search = searchQuery.value.trim();
    if (search) router.push({ path: '/catalog', query: { search } });
};
const logout = async () => { await authStore.logout(); closeMenus(); router.push('/login'); };
const handleEscape = event => { if (event.key === 'Escape') closeMenus(); };
watch(() => route.fullPath, closeMenus);
onMounted(() => document.addEventListener('keydown', handleEscape));
onBeforeUnmount(() => document.removeEventListener('keydown', handleEscape));
</script>
