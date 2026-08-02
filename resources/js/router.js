import { createRouter, createWebHistory } from 'vue-router';
import Home from '@/views/Home.vue';
import Catalog from '@/views/Catalog.vue';
import ProductDetail from '@/views/ProductDetail.vue';
import Cart from '@/views/Cart.vue';
import Checkout from '@/views/Checkout.vue';
import Login from '@/views/Login.vue';
import Register from '@/views/Register.vue';
import Profile from '@/views/Profile.vue';
const Orders = () => import('./views/Orders.vue');
const OrderSuccess = () => import('./views/OrderSuccess.vue');
const PaymentReturn = () => import('./views/PaymentReturn.vue');
const OrderTracking = () => import('./views/OrderTracking.vue');
import About from '@/views/About.vue';
import Contact from '@/views/Contact.vue';
const AdminDashboard = () => import('./views/admin/Dashboard.vue');
import AdminProducts from '@/views/admin/Products.vue';
// import AdminOrders from '@/views/admin/Orders.vue'; // Need to create or reuse
import AdminSliders from '@/views/admin/Sliders.vue';

const routes = [
    { path: '/', component: Home },
    { path: '/catalog', name: 'Catalog', component: Catalog },
    { path: '/product/:slug', name: 'ProductDetail', component: ProductDetail },
    { path: '/cart', component: Cart },
    { path: '/checkout', component: Checkout },
    { path: '/login', component: Login },
    { path: '/register', component: Register },
    { path: '/profile', component: Profile, meta: { requiresAuth: true } },
    { path: '/orders', name: 'Orders', component: Orders, meta: { requiresAuth: true } },
    { path: '/orders/payment-return', name: 'PaymentReturn', component: PaymentReturn, meta: { requiresAuth: true } },
    { path: '/orders/success/:id', name: 'OrderSuccess', component: OrderSuccess, meta: { requiresAuth: true } },
    { path: '/orders/:id/tracking', name: 'OrderTracking', component: OrderTracking, meta: { requiresAuth: true } },
    { path: '/about', name: 'About', component: About },
    { path: '/contact', component: Contact },

    // Admin Routes
    { path: '/admin/dashboard', component: AdminDashboard, meta: { requiresAdmin: true } },
    { path: '/admin/contact-inquiries', component: () => import('@/views/admin/ContactInquiries.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/products', component: AdminProducts, meta: { requiresAdmin: true } },
    { path: '/admin/branches', component: () => import('@/views/admin/Branches.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/warehouses', component: () => import('@/views/admin/Warehouses.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/inventory', component: () => import('@/views/admin/Inventory.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/categories', component: () => import('@/views/admin/Categories.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/brands', component: () => import('@/views/admin/Brands.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/news', component: () => import('@/views/admin/News.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/sliders', component: AdminSliders, meta: { requiresAdmin: true } },
    { path: '/admin/orders', component: () => import('@/views/admin/Orders.vue'), meta: { requiresAdmin: true } },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) return new Promise(resolve => setTimeout(() => resolve(savedPosition), 150));
        if (to.name === 'Catalog' && from.name === 'ProductDetail') {
            const stored = sessionStorage.getItem(`catalog:scroll:${to.fullPath}`);
            if (stored) return new Promise(resolve => setTimeout(() => resolve({ top: Number(stored) }), 200));
        }
        if (to.name === 'Catalog' && from.name === 'Catalog' && to.query.page !== from.query.page) {
            return new Promise(resolve => setTimeout(() => resolve({ el: '#catalog-results', top: 100 }), 150));
        }
        return { top: 0 };
    },
});

import { useAuthStore } from './stores/auth';

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();

    // Check if user is logged in, if not try to fetch user if token exists
    if (!authStore.user && authStore.token) {
        await authStore.fetchUser();
    }

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        next({ path: '/login', query: { redirect: to.fullPath } });
    } else if (to.meta.requiresAdmin) {
        if (!authStore.isAuthenticated) {
            next({ path: '/login', query: { redirect: to.fullPath } });
        } else if (!authStore.isAdmin) {
            next('/');
        } else {
            next();
        }
    } else {
        next();
    }
});

export default router;
