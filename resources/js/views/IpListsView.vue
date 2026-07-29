<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-100">IP Allow / Block Lists</h2>
                <p class="text-sm text-slate-400 mt-1">
                    Manage CIDR ranges. Allow rules always win over block rules.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="openBulkImport" class="btn-secondary text-sm">Bulk import</button>
                <button @click="openForm()" class="btn-primary text-sm">+ Add CIDR</button>
            </div>
        </header>

        <!-- Lookup widget -->
        <div class="card">
            <h3 class="text-sm font-semibold text-slate-200 mb-2">Check an IP</h3>
            <form @submit.prevent="checkIp" class="flex items-center gap-2">
                <input v-model="lookupIp" placeholder="1.2.3.4" required
                    class="flex-1 bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono" />
                <button type="submit" class="btn-primary text-sm">Check</button>
            </form>
            <div v-if="lookupResult" class="mt-3 text-sm">
                <p v-if="lookupResult.blocked" class="text-rose-300">
                    ✗ Blocked by <code>{{ lookupResult.block.cidr }}</code>
                    <span v-if="lookupResult.block.reason" class="text-slate-400">— {{ lookupResult.block.reason }}</span>
                </p>
                <p v-else-if="lookupResult.allowed" class="text-emerald-300">✓ Matched an allow entry — request would be permitted.</p>
                <p v-else class="text-slate-300">No matching list — request would be permitted.</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="card flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Type</label>
                <select v-model="filters.list_type" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    <option value="allow">Allow</option>
                    <option value="block">Block</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Show</label>
                <select v-model="filters.active" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="1">Active only</option>
                    <option value="0">Expired only</option>
                    <option value="">All</option>
                </select>
            </div>
        </div>

        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="error" class="card border border-rose-700 text-rose-300 text-sm">{{ error }}</div>
        <div v-else-if="!entries.length" class="card text-slate-400 text-sm">No entries yet.</div>
        <div v-else class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 uppercase border-b border-slate-800">
                        <th class="py-2 pr-3">Type</th>
                        <th class="py-2 pr-3">CIDR</th>
                        <th class="py-2 pr-3">Reason</th>
                        <th class="py-2 pr-3">Source</th>
                        <th class="py-2 pr-3">Scope</th>
                        <th class="py-2 pr-3">Expires</th>
                        <th class="py-2 pr-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries" :key="e.id" class="border-b border-slate-800/60 hover:bg-slate-900/40">
                        <td class="py-2 pr-3">
                            <span :class="e.list_type === 'block' ? 'badge bg-rose-900/40 text-rose-300' : 'badge bg-emerald-900/40 text-emerald-300'">
                                {{ e.list_type }}
                            </span>
                        </td>
                        <td class="py-2 pr-3 font-mono text-slate-100">{{ e.cidr }}</td>
                        <td class="py-2 pr-3 text-slate-400">{{ e.reason || '—' }}</td>
                        <td class="py-2 pr-3 text-slate-500">{{ e.source || 'manual' }}</td>
                        <td class="py-2 pr-3 text-slate-400">{{ e.scope_type || 'global' }}<span v-if="e.scope_id" class="text-slate-600"> #{{ e.scope_id }}</span></td>
                        <td class="py-2 pr-3 text-slate-400">
                            <span v-if="!e.expires_at" class="text-slate-600">never</span>
                            <span v-else :class="e.is_expired ? 'text-rose-300' : 'text-slate-300'">
                                {{ formatDate(e.expires_at) }}
                                <span v-if="e.is_expired" class="text-rose-400 text-xs ml-1">(expired)</span>
                            </span>
                        </td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap">
                            <button @click="remove(e)" class="text-xs text-rose-400 hover:text-rose-200">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Add form modal -->
        <div v-if="formOpen" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4" @click.self="formOpen=false">
            <div class="bg-slate-900 border border-slate-700 rounded-lg p-6 max-w-xl w-full">
                <h3 class="text-lg font-semibold text-slate-100 mb-4">Add IP / CIDR</h3>
                <form @submit.prevent="save" class="space-y-3">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Type</label>
                            <select v-model="editing.list_type" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="block">Block</option>
                                <option value="allow">Allow</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs text-slate-400">CIDR</label>
                            <input v-model="editing.cidr" required placeholder="1.2.3.0/24 or 1.2.3.4"
                                class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Reason</label>
                        <input v-model="editing.reason" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Source</label>
                            <input v-model="editing.source" placeholder="manual" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Scope type</label>
                            <select v-model="editing.scope_type" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="">Global</option>
                                <option value="domain">Domain</option>
                                <option value="edge">Edge</option>
                            </select>
                        </div>
                        <div v-if="editing.scope_type">
                            <label class="text-xs text-slate-400">Scope ID</label>
                            <input v-model.number="editing.scope_id" type="number" min="1" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Expires at (optional)</label>
                        <input v-model="editing.expires_at" type="datetime-local" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="formOpen=false" class="btn-secondary text-sm">Cancel</button>
                        <button type="submit" :disabled="busy" class="btn-primary text-sm disabled:opacity-50">{{ busy ? '…' : 'Save' }}</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk import modal -->
        <div v-if="bulkOpen" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4" @click.self="bulkOpen=false">
            <div class="bg-slate-900border border-slate-700 rounded-lg p-6 max-w-xl w-full">
                <h3 class="text-lg font-semibold text-slate-100 mb-4">Bulk import</h3>
                <form @submit.prevent="bulkSave" class="space-y-3">
                    <div>
                        <label class="text-xs text-slate-400">Type</label>
                        <select v-model="bulk.list_type" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                            <option value="block">Block</option>
                            <option value="allow">Allow</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Reason (applied to all)</label>
                        <input v-model="bulk.reason" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Source</label>
                        <input v-model="bulk.source" placeholder="feed:abuseipdb" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">CIDRs (one per line; # = comment)</label>
                        <textarea v-model="bulk.cidrs" rows="10" required
                            class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono"></textarea>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="bulkOpen=false" class="btn-secondary text-sm">Cancel</button>
                        <button type="submit" :disabled="busy" class="btn-primary text-sm disabled:opacity-50">{{ busy ? '…' : 'Import' }}</button>
                    </div>
                </form>
                <div v-if="bulkResult" class="mt-3 text-sm">
                    <p class="text-emerald-300">Imported {{ bulkResult.created_count }} entries.</p>
                    <p v-if="bulkResult.skipped_count" class="text-amber-300">{{ bulkResult.skipped_count }} skipped: <code>{{ bulkResult.skipped.join(', ') }}</code></p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../api'

