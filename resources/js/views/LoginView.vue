<template>
    <div class="min-h-screen bg-slate-950 flex items-center justify-center px-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-emerald-400 mb-2">⚡ Xerex Panel</h1>
                <p class="text-slate-400">Sign in to manage your edge network</p>
            </div>

            <div class="card">
                <form @submit.prevent="onSubmit" class="space-y-4">
                    <div>
                        <label class="label">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="input"
                            placeholder="admin@example.com"
                            required
                            autofocus
                        />
                    </div>
                    <div>
                        <label class="label">Password</label>
                        <input
                            v-model="form.password"
                            type="password"
                            class="input"
                            placeholder="••••••••"
                            required
                        />
                    </div>
                    <div v-if="error" class="bg-rose-500/10 text-rose-400 text-sm p-3 rounded-lg">
                        {{ error }}
                    </div>
                    <button type="submit" :disabled="loading" class="btn-primary w-full">
                        {{ loading ? 'Signing in...' : 'Sign in' }}
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-slate-600 mt-6">
                Open-source · MIT License
            </p>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = reactive({ email: '', password: '' });
const loading = ref(false);
const error = ref('');

async function onSubmit() {
    loading.value = true;
    error.value = '';
    try {
        await auth.login(form.email, form.password);
        router.push(route.query.redirect || { name: 'dashboard' });
    } catch (e) {
        error.value = e.response?.data?.message || 'Invalid credentials';
    } finally {
        loading.value = false;
    }
}
</script>
