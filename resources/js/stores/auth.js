import { defineStore } from 'pinia';
import axios from 'axios';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('xerex_token') || null,
        user: JSON.parse(localStorage.getItem('xerex_user') || 'null'),
        permissions: [],
        roles: [],
    }),

    getters: {
        isAuthenticated: (s) => !!s.token,
        isAdmin: (s) => s.roles.includes('admin') || s.user?.is_admin === true,
    },

    actions: {
        async login(email, password) {
            const { data } = await axios.post('/auth/login', { email, password });
            this.token = data.token;
            this.user = data.user;
            this.roles = data.roles || [];
            this.permissions = data.permissions || [];
            localStorage.setItem('xerex_token', data.token);
            localStorage.setItem('xerex_user', JSON.stringify(data.user));
            axios.defaults.headers.common['Authorization'] = `Bearer ${data.token}`;
            return data;
        },

        async fetchMe() {
            const { data } = await axios.get('/auth/me');
            this.user = data.user;
            this.roles = data.roles || [];
            this.permissions = data.permissions || [];
            localStorage.setItem('xerex_user', JSON.stringify(data.user));
            return data;
        },

        logout() {
            this.token = null;
            this.user = null;
            this.roles = [];
            this.permissions = [];
            localStorage.removeItem('xerex_token');
            localStorage.removeItem('xerex_user');
            delete axios.defaults.headers.common['Authorization'];
        },

        can(permission) {
            return this.permissions.includes(permission) || this.isAdmin;
        },
    },
});
