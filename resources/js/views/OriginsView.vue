<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <input v-model="search" @input="fetchList" class="input max-w-sm" placeholder="Search origins..." />
            <button @click="openCreate" class="btn-primary">+ Add Origin</button>
        </div>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase">
                    <tr>
                        <th class="text-left px-5 py-3">Name</th>
                        <th class="text-left px-5 py-3">Host:Port</th>
                        <th class="text-left px-5 py-3">Protocol</th>
                        <th class="text-left px-5 py-3">Health</th>
                        <th class="text-left px-5 py-3">Status</th>
                        <th class="text-right px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in origins" :key="o.id" class="border-t border-slate-800 hover:bg-slate-800/30">
                        <td class="px-5 py-3 font-medium">{{ o.name }}</td>
                        <td class="px-5 py-3 text-slate-400">{{ o.host }}:{{ o.port }}</td>
                        <td class="px-5 py-3"><span class="badge-info">{{ o.protocol }}</span></td>
                        <td class="px-5 py-3">
                            <span :class="healthClass(o.health_status)">{{ o.health_status }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span :class="o.is_active ? 'badge-success' : 'badge-muted'">{{ o.is_active ? 'active' : 'disabled' }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button @click="test(o)" class="text-sky-400 hover:underline text-xs">Test</button>
                            <button @click="del(o)" class="text-rose-400 hover:underline text-xs ml-3">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!origins.length">
                        <td colspan="6" class="text-center py-8 text-slate-500">No origin servers yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50" @click.self="showCreate = false">
            <div class="bg-slate-900 rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-4">Add Origin Server</h3>
                <form @submit.prevent="create" class="space-y-3">
                    <div><label class="label">Name</label><input v-model="form.name" class="input" required /></div>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2"><label class="label">Host</label><input v-model="form.host" class="input" required /></div>
                        <div><label class="label">Port</label><input v-model.number="form.port" type="number" class="input" required /></div>
                    </div>
                    <div>
                        <label class="label">Protocol</label>
                        <select v-model="form.protocol" class="input">
                            <option value="http">http</option>
                            <option value="https">https</option>
                            <option value="grpc">grpc</option>
                            <option value="tcp">tcp</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Health check path</label>
                        <input v-model="form.health_check_path" class="input" placeholder="/health" />
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

const origins = ref([]);
const search = ref('');
const showCreate = ref(false);
const form = reactive({ name: '', host: '', port: 80, protocol: 'http', health_check_path: '/health' });

function healthClass(s) {
    return { up: 'badge-success', down: 'badge-danger' }[s] || 'badge-muted';
}

async function fetchList() {
    const { data } = await axios.get('/origin-servers', { params: { search: search.value } });
    origins.value = data.data || [];
}

function openCreate() {
    Object.assign(form, { name: '', host: '', port: 80, protocol: 'http', health_check_path: '/health' });
    showCreate.value = true;
}

async function create() {
    try {
        await axios.post('/origin-servers', form);
        showCreate.value = false;
        await fetchList();
    } catch (e) {
        alert(e.response?.data?.message || 'Failed');
    }
}

async function test(o) {
    const { data } = await axios.post(`/origin-servers/${o.id}/test`);
    alert(data.success ? `OK (${data.latency_ms}ms)` : `Failed: ${data.error}`);
}

async function del(o) {
    if (!confirm(`Delete ${o.name}?`)) return;
    await axios.delete(`/origin-servers/${o.id}`);
    await fetchList();
}

onMounted(fetchList);
</script>
