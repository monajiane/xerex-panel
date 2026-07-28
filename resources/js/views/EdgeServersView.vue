<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <input v-model="search" @input="fetchList" class="input max-w-sm" placeholder="Search edge servers..." />
            <button @click="openCreate" class="btn-primary">+ Add Edge Server</button>
        </div>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase">
                    <tr>
                        <th class="text-left px-5 py-3">Name</th>
                        <th class="text-left px-5 py-3">Hostname</th>
                        <th class="text-left px-5 py-3">IP</th>
                        <th class="text-left px-5 py-3">Location</th>
                        <th class="text-left px-5 py-3">Status</th>
                        <th class="text-left px-5 py-3">CPU/RAM</th>
                        <th class="text-left px-5 py-3">Last Seen</th>
                        <th class="text-right px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="edge in edges" :key="edge.id" class="border-t border-slate-800 hover:bg-slate-800/30">
                        <td class="px-5 py-3 font-medium">{{ edge.name }}</td>
                        <td class="px-5 py-3 text-slate-400">{{ edge.hostname }}</td>
                        <td class="px-5 py-3 text-slate-400">{{ edge.ip_address }}</td>
                        <td class="px-5 py-3 text-slate-400">{{ edge.location || '—' }}</td>
                        <td class="px-5 py-3">
                            <span :class="statusClass(edge.status)">{{ edge.status }}</span>
                        </td>
                        <td class="px-5 py-3 text-slate-400">{{ edge.cpu_usage }}% / {{ edge.ram_usage }}%</td>
                        <td class="px-5 py-3 text-slate-500 text-xs">{{ formatTime(edge.last_seen_at) }}</td>
                        <td class="px-5 py-3 text-right">
                            <button @click="testConnection(edge)" class="text-sky-400 hover:underline text-xs">Test</button>
                            <button @click="rotateToken(edge)" class="text-amber-400 hover:underline text-xs ml-3">Rotate Token</button>
                            <button @click="del(edge)" class="text-rose-400 hover:underline text-xs ml-3">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!edges.length">
                        <td colspan="8" class="text-center py-8 text-slate-500">No edge servers yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create modal -->
        <div v-if="showCreate" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50" @click.self="showCreate = false">
            <div class="bg-slate-900 rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-4">Add Edge Server</h3>
                <form @submit.prevent="create" class="space-y-3">
                    <div><label class="label">Name</label><input v-model="form.name" class="input" required /></div>
                    <div><label class="label">Hostname</label><input v-model="form.hostname" class="input" required /></div>
                    <div><label class="label">IP address</label><input v-model="form.ip_address" class="input" required /></div>
                    <div><label class="label">Location</label><input v-model="form.location" class="input" placeholder="e.g. Tehran, IR" /></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">Country (ISO2)</label><input v-model="form.country_code" class="input" maxlength="2" /></div>
                        <div><label class="label">Region</label><input v-model="form.region" class="input" /></div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showCreate = false" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="newToken" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50" @click.self="newToken = null">
            <div class="bg-slate-900 rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-2 text-amber-400">⚠️ Save this token now</h3>
                <p class="text-slate-400 text-sm mb-3">This token will only be shown once.</p>
                <pre class="bg-slate-950 p-3 rounded text-xs overflow-x-auto">{{ newToken }}</pre>
                <button @click="newToken = null" class="btn-primary w-full mt-4">I have saved it</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const edges = ref([]);
const search = ref('');
const showCreate = ref(false);
const newToken = ref(null);
const form = reactive({ name: '', hostname: '', ip_address: '', location: '', country_code: '', region: '' });

function statusClass(s) {
    return {
        online: 'badge-success',
        offline: 'badge-danger',
        degraded: 'badge-warning',
        maintenance: 'badge-info',
        provisioning: 'badge-muted',
    }[s] || 'badge-muted';
}

function formatTime(t) {
    if (!t) return '—';
    return new Date(t).toLocaleString();
}

async function fetchList() {
    const { data } = await axios.get('/edge-servers', { params: { search: search.value } });
    edges.value = data.data || [];
}

function openCreate() {
    Object.assign(form, { name: '', hostname: '', ip_address: '', location: '', country_code: '', region: '' });
    showCreate.value = true;
}

async function create() {
    try {
        const { data } = await axios.post('/edge-servers', form);
        newToken.value = data.token;
        showCreate.value = false;
        await fetchList();
    } catch (e) {
        alert(e.response?.data?.message || 'Failed');
    }
}

async function testConnection(edge) {
    const { data } = await axios.post(`/edge-servers/${edge.id}/test`);
    alert(data.success ? 'Connection OK' : `Failed: ${data.error}`);
}

async function rotateToken(edge) {
    if (!confirm('Rotate agent token? Existing agents will need re-auth.')) return;
    const { data } = await axios.post(`/edge-servers/${edge.id}/rotate-token`);
    newToken.value = data.token;
}

async function del(edge) {
    if (!confirm(`Delete ${edge.name}?`)) return;
    await axios.delete(`/edge-servers/${edge.id}`);
    await fetchList();
}

onMounted(fetchList);
</script>
