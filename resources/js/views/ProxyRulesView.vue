<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <div class="flex gap-2">
                <input v-model="search" @input="fetchList" class="input max-w-xs" placeholder="Search rules..." />
                <select v-model="typeFilter" @change="fetchList" class="input max-w-xs">
                    <option value="">All types</option>
                    <option value="http">HTTP</option>
                    <option value="websocket">WebSocket</option>
                    <option value="tcp">TCP</option>
                    <option value="grpc">gRPC</option>
                </select>
            </div>
            <button @click="openCreate" class="btn-primary">+ Add Proxy Rule</button>
        </div>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase">
                    <tr>
                        <th class="text-left px-5 py-3">Domain</th>
                        <th class="text-left px-5 py-3">Path</th>
                        <th class="text-left px-5 py-3">Type</th>
                        <th class="text-left px-5 py-3">Edge</th>
                        <th class="text-left px-5 py-3">Origin</th>
                        <th class="text-left px-5 py-3">Status</th>
                        <th class="text-right px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rules" :key="r.id" class="border-t border-slate-800 hover:bg-slate-800/30">
                        <td class="px-5 py-3 font-medium">{{ r.domain?.domain }}</td>
                        <td class="px-5 py-3 text-slate-400 font-mono text-xs">{{ r.path }}</td>
                        <td class="px-5 py-3"><span class="badge-info">{{ r.type }}</span></td>
                        <td class="px-5 py-3 text-slate-400">{{ r.edge_server?.name }}</td>
                        <td class="px-5 py-3 text-slate-400">{{ r.origin_server?.name }}</td>
                        <td class="px-5 py-3">
                            <span :class="r.enabled ? 'badge-success' : 'badge-muted'">
                                {{ r.enabled ? 'enabled' : 'disabled' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button @click="toggle(r)" class="text-sky-400 hover:underline text-xs">
                                {{ r.enabled ? 'Disable' : 'Enable' }}
                            </button>
                            <button @click="del(r)" class="text-rose-400 hover:underline text-xs ml-3">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!rules.length">
                        <td colspan="7" class="text-center py-8 text-slate-500">No proxy rules yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50 overflow-y-auto" @click.self="showCreate = false">
            <div class="bg-slate-900 rounded-2xl p-6 w-full max-w-lg my-8">
                <h3 class="text-lg font-semibold mb-4">Add Proxy Rule</h3>
                <form @submit.prevent="create" class="space-y-3">
                    <div>
                        <label class="label">Domain</label>
                        <select v-model="form.domain_id" class="input" required>
                            <option value="">— select —</option>
                            <option v-for="d in domains" :key="d.id" :value="d.id">{{ d.domain }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label">Edge server</label>
                            <select v-model="form.edge_server_id" class="input" required>
                                <option value="">— select —</option>
                                <option v-for="e in edges" :key="e.id" :value="e.id">{{ e.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Origin server</label>
                            <select v-model="form.origin_server_id" class="input" required>
                                <option value="">— select —</option>
                                <option v-for="o in origins" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label">Type</label>
                            <select v-model="form.type" class="input">
                                <option value="http">HTTP</option>
                                <option value="websocket">WebSocket</option>
                                <option value="tcp">TCP</option>
                                <option value="grpc">gRPC</option>
                                <option value="sse">SSE</option>
                                <option value="redirect">Redirect</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Path match</label>
                            <select v-model="form.path_match_type" class="input">
                                <option value="prefix">Prefix</option>
                                <option value="exact">Exact</option>
                                <option value="regex">Regex</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="label">Path</label>
                        <input v-model="form.path" class="input" placeholder="/ or /api" required />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">Priority</label><input v-model.number="form.priority" type="number" class="input" /></div>
                        <div><label class="label">Weight</label><input v-model.number="form.weight" type="number" class="input" /></div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showCreate = false" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const rules = ref([]);
const domains = ref([]);
const edges = ref([]);
const origins = ref([]);
const search = ref('');
const typeFilter = ref('');
const showCreate = ref(false);
const form = reactive({
    domain_id: '', edge_server_id: '', origin_server_id: '',
    type: 'http', path: '/', path_match_type: 'prefix',
    priority: 100, weight: 100,
});

async function fetchList() {
    const { data } = await axios.get('/proxy-rules', { params: { search: search.value, type: typeFilter.value } });
    rules.value = data.data || [];
}

async function fetchOptions() {
    const [d, e, o] = await Promise.all([
        axios.get('/domains'),
        axios.get('/edge-servers'),
        axios.get('/origin-servers'),
    ]);
    domains.value = d.data.data || [];
    edges.value = e.data.data || [];
    origins.value = o.data.data || [];
}

function openCreate() {
    Object.assign(form, {
        domain_id: '', edge_server_id: '', origin_server_id: '',
        type: 'http', path: '/', path_match_type: 'prefix',
        priority: 100, weight: 100,
    });
    showCreate.value = true;
}

async function create() {
    try {
        await axios.post('/proxy-rules', form);
        showCreate.value = false;
        await fetchList();
    } catch (e) {
        alert(e.response?.data?.message || 'Failed');
    }
}

async function toggle(r) {
    await axios.post(`/proxy-rules/${r.id}/toggle`);
    await fetchList();
}

async function del(r) {
    if (!confirm('Delete rule?')) return;
    await axios.delete(`/proxy-rules/${r.id}`);
    await fetchList();
}

onMounted(async () => {
    await fetchOptions();
    await fetchList();
});
</script>
