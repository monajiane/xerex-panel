<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-100">Rate Limit Policies</h2>
                <p class="text-sm text-slate-400 mt-1">
                    Cap how many requests a given key (IP / user / path / domain) can make per window.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="openForm()" class="btn-primary text-sm">+ New policy</button>
            </div>
        </header>

        <div class="card flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Limit type</label>
                <select v-model="filters.limit_type" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    <option value="ip">Per IP</option>
                    <option value="user">Per user</option>
                    <option value="path">Per path</option>
                    <option value="domain">Per domain</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Scope</label>
                <select v-model="filters.scope_type" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    <option value="global">Global</option>
                    <option value="domain">Domain</option>
                    <option value="edge">Edge</option>
                    <option value="user">User</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Active</label>
                <select v-model="filters.is_active" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    <option value="1">Active</option>
                    <option value="0">Disabled</option>
                </select>
            </div>
        </div>

        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="error" class="card border border-rose-700 text-rose-300 text-sm">{{ error }}</div>
        <div v-else-if="!policies.length" class="card text-slate-400 text-sm">
            No policies yet. Run <code>php artisan xerex:security:seed-rate-limits</code> to install the default set, or click “+ New policy”.
        </div>
        <div v-else class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 uppercase border-b border-slate-800">
                        <th class="py-2 pr-3">Name</th>
                        <th class="py-2 pr-3">Limit</th>
                        <th class="py-2 pr-3">Window</th>
                        <th class="py-2 pr-3">Effective</th>
                        <th class="py-2 pr-3">Bucket</th>
                        <th class="py-2 pr-3">Scope</th>
                        <th class="py-2 pr-3">Action</th>
                        <th class="py-2 pr-3 text-right">Status</th>
                        <th class="py-2 pr-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in policies" :key="p.id" class="border-b border-slate-800/60 hover:bg-slate-900/40">
                        <td class="py-2 pr-3 text-slate-100 font-medium">{{ p.name }}</td>
                        <td class="py-2 pr-3 text-slate-300 font-mono">{{ p.max_requests }}</td>
                        <td class="py-2 pr-3 text-slate-400 font-mono">{{ formatWindow(p.window_seconds) }}</td>
                        <td class="py-2 pr-3 text-slate-300 font-mono">{{ p.effective_max }}</td>
                        <td class="py-2 pr-3 text-slate-400">{{ p.limit_type }}</td>
                        <td class="py-2 pr-3 text-slate-400">{{ p.scope_type }}<span v-if="p.scope_id" class="text-slate-600"> #{{ p.scope_id }}</span></td>
                        <td class="py-2 pr-3">
                            <span :class="actionBadge(p.action)">{{ p.action }}</span>
                        </td>
                        <td class="py-2 pr-3 text-right">
                            <span :class="p.is_active ? 'badge bg-emerald-900/40 text-emerald-300' : 'badge bg-slate-800 text-slate-400'">
                                {{ p.is_active ? 'on' : 'off' }}
                            </span>
                        </td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap">
                            <button @click="toggle(p)" class="text-xs text-slate-400 hover:text-slate-100 mr-3">{{ p.is_active ? 'Disable' : 'Enable' }}</button>
                            <button @click="openForm(p)" class="text-xs text-sky-400 hover:text-sky-200 mr-3">Edit</button>
                            <button @click="remove(p)" class="text-xs text-rose-400 hover:text-rose-200">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Edit/create form modal -->
        <div v-if="formOpen" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4" @click.self="formOpen=false">
            <div class="bg-slate-900 border border-slate-700 rounded-lg p-6 max-w-2xl w-full">
                <h3 class="text-lg font-semibold text-slate-100 mb-4">{{ editing.id ? 'Edit policy' : 'New policy' }}</h3>
                <form @submit.prevent="save" class="space-y-3">
                    <div>
                        <label class="text-xs text-slate-400">Name</label>
                        <input v-model="editing.name" required maxlength="120" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Description</label>
                        <textarea v-model="editing.description" rows="2" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Limit type (bucket by)</label>
                            <select v-model="editing.limit_type" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="ip">IP</option>
                                <option value="user">User</option>
                                <option value="path">Path</option>
                                <option value="domain">Domain</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Scope</label>
                            <select v-model="editing.scope_type" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="global">Global</option>
                                <option value="domain">Domain</option>
                                <option value="edge">Edge</option>
                                <option value="user">User</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="editing.scope_type !== 'global'">
                        <label class="text-xs text-slate-400">Scope ID</label>
                        <input v-model.number="editing.scope_id" type="number" min="1" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Max requests</label>
                            <input v-model.number="editing.max_requests" type="number" min="1" required class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Window (seconds)</label>
                            <input v-model.number="editing.window_seconds" type="number" min="1" required class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Burst multiplier</label>
                            <input v-model.number="editing.burst_multiplier" type="number" step="0.1" min="1" max="10" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Action</label>
                        <select v-model="editing.action" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                            <option value="block">Block (429)</option>
                            <option value="challenge">Challenge (412)</option>
                            <option value="throttle">Throttle (slow down)</option>
                            <option value="log">Log only</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model="editing.is_active" type="checkbox" id="rl-active" />
                        <label for="rl-active" class="text-sm text-slate-300">Active</label>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="formOpen=false" class="btn-secondary text-sm">Cancel</button>
                        <button type="submit" :disabled="busy" class="btn-primary text-sm disabled:opacity-50">{{ busy ? '…' : 'Save' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../api'

const policies = ref([])
const loading = ref(false)
const error = ref(null)
const filters = reactive({ limit_type: '', scope_type: '', is_active: '' })
const formOpen = ref(false)
const editing = ref({})
const busy = ref(false)

async function reload() {
    loading.value = true
    error.value = null
    try {
        const params = {}
        if (filters.limit_type) params.limit_type = filters.limit_type
        if (filters.scope_type) params.scope_type = filters.scope_type
        if (filters.is_active !== '') params.is_active = filters.is_active
        const { data } = await api.get('/api/security/rate-limits', { params })
        policies.value = data.policies || []
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        loading.value = false
    }
}

function openForm(p = null) {
    editing.value = p
        ? { ...p }
        : { name: '', description: '', scope_type: 'global', scope_id: null, limit_type: 'ip', max_requests: 100, window_seconds: 60, burst_multiplier: 1.0, action: 'block', is_active: true }
    formOpen.value = true
}

async function save() {
    busy.value = true
    try {
        if (editing.value.id) {
            await api.put(`/api/security/rate-limits/${editing.value.id}`, editing.value)
        } else {
            await api.post('/api/security/rate-limits', editing.value)
        }
        formOpen.value = false
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        busy.value = false
    }
}

async function remove(p) {
    if (!confirm(`Delete policy "${p.name}"?`)) return
    try {
        await api.delete(`/api/security/rate-limits/${p.id}`)
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    }
}

async function toggle(p) {
    try {
        await api.post(`/api/security/rate-limits/${p.id}/toggle`)
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    }
}

function formatWindow(s) {
    if (s < 60) return `${s}s`
    if (s < 3600) return `${Math.round(s / 60)}m`
    if (s < 86400) return `${Math.round(s / 3600)}h`
    return `${Math.round(s / 86400)}d`
}

function actionBadge(action) {
    const base = 'badge text-xs'
    if (action === 'block') return `${base} bg-rose-900/40 text-rose-300`
    if (action === 'challenge') return `${base} bg-amber-900/40 text-amber-300`
    if (action === 'throttle') return `${base} bg-violet-900/40 text-violet-300`
    if (action === 'log') return `${base} bg-sky-900/40 text-sky-300`
    return `${base} bg-slate-800 text-slate-300`
}

onMounted(reload)
</script>