const entries = ref([])
const loading = ref(false)
const error = ref(null)
const filters = reactive({ list_type: '', active: '1' })
const formOpen = ref(false)
const bulkOpen = ref(false)
const editing = ref({})
const bulk = reactive({ list_type: 'block', reason: '', source: 'manual', cidrs: '' })
const busy = ref(false)
const lookupIp = ref('')
const lookupResult = ref(null)
const bulkResult = ref(null)

async function reload() {
    loading.value = true
    error.value = null
    try {
        const params = {}
        if (filters.list_type) params.list_type = filters.list_type
        if (filters.active !== '') params.active = filters.active
        const { data } = await api.get('/api/security/ip-lists', { params })
        entries.value = data.entries || []
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        loading.value = false
    }
}

function openForm() {
    editing.value = { list_type: 'block', cidr: '', reason: '', source: 'manual', scope_type: '', scope_id: null, expires_at: '' }
    formOpen.value = true
}

async function save() {
    busy.value = true
    try {
        await api.post('/api/security/ip-lists', editing.value)
        formOpen.value = false
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        busy.value = false
    }
}

async function remove(e) {
    if (!confirm(`Delete ${e.cidr}?`)) return
    try {
        await api.delete(`/api/security/ip-lists/${e.id}`)
        await reload()
    } catch (err) {
        error.value = err?.response?.data?.message || err.message
    }
}

function openBulkImport() {
    bulk.list_type = 'block'
    bulk.reason = ''
    bulk.source = 'manual'
    bulk.cidrs = ''
    bulkResult.value = null
    bulkOpen.value = true
}

async function bulkSave() {
    busy.value = true
    bulkResult.value = null
    try {
        const { data } = await api.post('/api/security/ip-lists/bulk', {
            list_type: bulk.list_type,
            reason: bulk.reason,
            source: bulk.source,
            cidrs: bulk.cidrs,
        })
        bulkResult.value = data
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        busy.value = false
    }
}

async function checkIp() {
    lookupResult.value = null
    try {
        const { data } = await api.post('/api/security/ip-lists/check', { ip: lookupIp.value })
        lookupResult.value = data
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    }
}

function formatDate(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleString()
}

onMounted(reload)
</script>
