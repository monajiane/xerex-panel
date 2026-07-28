<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <input v-model="search" @input="fetchList" class="input max-w-sm" placeholder="Search domains..." />
            <button @click="openCreate" class="btn-primary">+ Add Domain</button>
        </div>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase">
                    <tr>
                        <th class="text-left px-5 py-3">Domain</th>
                        <th class="text-left px-5 py-3">DNS</th>
                        <th class="text-left px-5 py-3">SSL</th>
                        <th class="text-left px-5 py-3">CDN</th>
                        <th class="text-left px-5 py-3">Expires</th>
                        <th class="text-right px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in domains" :key="d.id" class="border-t border-slate-800 hover:bg-slate-800/30">
                        <td class="px-5 py-3 font-medium">{{ d.domain }}</td>
                        <td class="px-5 py-3">
                            <span :class="d.dns_status === 'active' ? 'badge-success' : 'badge-muted'">{{ d.dns_status }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span :class="sslClass(d.ssl_status)">{{ d.ssl_status }}</span>
                        </td>
                        <td class="px-5 py-3">{{ d.cdn_enabled ? '✅' : '—' }}</td>
                        <td class="px-5 py-3 text-slate-400 text-xs">{{ d.ssl_expires_at ? new Date(d.ssl_expires_at).toLocaleDateString() : '—' }}</td>
                        <td class="px-5 py-3 text-right">
                            <button @click="del(d)" class="text-rose-400 hover:underline text-xs">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!domains.length">
                        <td colspan="6" class="text-center py-8 text-slate-500">No domains yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50" @click.self="showCreate = false">
            <div class="bg-slate-900 rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-4">Add Domain</h3>
                <form @submit.prevent="create" class="space-y-3">
                    <div><label class="label">Domain name</label><input v-model="form.domain" class="input" placeholder="example.com" required /></div>
                    <div><label class="label">Registrar (optional)</label><input v-model="form.registrar" class="input" /></div>
                    <div class="flex items-center gap-2">
                        <input id="wild" v-model="form.wildcard" type="checkbox" class="rounded" />
                        <label for="wild" class="text-sm text-slate-300">Wildcard certificate (*.example.com)</label>
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

const domains = ref([]);
const search = ref('');
const showCreate = ref(false);
const form = reactive({ domain: '', registrar: '', wildcard: false });

function sslClass(s) {
    return {
        active: 'badge-success',
        pending: 'badge-muted',
        provisioning: 'badge-info',
        expiring: 'badge-warning',
        expired: 'badge-danger',
        error: 'badge-danger',
    }[s] || 'badge-muted';
}

async function fetchList() {
    const { data } = await axios.get('/domains', { params: { search: search.value } });
    domains.value = data.data || [];
}

function openCreate() {
    Object.assign(form, { domain: '', registrar: '', wildcard: false });
    showCreate.value = true;
}

async function create() {
    try {
        await axios.post('/domains', form);
        showCreate.value = false;
        await fetchList();
    } catch (e) {
        alert(e.response?.data?.message || 'Failed');
    }
}

async function del(d) {
    if (!confirm(`Delete ${d.domain}?`)) return;
    await axios.delete(`/domains/${d.id}`);
    await fetchList();
}

onMounted(fetchList);
</script>
