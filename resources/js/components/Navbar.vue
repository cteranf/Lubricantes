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
                    <button type="button" class="icon-button relative" aria-label="Abrir carrito" :aria-expanded="cartStore.drawerOpen" aria-controls="cart-drawer" @click="cartStore.openDrawer($event.currentTarget)">
                        <i class="pi pi-shopping-cart text-lg" aria-hidden="true"></i>
                        <span v-if="cartStore.count > 0" class="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold leading-none text-white" :aria-label="`${cartStore.count} unidades en el carrito`">{{ cartCountLabel }}</span>
                    </button>

                    <div v-if="authStore.isAuthenticated" class="relative">
                        <button
                            type="button"
                            class="account-button gap-1.5 px-2 sm:px-3"
                            aria-haspopup="menu"
                            aria-controls="account-menu"
                            :aria-expanded="accountMenuOpen"
                            :aria-label="`Menú de usuario: ${accountDisplayName}`"
                            @click="toggleAccountMenu"
                        >
                            <span class="account-avatar" aria-hidden="true">{{ accountInitials }}</span>
                            <span class="hidden max-w-36 truncate md:inline">{{ accountDisplayName }}</span>
                            <i class="pi pi-chevron-down hidden text-xs md:inline" aria-hidden="true"></i>
                        </button>

                        <Menu
                            id="account-menu"
                            ref="accountMenuRef"
                            :model="accountMenuItems"
                            popup
                            append-to="body"
                            aria-label="Menú de usuario"
                            :pt="accountMenuPassThrough"
                            @show="accountMenuOpen = true"
                            @hide="accountMenuOpen = false"
                        >
                            <template #start>
                                <div class="account-menu-heading">
                                    <span class="account-avatar account-avatar-large" aria-hidden="true">{{ accountInitials }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-bold text-slate-900">{{ accountDisplayName }}</span>
                                        <span class="mt-0.5 block text-xs text-slate-500">Mi cuenta</span>
                                    </span>
                                </div>
                            </template>
                            <template #item="{ item, props }">
                                <router-link v-if="item.to" :to="item.to" custom v-slot="{ href, navigate }">
                                    <a :href="href" v-bind="props.action" :aria-current="isAccountRouteActive(item) ? 'page' : undefined" :class="{ 'account-menu-action-active': isAccountRouteActive(item) }" @click="navigate">
                                        <span v-bind="props.icon" aria-hidden="true"></span>
                                        <span v-bind="props.label">{{ item.label }}</span>
                                    </a>
                                </router-link>
                                <a v-else href="#" v-bind="props.action" :aria-disabled="isLoggingOut" :class="{ 'pointer-events-none opacity-60': isLoggingOut }" @click.prevent>
                                    <span v-bind="props.icon" aria-hidden="true"></span>
                                    <span v-bind="props.label">{{ item.label }}</span>
                                    <i v-if="isLoggingOut" class="pi pi-spinner pi-spin ml-auto" aria-hidden="true"></i>
                                </a>
                            </template>
                        </Menu>
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
import Menu from 'primevue/menu';
import { isAccountMenuRouteActive } from '@/utils/accountMenuRoute.mjs';
import { useAuthStore } from '@/stores/auth';
import { useCartStore } from '@/stores/cart';

const cartStore = useCartStore();
const authStore = useAuthStore();
const router = useRouter();
const route = useRoute();
const searchQuery = ref('');
const mobileMenuOpen = ref(false);
const accountMenuOpen = ref(false);
const accountMenuRef = ref(null);
const isLoggingOut = ref(false);
const cartCountLabel = computed(() => cartStore.count > 99 ? '99+' : cartStore.count);
const accountDisplayName = computed(() => {
    const name = typeof authStore.user?.name === 'string' ? authStore.user.name.trim() : '';

    return name || 'Usuario';
});
const accountInitials = computed(() => {
    const parts = accountDisplayName.value.split(/\s+/u).filter(Boolean);
    const initials = parts.slice(0, 2).map(part => Array.from(part)[0]?.toLocaleUpperCase('es-PE') || '');

    return initials.join('') || 'U';
});
const navigation = [
    { label: 'Inicio', to: '/' },
    { label: 'Catálogo', to: '/catalog' },
    { label: 'Categorías', to: { path: '/', hash: '#categories' } },
    { label: 'Nosotros', to: '/about' },
    { label: 'Contacto', to: '/contact' },
];
const accountMenuItems = computed(() => [
    { label: 'Mi perfil', icon: 'pi pi-user', to: '/profile' },
    { label: 'Mis pedidos', icon: 'pi pi-receipt', to: '/orders', activeScope: 'subtree' },
    ...(authStore.isAdmin ? [{ label: 'Panel administrativo', icon: 'pi pi-cog', to: '/admin/dashboard', activeScope: 'admin' }] : []),
    ...(authStore.isTreasury ? [{ label: 'Panel de tesorería', icon: 'pi pi-wallet', to: { name: 'treasury-payments' }, activeScope: 'treasury' }] : []),
    { separator: true },
    { label: 'Cerrar sesión', icon: 'pi pi-sign-out', command: logout, disabled: isLoggingOut.value, class: 'account-menu-logout' },
]);
const accountMenuPassThrough = {
    root: { class: 'account-menu-panel w-[min(18rem,calc(100vw-1rem))] max-w-[calc(100vw-1rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl' },
    start: { class: 'border-b border-slate-100 px-4 py-3' },
    list: { class: 'max-h-[min(70vh,28rem)] overflow-y-auto p-2' },
    itemLink: { class: 'account-menu-action flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-700 no-underline transition hover:bg-blue-50 hover:text-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-blue-700' },
    separator: { class: 'my-1 border-t border-slate-100' },
};

function isAccountRouteActive(item) {
    return isAccountMenuRouteActive(item, route.path);
}

function toggleAccountMenu(event) {
    accountMenuRef.value?.toggle(event, event.currentTarget);
}

function closeMenus() {
    mobileMenuOpen.value = false;
    accountMenuRef.value?.hide();
    accountMenuOpen.value = false;
}

function handleSearch() {
    const search = searchQuery.value.trim();

    if (search) router.push({ path: '/catalog', query: { search } });
}

async function logout() {
    if (isLoggingOut.value) return;

    isLoggingOut.value = true;

    try {
        await authStore.logout();
        closeMenus();
        await router.push('/login');
    } finally {
        isLoggingOut.value = false;
    }
}

function handleEscape(event) {
    if (event.key === 'Escape') mobileMenuOpen.value = false;
}

watch(() => route.fullPath, closeMenus);
onMounted(() => document.addEventListener('keydown', handleEscape));
onBeforeUnmount(() => document.removeEventListener('keydown', handleEscape));
</script>

<style scoped>
.account-avatar {
    display: inline-flex;
    width: 2.25rem;
    height: 2.25rem;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    border: 1px solid rgb(191 219 254);
    border-radius: 9999px;
    background: rgb(239 246 255);
    color: rgb(30 64 175);
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.025em;
}

.account-avatar-large {
    width: 2.5rem;
    height: 2.5rem;
    font-size: 0.875rem;
}

.account-menu-heading {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 0.75rem;
}

:global(.account-menu-panel .p-menu-item-content:hover) {
    background: transparent;
}

:global(.account-menu-panel .account-menu-logout .account-menu-action) {
    color: rgb(185 28 28);
}

:global(.account-menu-panel .account-menu-action-active) {
    background: rgb(239 246 255);
    color: rgb(30 64 175);
    font-weight: 700;
}

:global(.account-menu-panel .account-menu-action:focus-visible) {
    outline: 2px solid rgb(29 78 216);
    outline-offset: 2px;
}
</style>
