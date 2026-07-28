<template>
    <div class="flex h-full bg-slate-950">
        <!-- Sidebar -->
        <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col">
            <div class="px-6 py-5 border-b border-slate-800">
                <h1 class="text-xl font-bold text-emerald-400">⚡ Xerex Panel</h1>
                <p class="text-xs text-slate-500 mt-1">Edge Proxy & CDN Manager</p>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1">
                <router-link
                    v-for="link in links"
                    :key="link.to"
                    :to="link.to"
                    v-slot="{ isActive }"
                    custom
                >
                    <a
                        @click="$router.push(link.to)"
                        :class="[
                            'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium cursor-pointer',
                            isActive
                                ? 'bg-emerald-500/10 text-emerald-400'
                                : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
                        ]"
                    >
                        <span class="text-lg">{{ link.icon }}</span>
                        <span>{{ link.label }}</span>
                    </a>
                </router-link>
            </nav>

            <div class="p-4 border-t border-slate-800">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 font-semibold">
                        {{ initials }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate">{{ auth.user?.name }}</p>
                        <p class="text-xs text-slate-500 truncate">{{ auth.user?.email }}</p>
                    </div>
                </div>
                <button @click="onLogout" class="btn-secondary w-full text-xs">Logout</button>
            </div>
        </aside>

        <!-- Main -->
        <main class="flex-1 overflow-y-auto">
            <header class="h-16 border-b border-slate-800 px-8 flex items-center justify-between bg-slate-950/50 backdrop-blur sticky top-0 z-10">
                <div>
                    <h2 class="text-lg font-semibold">{{ pageTitle }}</h2>
                </div>
                <div class="flex items-center gap-3">
                    <span class="badge-info">{{ auth.roles.join(', ') || 'user' }}</span>
                </div>
            </header>
            <div class="p-8">
                <router-view />
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const links = [
    { to: '/dashboard',   label: 'Dashboard',   icon: '📊' },
    { to: '/edge-servers',label: 'Edge Servers',icon: '🌐' },
    { to: '/origins',     label: 'Origins',     icon: '🖥️' },
    { to: '/domains',     label: 'Domains',     icon: '🔗' },
    { to: '/proxy-rules', label: 'Proxy Rules', icon: '⚙️' },
];

const pageTitle = computed(() => {
    const map = {
        dashboard: 'Dashboard',
        'edge-servers': 'Edge Servers',
        origins: 'Origin Servers',
        domains: 'Domains',
        'proxy-rules': 'Proxy Rules',
    };
    return map[route.name] || 'Xerex Panel';
});

const initials = computed(() => {
    const name = auth.user?.name || '?';
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
});

function onLogout() {
    auth.logout();
    router.push({ name: 'login' });
}
</script>
