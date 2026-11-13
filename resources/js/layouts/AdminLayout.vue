<template>
    <div class="admin-shell flex min-h-screen bg-slate-100" :class="{ 'sidebar-collapsed': isCollapsed && !isMobileViewport }">
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
                <router-link :to="isTreasury ? '/treasury/payments' : '/admin/dashboard'" class="rounded text-xl font-black focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-400" :aria-label="isCollapsed && !isMobileViewport ? 'LubriStore Admin' : undefined" @click="closeMobileMenu"><span v-if="!isCollapsed || isMobileViewport">Lubri<span class="text-blue-400">Store</span> {{ isTreasury ? 'Tesorería' : 'Admin' }}</span><span v-else class="text-blue-400" aria-hidden="true">LS</span></router-link>
                <button ref="closeButton" type="button" class="flex h-11 w-11 items-center justify-center rounded-lg text-slate-300 hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400 md:hidden" aria-label="Cerrar menú administrativo" @click="closeMobileMenu"><i class="pi pi-times" aria-hidden="true"></i></button>
                <button v-if="!isMobileViewport" type="button" class="collapse-toggle flex h-10 w-10 items-center justify-center rounded-lg text-slate-300 hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400" :aria-label="isCollapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'" :title="isCollapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'" @click="toggleCollapsed"><i :class="isCollapsed ? 'pi pi-angle-right' : 'pi pi-angle-left'" aria-hidden="true"></i></button>
            </div>

            <nav class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto px-3 py-3" aria-label="Navegación administrativa">
                <router-link v-for="item in directNavigation" :key="item.to" :to="item.to" active-class="bg-slate-800 text-blue-300 border-blue-400" class="admin-nav-link mb-1 flex min-h-10 items-center rounded-lg border-l-2 border-transparent px-3 py-2 text-sm font-semibold text-slate-300 transition hover:bg-slate-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400" :class="{ 'justify-center px-2': isSidebarCollapsed }" :data-label="item.label" :aria-label="item.label" :title="isSidebarCollapsed ? item.label : undefined" :aria-current="isRouteActive(item.to) ? 'page' : undefined" @click="closeMobileMenu">
                    <i :class="[item.icon, { 'mr-0': isSidebarCollapsed }]" class="mr-3 w-5 shrink-0 text-center" aria-hidden="true"></i><span :class="{ 'sr-only': isSidebarCollapsed }">{{ item.label }}</span>
                    <span v-if="!isTreasury && item.to === '/admin/contact-inquiries' && pendingCount > 0" class="ml-auto flex min-h-6 min-w-6 items-center justify-center rounded-full bg-red-600 px-1.5 text-xs font-black text-white" :aria-label="`${pendingCount} consultas pendientes o en atención`">{{ pendingCount > 99 ? '99+' : pendingCount }}</span>
                </router-link>

                <section v-for="group in visibleAdminGroups" :key="group.id" class="mb-1">
                    <button
                        :id="`admin-group-button-${group.id}`"
                        type="button"
                        class="admin-group-button flex min-h-10 w-full items-center rounded-lg border-l-2 px-3 py-2 text-left text-sm font-semibold transition hover:bg-slate-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400"
                        :class="[
                            isSidebarCollapsed ? 'justify-center px-2' : '',
                            activeGroupId === group.id ? 'border-blue-400 bg-slate-800/80 text-white' : 'border-transparent text-slate-300',
                        ]"
                        :aria-label="group.label"
                        :title="isSidebarCollapsed ? group.label : undefined"
                        :aria-expanded="isGroupExpanded(group.id)"
                        :aria-controls="`admin-submenu-${group.id}`"
                        :aria-current="activeGroupId === group.id ? 'location' : undefined"
                        @click="toggleGroup(group.id)"
                    >
                        <i :class="[group.icon, { 'mr-0': isSidebarCollapsed }]" class="mr-3 w-5 shrink-0 text-center" aria-hidden="true"></i>
                        <span class="min-w-0 flex-1 truncate" :class="{ 'sr-only': isSidebarCollapsed }">{{ group.label }}<span v-if="activeGroupId === group.id" class="sr-only">, sección actual</span></span>
                        <i v-if="!isSidebarCollapsed" class="pi pi-chevron-down ml-auto text-xs transition-transform duration-150 motion-reduce:transition-none" :class="{ 'rotate-180': isGroupExpanded(group.id) }" aria-hidden="true"></i>
                    </button>

                    <Transition name="admin-submenu">
                        <div
                            :id="`admin-submenu-${group.id}`"
                            v-show="isGroupExpanded(group.id)"
                            class="admin-submenu ml-2 border-l border-slate-700 pl-2"
                            :aria-labelledby="`admin-group-button-${group.id}`"
                        >
                            <router-link
                                v-for="item in group.children"
                                :key="item.to"
                                :to="item.to"
                                active-class="bg-slate-800 text-blue-300 border-blue-400"
                                class="admin-nav-link mb-0.5 flex min-h-9 min-w-0 items-center rounded-md border-l-2 border-transparent px-3 py-1.5 text-sm font-medium text-slate-300 transition hover:bg-slate-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400"
                                :data-label="item.label"
                                :aria-label="item.label"
                                :aria-current="isRouteActive(item.to) ? 'page' : undefined"
                                @click="closeMobileMenu"
                            >
                                <i :class="item.icon" class="mr-2.5 w-4 shrink-0 text-center" aria-hidden="true"></i><span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                                <span v-if="item.to === '/admin/contact-inquiries' && pendingCount > 0" class="ml-auto flex min-h-6 min-w-6 items-center justify-center rounded-full bg-red-600 px-1.5 text-xs font-black text-white" :aria-label="`${pendingCount} consultas pendientes o en atención`">{{ pendingCount > 99 ? '99+' : pendingCount }}</span>
                            </router-link>
                        </div>
                    </Transition>
                </section>
            </nav>
            <div class="shrink-0 space-y-1 border-t border-slate-800 p-3">
                <router-link :to="profileNavigation.to" active-class="bg-slate-800 text-blue-300 border-blue-400" class="admin-nav-link flex min-h-10 items-center rounded-lg border-l-2 border-transparent px-3 py-2 text-sm font-semibold text-slate-300 transition hover:bg-slate-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-400" :class="{ 'justify-center px-2': isSidebarCollapsed }" :data-label="profileNavigation.label" :aria-label="profileNavigation.label" :title="isSidebarCollapsed ? profileNavigation.label : undefined" :aria-current="isRouteActive(profileNavigation.to) ? 'page' : undefined" @click="closeMobileMenu">
                    <i :class="[profileNavigation.icon, { 'mr-0': isSidebarCollapsed }]" class="mr-3 w-5 shrink-0 text-center" aria-hidden="true"></i><span :class="{ 'sr-only': isSidebarCollapsed }">{{ profileNavigation.label }}</span>
                </router-link>
                <button type="button" class="logout-button flex min-h-10 w-full items-center rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-300 transition hover:bg-red-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-400" :class="{ 'justify-center px-2': isSidebarCollapsed }" data-label="Cerrar sesión" aria-label="Cerrar sesión" :title="isSidebarCollapsed ? 'Cerrar sesión' : undefined" @click="logout"><i class="pi pi-sign-out mr-3 w-5 shrink-0 text-center" :class="{ 'mr-0': isSidebarCollapsed }" aria-hidden="true"></i><span :class="{ 'sr-only': isSidebarCollapsed }">Cerrar sesión</span></button>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <header class="flex min-h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm md:hidden">
                <span class="font-black text-slate-900">Panel administrativo</span>
                <button ref="menuButton" type="button" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" :aria-expanded="mobileOpen" aria-controls="admin-navigation" aria-label="Abrir menú administrativo" @click="openMobileMenu"><i class="pi pi-bars" aria-hidden="true"></i></button>
            </header>
            <main class="admin-main flex-1 overflow-x-hidden overflow-y-auto bg-slate-100 p-4 sm:p-6"><slot /></main>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
