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
const OrderTracking = () => import('./views/OrderTracking.vue');
import About from '@/views/About.vue';
import Contact from '@/views/Contact.vue';
const AdminDashboard = () => import('./views/admin/Dashboard.vue');
import AdminProducts from '@/views/admin/Products.vue';
// import AdminOrders from '@/views/admin/Orders.vue'; // Need to create or reuse
import AdminSliders from '@/views/admin/Sliders.vue';

const routes = [
    { path: '/', component: Home },
    { path: '/catalog', component: Catalog },
    { path: '/product/:slug', component: ProductDetail },
    { path: '/cart', component: Cart },
    { path: '/checkout', component: Checkout },
    { path: '/login', component: Login },
    { path: '/register', component: Register },
    { path: '/profile', component: Profile },
    { path: '/orders', name: 'Orders', component: Orders },
    { path: '/orders/success/:id', name: 'OrderSuccess', component: OrderSuccess },
    { path: '/orders/:id/tracking', name: 'OrderTracking', component: OrderTracking },
    { path: '/about', name: 'About', component: About },
    { path: '/contact', component: Contact },

    // Admin Routes
    { path: '/admin/dashboard', component: AdminDashboard, meta: { requiresAdmin: true } },
    { path: '/admin/products', component: AdminProducts, meta: { requiresAdmin: true } },
    { path: '/admin/categories', component: () => import('@/views/admin/Categories.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/news', component: () => import('@/views/admin/News.vue'), meta: { requiresAdmin: true } },
    { path: '/admin/sliders', component: AdminSliders, meta: { requiresAdmin: true } },
    { path: '/admin/orders', component: () => import('@/views/admin/Orders.vue'), meta: { requiresAdmin: true } },
    {
        path: '/orders/success/:id',
        name: 'OrderSuccess',
        component: {
            template: `
                <div class="container mx-auto py-20 text-center">
                    <h1 class="text-4xl font-bold text-green-600 mb-4">¡Pedido Confirmado!</h1>
                    <p class="mb-6 text-xl">Gracias por tu compra. Te hemos enviado un correo con los detalles.</p>
                    <router-link to="/" class="text-blue-600 underline">Volver al inicio</router-link>
                </div>
            `
        }
    },
    // Admin routes can be added here
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

import { useAuthStore } from './stores/auth';

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();

    // Check if user is logged in, if not try to fetch user if token exists
    if (!authStore.user && authStore.token) {
        await authStore.fetchUser();
    }

    if (to.meta.requiresAdmin) {
        if (!authStore.isAuthenticated) {
            next('/login');
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
