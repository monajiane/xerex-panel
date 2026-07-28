<template>
    <div>
        <div v-if="loading" class="text-slate-400">Loading...</div>

        <div v-else-if="stats" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <StatCard
                title="Edge Servers"
                :value="`${stats.edges.online} / ${stats.edges.total}`"
                :subtitle="`${stats.edges.online} online`"
                icon="🌐"
                tone="emerald"
            />
            <StatCard
                title="Origin Servers"
                :value="`${stats.origins.up} / ${stats.origins.total}`"
                :subtitle="`${stats.origins.up} healthy`"
                icon="🖥️"
                tone="sky"
            />
            <StatCard
                title="Domains"
                :value="stats.domains.total"
                :subtitle="`${stats.domains.ssl_active} with active SSL`"
                icon="🔗"
                tone="amber"
            />
            <StatCard
                title="Active Proxy Rules"
                :value="stats.rules.active"
                :subtitle="`${formatBytes(stats.traffic_24h.bytes)} in last 24h`"
                icon="⚙️"
                tone="violet"
            />
        </div>

        <div v-if="stats" class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
            <div class="card">
                <h3 class="font-semibold mb-4">Traffic (Last 24h)</h3>
                <div class="text-3xl font-bold text-emerald-400">{{ formatBytes(stats.traffic_24h.bytes) }}</div>
                <div class="text-slate-400 text-sm mt-1">{{ stats.traffic_24h.requests.toLocaleString() }} requests</div>
            </div>

            <div class="card">
                <h3 class="font-semibold mb-4">Recent Health Checks</h3>
                <div v-if="!healthChecks.length" class="text-slate-500 text-sm">No checks yet.</div>
                <ul v-else class="space-y-2 max-h-64 overflow-y-auto">
                    <li
                        v-for="check in healthChecks.slice(0, 10)"
                        :key="check.id"
                        class="flex items-center justify-between text-sm border-b border-slate-800 pb-2"
                    >
                        <span class="truncate">{{ check.target }}</span>
                        <span
                            :class="{
                                'badge-success': check.status === 'up',
                                'badge-danger': check.status === 'down',
                                'badge-warning': check.status === 'degraded',
                            }"
                        >
                            {{ check.status }} · {{ check.latency_ms || '-' }}ms
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import StatCard from '../components/StatCard.vue';

const stats = ref(null);
const healthChecks = ref([]);
const loading = ref(true);

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const k = 1024;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + units[i];
}

onMounted(async () => {
    try {
        const [s, h] = await Promise.all([
            axios.get('/dashboard/stats'),
            axios.get('/dashboard/health-checks'),
        ]);
        stats.value = s.data;
        healthChecks.value = h.data;
    } finally {
        loading.value = false;
    }
});
</script>