const isCollapsed = ref(false);
const isMobileViewport = ref(false);
let mediaQuery;
let previousOverflow = '';
const SIDEBAR_STORAGE_KEY = 'lubristore.admin.sidebar.collapsed';
const OPEN_GROUP_STORAGE_KEY = 'lubristore.admin.navigation.open-group';

const dashboardNavigation = [
    { label: 'Dashboard', to: '/admin/dashboard', icon: 'pi pi-home' },
];
const adminNavigationGroups = [
    { id: 'sales', label: 'Ventas', icon: 'pi pi-shopping-cart', children: [
        { label: 'Pedidos', to: '/admin/orders', icon: 'pi pi-shopping-cart' },
        { label: 'Consultas', to: '/admin/contact-inquiries', icon: 'pi pi-inbox' },
    ] },
    { id: 'catalog', label: 'Catálogo', icon: 'pi pi-box', children: [
        { label: 'Productos', to: '/admin/products', icon: 'pi pi-box' },
        { label: 'Opiniones', to: '/admin/product-reviews', icon: 'pi pi-star' },
        { label: 'Categorías', to: '/admin/categories', icon: 'pi pi-tags' },
        { label: 'Marcas', to: '/admin/brands', icon: 'pi pi-bookmark' },
    ] },
    { id: 'inventory', label: 'Inventario y logística', icon: 'pi pi-warehouse', children: [
        { label: 'Inventario', to: '/admin/inventory', icon: 'pi pi-warehouse' },
        { label: 'Almacenes', to: '/admin/warehouses', icon: 'pi pi-building' },
        { label: 'Sedes', to: '/admin/branches', icon: 'pi pi-map-marker' },
        { label: 'Ubicaciones', to: '/admin/locations', icon: 'pi pi-globe' },
        { label: 'Tarifas de envío', to: '/admin/shipping', icon: 'pi pi-truck' },
        { label: 'Repartidores', to: '/admin/delivery-drivers', icon: 'pi pi-id-card' },
        { label: 'Vehículos', to: '/admin/delivery-vehicles', icon: 'pi pi-car' },
    ] },
    { id: 'payments', label: 'Pagos', icon: 'pi pi-credit-card', children: [
        { label: 'Métodos de pago', to: '/admin/payment-settings', icon: 'pi pi-credit-card' },
        { label: 'Cuentas receptoras', to: '/admin/treasury/receiving-accounts', icon: 'pi pi-wallet' },
    ] },
    { id: 'content', label: 'Contenido', icon: 'pi pi-images', children: [
        { label: 'Noticias', to: '/admin/news', icon: 'pi pi-megaphone' },
        { label: 'Sliders', to: '/admin/sliders', icon: 'pi pi-images' },
    ] },
    { id: 'analytics', label: 'Análisis', icon: 'pi pi-chart-bar', children: [
        { label: 'Reportes gerenciales', to: '/admin/reports/management', icon: 'pi pi-chart-bar' },
    ] },
    { id: 'administration', label: 'Administración', icon: 'pi pi-shield', children: [
        { label: 'Usuarios', to: '/admin/users', icon: 'pi pi-users' },
        { label: 'Auditoría', to: '/admin/auditoria', icon: 'pi pi-history' },
    ] },
];
const treasuryNavigation = [
    { label: 'Bandeja de pagos', to: '/treasury/payments', icon: 'pi pi-wallet' },
];
const profileNavigation = { label: 'Mi perfil', to: '/profile', icon: 'pi pi-user' };
const isAdmin = computed(() => authStore.isAdmin);
const isTreasury = computed(() => authStore.isTreasury);
const isSidebarCollapsed = computed(() => isCollapsed.value && !isMobileViewport.value);
const directNavigation = computed(() => isTreasury.value ? treasuryNavigation : isAdmin.value ? dashboardNavigation : []);
const visibleAdminGroups = computed(() => !isAdmin.value ? [] : adminNavigationGroups
    .map(group => ({ ...group, children: group.children.filter(item => item.to.startsWith('/admin/')) }))
    .filter(group => group.children.length > 0));
