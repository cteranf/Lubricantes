import { defineStore } from 'pinia';
import api from '@/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: localStorage.getItem('token') || null,
    }),
    getters: {
        isAuthenticated: (state) => !!state.token,
        isAdmin: (state) => state.user?.role === 'admin',
    },
    actions: {
        async login(credentials) {
            const response = await api.post('/auth/login', credentials);
            this.token = response.data.access_token;
            this.user = response.data.user;
            localStorage.setItem('token', this.token);
            api.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
        },
        async register(data) {
            const response = await api.post('/auth/register', data);
            this.token = response.data.access_token;
            this.user = response.data.user;
            localStorage.setItem('token', this.token);
            api.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
        },
        async logout() {
            try {
                await api.post('/auth/logout');
            } catch (e) {
                console.error(e);
            }
            this.token = null;
            this.user = null;
            localStorage.removeItem('token');
            delete api.defaults.headers.common['Authorization'];
        },
        async fetchUser() {
            if (!this.token) return;
            try {
                const response = await api.get('/auth/user');
                this.user = response.data;
            } catch (e) {
                this.logout();
            }
        }
    }
});
