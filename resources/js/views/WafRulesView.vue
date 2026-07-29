<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-100">WAF Rules</h2>
                <p class="text-sm text-slate-400 mt-1">
                    Web Application Firewall rules — evaluated in priority order on every protected request.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="openTestPanel = true" class="btn-secondary text-sm">Test request</button>
                <button @click="openForm()" class="btn-primary text-sm">+ New rule</button>
            </div>
        </header>

        <!-- Filters -->
        <div class="card flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Type</label>
                <select v-model="filters.type" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    <option value="sql_injection">SQL injection</option>
                    <option value="xss">XSS</option>
                    <option value="path_traversal">Path traversal</option>
                    <option value="rce">RCE</option>
                    <option value="user_agent">Bad user agent</option>
                    <option value="regex">Custom regex</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Action</label>
                <select v-model="filters.action" @change="reload" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-sm">
                    <option value="">Any</option>
                    <option value="block">Block</option>
                    <option value="challenge">Challenge</option>
                    <option value="log">Log only</option>
                    <option value="rate_limit">Rate limit</option>
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
            <button @click="resetFilters" class="text-xs text-slate-400 hover:text-slate-200 ml-auto">Reset</button>
        </div>

        <!-- Loading / errors -->
        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="error" class="card border border-rose-700 text-rose-300 text-sm">{{ error }}</div>
        <div v-else-if="!rules.length" class="card text-slate-400 text-sm">
            No rules yet. Run <code>php artisan xerex:security:seed-waf</code> to install the built-in set, or click “+ New rule”.
        </div>

        <!-- Rules table -->
        <div v-else class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 uppercase border-b border-slate-800">
                        <th class="py-2 pr-3">Priority</th>
                        <th class="py-2 pr-3">Name</th>
                        <th class="py-2 pr-3">Type</th>
                        <th class="py-2 pr-3">Action</th>
                        <th class="py-2 pr-3">Target</th>
                        <th class="py-2 pr-3">Scope</th>
                        <th class="py-2 pr-3 text-right">Status</th>
                        <th class="py-2 pr-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rules" :key="r.id" class="border-b border-slate-800/60 hover:bg-slate-900/40">
                        <td class="py-2 pr-3 text-slate-300 font-mono">{{ r.priority }}</td>
                        <td class="py-2 pr-3 text-slate-100 font-medium">{{ r.name }}</td>
                        <td class="py-2 pr-3 text-slate-400">{{ r.type }}</td>
                        <td class="py-2 pr-3">
                            <span :class="actionBadge(r.action)">{{ r.action }}</span>
                        </td>
                        <td class="py-2 pr-3 text-slate-400">{{ r.target }}<span v-if="r.target_field" class="text-slate-600">/{{ r.target_field }}</span></td>
                        <td class="py-2 pr-3 text-slate-400">{{ r.scope_type || 'global' }}<span v-if="r.scope_id" class="text-slate-600"> #{{ r.scope_id }}</span></td>
                        <td class="py-2 pr-3 text-right">
                            <span :class="r.is_active ? 'badge bg-emerald-900/40 text-emerald-300' : 'badge bg-slate-800 text-slate-400'">
                                {{ r.is_active ? 'on' : 'off' }}
                            </span>
                        </td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap">
                            <button @click="toggle(r)" class="text-xs text-slate-400 hover:text-slate-100 mr-3">{{ r.is_active ? 'Disable' : 'Enable' }}</button>
                            <button @click="openForm(r)" class="text-xs text-sky-400 hover:text-sky-200 mr-3">Edit</button>
                            <button @click="remove(r)" class="text-xs text-rose-400 hover:text-rose-200">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Edit/create form modal -->
        <div v-if="formOpen" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4" @click.self="formOpen=false">
            <div class="bg-slate-900 border border-slate-700 rounded-lg p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-semibold text-slate-100 mb-4">{{ editing.id ? 'Edit rule' : 'New rule' }}</h3>
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
                            <label class="text-xs text-slate-400">Type</label>
                            <select v-model="editing.type" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="sql_injection">SQL injection</option>
                                <option value="xss">XSS</option>
                                <option value="path_traversal">Path traversal</option>
                                <option value="rce">RCE</option>
                                <option value="user_agent">Bad user agent</option>
                                <option value="regex">Custom regex</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Action</label>
                            <select v-model="editing.action" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="block">Block (403)</option>
                                <option value="challenge">Challenge (412)</option>
                                <option value="log">Log only</option>
                                <option value="rate_limit">Rate limit</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Target</label>
                            <select v-model="editing.target" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm">
                                <option value="uri">URI</option>
                                <option value="query">Query string</option>
                                <option value="body">Body</option>
                                <option value="header">Header</option>
                                <option value="user_agent">User agent</option>
                                <option value="any">Anywhere</option>
                            </select>
                        </div>
                        <div v-if="editing.target === 'header'">
                            <label class="text-xs text-slate-400">Header name</label>
                            <input v-model="editing.target_field" maxlength="64" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                    </div>
                    <div v-if="editing.type === 'regex'">
                        <label class="text-xs text-slate-400">Regex pattern (PCRE, no delimiters)</label>
                        <input v-model="editing.pattern" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Priority</label>
                            <input v-model.number="editing.priority" type="number" min="0" max="10000" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
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
                    <div class="flex items-center gap-2">
                        <input v-model="editing.is_active" type="checkbox" id="waf-active" />
                        <label for="waf-active" class="text-sm text-slate-300">Active</label>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="formOpen=false" class="btn-secondary text-sm">Cancel</button>
                        <button type="submit" :disabled="busy" class="btn-primary text-sm disabled:opacity-50">{{ busy ? '…' : 'Save' }}</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Test panel -->
        <div v-if="openTestPanel" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4" @click.self="openTestPanel=false">
            <div class="bg-slate-900 border border-slate-700 rounded-lg p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-semibold text-slate-100 mb-4">Test a request against the WAF</h3>
                <form @submit.prevent="runTest" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-slate-400">Method</label>
                            <input v-model="test.method" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">URI</label>
                            <input v-model="test.uri" required class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Query string</label>
                        <input v-model="test.query" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Body</label>
                        <textarea v-model="test.body" rows="3" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm font-mono"></textarea>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">User agent</label>
                        <input v-model="test.user_agent" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1 text-sm" />
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="openTestPanel=false" class="btn-secondary text-sm">Close</button>
                        <button type="submit" :disabled="testBusy" class="btn-primary text-sm disabled:opacity-50">{{ testBusy ? '…' : 'Run test' }}</button>
                    </div>
                </form>
                <div v-if="testResult" class="mt-4 space-y-2">
                    <p v-if="testResult.matches.length === 0" class="text-emerald-400 text-sm">No rules matched — request would be allowed.</p>
                    <div v-for="m in testResult.matches" :key="m.rule.id" class="border border-rose-700/40 rounded p-2 text-sm">
                        <p class="text-rose-300 font-medium">{{ m.rule.name }} <span class="text-slate-500 text-xs">({{ m.rule.action }})</span></p>
                        <p class="text-slate-400 text-xs mt-1">evidence: <code class="text-amber-300">{{ m.evidence }}</code></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../api'