const visibleGroupSignature = computed(() => visibleAdminGroups.value.map(group => group.id).join('|'));
const activeGroupId = computed(() => visibleAdminGroups.value
    .find(group => group.children.some(item => isRouteActive(item.to)))?.id ?? null);
const openGroupId = ref(null);

function isGroupExpanded(groupId) { return !isSidebarCollapsed.value && openGroupId.value === groupId; }
function persistOpenGroup(groupId) {
    try {
        const visibleGroupId = groupId && visibleAdminGroups.value.some(group => group.id === groupId) ? groupId : null;
        if (visibleGroupId) sessionStorage.setItem(OPEN_GROUP_STORAGE_KEY, visibleGroupId);
        else sessionStorage.removeItem(OPEN_GROUP_STORAGE_KEY);
    } catch { /* El recuerdo del grupo es opcional. */ }
}
function toggleGroup(groupId) {
    if (isSidebarCollapsed.value) {
        isCollapsed.value = false;
        try { localStorage.setItem(SIDEBAR_STORAGE_KEY, 'false'); } catch { /* Preferencia opcional. */ }
        openGroupId.value = groupId;
        persistOpenGroup(groupId);
        return;
    }
    openGroupId.value = openGroupId.value === groupId ? null : groupId;
    persistOpenGroup(openGroupId.value);
}
async function loadPendingCount() {
    if (isTreasury.value) return;
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
function toggleCollapsed() { isCollapsed.value = !isCollapsed.value; try { localStorage.setItem(SIDEBAR_STORAGE_KEY, String(isCollapsed.value)); } catch { /* Preferencia opcional. */ } }
function isRouteActive(path) { return route.path === path || route.path.startsWith(`${path}/`); }
function reconcileActiveGroup() {
    const nextGroupId = activeGroupId.value;
    openGroupId.value = nextGroupId;
    persistOpenGroup(nextGroupId);
}
function updateViewport(event) { isMobileViewport.value = event.matches; if (event.matches) closeMobileMenu(false); }
function handleSidebarKeydown(event) {
    if (!mobileOpen.value) return;
    if (event.key === 'Escape') { event.preventDefault(); closeMobileMenu(); return; }
    if (event.key !== 'Tab') return;
    const focusable = [...sidebar.value.querySelectorAll('a[href], button:not([disabled])')].filter(element => element.getClientRects().length > 0);
    const first = focusable[0], last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
async function logout() { closeMobileMenu(false); await authStore.logout(); router.push('/login'); }
function onChanged() { loadPendingCount(); }

watch([() => route.path, visibleGroupSignature], reconcileActiveGroup, { immediate: true });
watch(() => route.fullPath, () => { closeMobileMenu(false); loadPendingCount(); });
onMounted(() => {
    try {
        const stored = localStorage.getItem(SIDEBAR_STORAGE_KEY);
        if (stored === 'true' || stored === 'false') isCollapsed.value = stored === 'true';
    } catch { /* Preferencia inválida o almacenamiento no disponible. */ }
    mediaQuery = window.matchMedia('(max-width: 1023px)'); isMobileViewport.value = mediaQuery.matches; mediaQuery.addEventListener?.('change', updateViewport); loadPendingCount(); window.addEventListener('contact-inquiries:changed', onChanged);
});
onBeforeUnmount(() => { document.body.style.overflow = previousOverflow; mediaQuery?.removeEventListener?.('change', updateViewport); window.removeEventListener('contact-inquiries:changed', onChanged); });
</script>

<style scoped>
.admin-shell aside { width: 18rem; transition: width 200ms ease, transform 200ms ease; }
.admin-shell.sidebar-collapsed aside { width: 5rem; }
.admin-shell.sidebar-collapsed .admin-nav-link { position: relative; }
.admin-shell.sidebar-collapsed .admin-nav-link:hover::after,
.admin-shell.sidebar-collapsed .admin-nav-link:focus-visible::after { content: attr(data-label); position: absolute; left: calc(100% + .5rem); top: 50%; z-index: 100; transform: translateY(-50%); white-space: nowrap; border-radius: .375rem; background: #0f172a; padding: .4rem .6rem; color: white; font-size: .75rem; pointer-events: none; box-shadow: 0 4px 12px rgb(15 23 42 / .25); }
.admin-shell.sidebar-collapsed .logout-button { position: relative; white-space: nowrap; }
.admin-shell.sidebar-collapsed .logout-button:hover::after,
.admin-shell.sidebar-collapsed .logout-button:focus-visible::after { content: attr(data-label); position: absolute; left: calc(100% + .5rem); top: 50%; z-index: 100; transform: translateY(-50%); white-space: nowrap; border-radius: .375rem; background: #0f172a; padding: .4rem .6rem; color: white; font-size: .75rem; pointer-events: none; box-shadow: 0 4px 12px rgb(15 23 42 / .25); }
.admin-shell.sidebar-collapsed .admin-group-button { position: relative; white-space: nowrap; }
.admin-shell.sidebar-collapsed .admin-group-button:hover::after,
.admin-shell.sidebar-collapsed .admin-group-button:focus-visible::after { content: attr(title); position: absolute; left: calc(100% + .5rem); top: 50%; z-index: 100; transform: translateY(-50%); white-space: nowrap; border-radius: .375rem; background: #0f172a; padding: .4rem .6rem; color: white; font-size: .75rem; pointer-events: none; box-shadow: 0 4px 12px rgb(15 23 42 / .25); }
.admin-submenu-enter-active, .admin-submenu-leave-active { transition: opacity 140ms ease, transform 140ms ease; }
.admin-submenu-enter-from, .admin-submenu-leave-to { opacity: 0; transform: translateY(-.25rem); }
@media (prefers-reduced-motion: reduce) { .admin-shell aside { transition: none; } }
@media (prefers-reduced-motion: reduce) { .admin-submenu-enter-active, .admin-submenu-leave-active { transition: none; } }
@media (max-width: 1023px) { .admin-shell aside { width: 18rem; } }
</style>
