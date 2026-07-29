<template>
    <div class="flex h-full bg-slate-950">
        <!-- Sidebar -->
        <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col">
            <div class="px-6 py-5 border-b border-slate-800">
                <h1 class="text-xl font-bold text-emerald-400">⚡ Xerex Panel</h1>
                <p class="text-xs text-slate-500 mt-1">Edge Proxy & CDN Manager</p>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
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
                        <span v-html="link.icon" class="w-5 h-5 flex items-center justify-center"></span>
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
                    <span v-if="realtime.connected" class="flex items-center gap-1.5 text-xs text-emerald-400">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        live
                    </span>
                </div>
            </header>
            <div class="p-8">
                <router-view />
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useRealtimeStore } from '../stores/realtime';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const realtime = useRealtimeStore();

const links = [
    { to: '/dashboard',       label: 'Dashboard',        icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>' },
    { to: '/edge-servers',    label: 'Edge Servers',     icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01"/></svg>' },
    { to: '/origins',         label: 'Origins',          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a4 4 0 014-4h4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>' },
    { to: '/failover-groups', label: 'Failover Groups',  icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>' },
    { to: '/domains',         label: 'Domains',          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>' },
    { to: '/proxy-rules',     label: 'Proxy Rules',      icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' },
    { to: '/dns',             label: 'DNS',              icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
    { to: '/ssl',             label: 'SSL',              icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>' },
    { to: '/analytics',       label: 'Analytics',        icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>' },
    { to: '/billing',         label: 'Billing',          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>' },
    { to: '/security/waf',         label: 'WAF Rules',     icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>' },
    { to: '/security/ip-lists',    label: 'IP Lists',      icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M3 4v16M21 4v16M7 8h10M7 12h10M7 16h6"/></svg>' },
    { to: '/security/rate-limits', label: 'Rate Limits',   icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
];

const pageTitle = computed(() => {
    const map = {
        dashboard: 'Dashboard',
        'edge-servers': 'Edge Servers',
        origins: 'Origin Servers',
        'failover-groups': 'Failover Groups',
        domains: 'Domains',
        'proxy-rules': 'Proxy Rules',
        'dns-zones': 'DNS Zones',
        ssl: 'SSL Certificates',
        analytics: 'Traffic Analytics',
        billing: 'Billing & Quotas',
        'security.waf': 'WAF Rules',
        'security.ip-lists': 'IP Allow / Block Lists',
        'security.rate-limits': 'Rate Limit Policies',
    };
    return map[route.name] || 'Xerex Panel';
});

const initials = computed(() => {
    const name = auth.user?.name || '?';
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
});

function onLogout() {
    realtime.disconnect();
    auth.logout();
    router.push({ name: 'login' });
}

onMounted(() => {
    if (auth.token) {
        realtime.connect();
    }
});
</script>