const rules = ref([])
const loading = ref(false)
const error = ref(null)
const filters = reactive({ type: '', action: '', is_active: '' })
const formOpen = ref(false)
const editing = ref({})
const busy = ref(false)
const openTestPanel = ref(false)
const test = reactive({ method: 'GET', uri: '/', query: '', body: '', user_agent: '' })
const testBusy = ref(false)
const testResult = ref(null)

function resetFilters() {
    filters.type = ''; filters.action = ''; filters.is_active = ''
    reload()
}

async function reload() {
    loading.value = true
    error.value = null
    try {
        const params = {}
        if (filters.type) params.type = filters.type
        if (filters.action) params.action = filters.action
        if (filters.is_active !== '') params.is_active = filters.is_active
        const { data } = await api.get('/api/security/waf/rules', { params })
        rules.value = data.rules || []
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        loading.value = false
    }
}

function openForm(r = null) {
    editing.value = r
        ? { ...r }
        : { name: '', description: '', type: 'sql_injection', action: 'block', target: 'uri', target_field: '', pattern: '', priority: 100, is_active: true, scope_type: '', scope_id: null }
    formOpen.value = true
}

async function save() {
    busy.value = true
    try {
        if (editing.value.id) {
            await api.put(`/api/security/waf/rules/${editing.value.id}`, editing.value)
        } else {
            await api.post('/api/security/waf/rules', editing.value)
        }
        formOpen.value = false
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        busy.value = false
    }
}

async function remove(r) {
    if (!confirm(`Delete rule "${r.name}"?`)) return
    try {
        await api.delete(`/api/security/waf/rules/${r.id}`)
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    }
}

async function toggle(r) {
    try {
        await api.post(`/api/security/waf/rules/${r.id}/toggle`)
        await reload()
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    }
}

async function runTest() {
    testBusy.value = true
    testResult.value = null
    try {
        const { data } = await api.post('/api/security/waf/test', test)
        testResult.value = data
    } catch (e) {
        error.value = e?.response?.data?.message || e.message
    } finally {
        testBusy.value = false
    }
}

function actionBadge(action) {
    const base = 'badge text-xs'
    if (action === 'block') return `${base} bg-rose-900/40 text-rose-300`
    if (action === 'challenge') return `${base} bg-amber-900/40 text-amber-300`
    if (action === 'log') return `${base} bg-sky-900/40 text-sky-300`
    if (action === 'rate_limit') return `${base} bg-violet-900/40 text-violet-300`
    return `${base} bg-slate-800 text-slate-300`
}

onMounted(reload)
</script>
