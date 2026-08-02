<template>
    <div class="flex min-h-screen bg-slate-100">
        <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-slate-950/60 md:hidden" aria-hidden="true" @mousedown.self="closeMobileMenu"></div>

        <aside
            id="admin-navigation"
            ref="sidebar"
            class="fixed inset-y-0 left-0 z-50 flex w-72 shrink-0 flex-col bg-slate-950 text-white shadow-2xl transition-transform md:static md:z-auto md:w-64 md:translate-x-0 md:shadow-none"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
            :aria-label="mobileOpen ? 'Menú administrativo' : undefined"
            @keydown="handleSidebarKeydown"
        >
            <div class="flex h-16 items-center justify-between border-b border-slate-800 px-5">
                <router-link to="/admin/dashboard" class="rounded text-xl font-black focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-400" @click="closeMobileMenu">Lubri<span class="text-blue-400">Store</span> Admin</router-link>
                <button ref="closeButton" type="button" class="flex h-11 w-11 items-center justify-center rounded-lg text-slate-300 hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400 md:hidden" aria-label="Cerrar menú administrativo" @click="closeMobileMenu"><i class="pi pi-times" aria-hidden="true"></i></button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Navegación administrativa">
                <router-link v-for="item in navigation" :key="item.to" :to="item.to" active-class="bg-slate-800 text-blue-300" class="mb-1 flex min-h-11 items-center rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:bg-slate-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400" @click="closeMobileMenu">
                    <i :class="item.icon" class="mr-3 w-5 text-center" aria-hidden="true"></i><span>{{ item.label }}</span>
                    <span v-if="item.to === '/admin/contact-inquiries' && pendingCount > 0" class="ml-auto flex min-h-6 min-w-6 items-center justify-center rounded-full bg-red-600 px-1.5 text-xs font-black text-white" :aria-label="`${pendingCount} consultas pendientes o en atención`">{{ pendingCount > 99 ? '99+' : pendingCount }}</span>
                </router-link>
                <button type="button" class="mt-7 flex min-h-11 w-full items-center rounded-xl px-4 py-2.5 text-left text-sm font-semibold text-red-300 transition hover:bg-red-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-400" @click="logout"><i class="pi pi-sign-out mr-3 w-5" aria-hidden="true"></i>Cerrar sesión</button>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <header class="flex min-h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm md:hidden">
                <span class="font-black text-slate-900">Panel administrativo</span>
                <button ref="menuButton" type="button" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" :aria-expanded="mobileOpen" aria-controls="admin-navigation" aria-label="Abrir menú administrativo" @click="openMobileMenu"><i class="pi pi-bars" aria-hidden="true"></i></button>
            </header>
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-100 p-4 sm:p-6"><slot /></main>
        </div>
    </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import api from '@/api';

const authStore = useAuthStore();
const router = useRouter();
const route = useRoute();
const mobileOpen = ref(false);
const menuButton = ref(null);
const closeButton = ref(null);
const sidebar = ref(null);
const pendingCount = ref(0);
let previousOverflow = '';

const navigation = [
    { label: 'Dashboard', to: '/admin/dashboard', icon: 'pi pi-home' },
    { label: 'Consultas', to: '/admin/contact-inquiries', icon: 'pi pi-inbox' },
    { label: 'Productos', to: '/admin/products', icon: 'pi pi-box' },
    { label: 'Sedes', to: '/admin/branches', icon: 'pi pi-map-marker' },
    { label: 'Almacenes', to: '/admin/warehouses', icon: 'pi pi-building' },
    { label: 'Inventario', to: '/admin/inventory', icon: 'pi pi-warehouse' },
    { label: 'Categorías', to: '/admin/categories', icon: 'pi pi-tags' },
    { label: 'Marcas', to: '/admin/brands', icon: 'pi pi-bookmark' },
    { label: 'Noticias', to: '/admin/news', icon: 'pi pi-megaphone' },
    { label: 'Pedidos', to: '/admin/orders', icon: 'pi pi-shopping-cart' },
    { label: 'Sliders', to: '/admin/sliders', icon: 'pi pi-images' },
];

async function loadPendingCount() {
    try { pendingCount.value = Number((await api.get('/admin/contact-inquiries/pending-count')).data.count || 0); }
    catch { /* El contador nunca bloquea el menú. */ }
}
async function openMobileMenu() {
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden'; mobileOpen.value = true;
    await nextTick(); closeButton.value?.focus();
}
function closeMobileMenu(returnFocus = true) {
    if (!mobileOpen.value) return;
    mobileOpen.value = false; document.body.style.overflow = previousOverflow;
    if (returnFocus) nextTick(() => menuButton.value?.focus());
}
function handleSidebarKeydown(event) {
    if (!mobileOpen.value) return;
    if (event.key === 'Escape') { event.preventDefault(); closeMobileMenu(); return; }
    if (event.key !== 'Tab') return;
    const focusable = [...sidebar.value.querySelectorAll('a[href], button:not([disabled])')];
    const first = focusable[0], last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
async function logout() { closeMobileMenu(false); await authStore.logout(); router.push('/login'); }
function onChanged() { loadPendingCount(); }

watch(() => route.fullPath, () => { closeMobileMenu(false); loadPendingCount(); });
onMounted(() => { loadPendingCount(); window.addEventListener('contact-inquiries:changed', onChanged); });
onBeforeUnmount(() => { document.body.style.overflow = previousOverflow; window.removeEventListener('contact-inquiries:changed', onChanged); });
</script>
