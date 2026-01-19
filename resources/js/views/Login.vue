<template>
    <AppLayout>
        <div class="flex items-center justify-center min-h-[60vh] bg-gray-50 px-4">
            <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8">
                <h2 class="text-2xl font-bold text-center mb-6">Iniciar Sesión</h2>
                <form @submit.prevent="handleLogin" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input v-model="form.email" type="email" required class="mt-1 block w-full border rounded-md shadow-sm py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Contraseña</label>
                        <input v-model="form.password" type="password" required class="mt-1 block w-full border rounded-md shadow-sm py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div v-if="error" class="text-red-600 text-sm font-semibold">{{ error }}</div>

                    <button type="submit" :disabled="loading" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50">
                         {{ loading ? 'Cargando...' : 'Ingresar' }}
                    </button>
                    
                    <div class="text-center text-sm">
                        ¿No tienes cuenta? <router-link to="/register" class="text-blue-600 hover:text-blue-500 font-bold">Regístrate</router-link>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useRouter, useRoute } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = ref({ email: '', password: '' });
const loading = ref(false);
const error = ref('');

const handleLogin = async () => {
    loading.value = true;
    error.value = '';
    try {
        await authStore.login(form.value);
        const redirect = route.query.redirect || '/';
        router.push(redirect);
    } catch (e) {
        error.value = e.response?.data?.message || 'Error al iniciar sesión';
    } finally {
        loading.value = false;
    }
};
</script>
