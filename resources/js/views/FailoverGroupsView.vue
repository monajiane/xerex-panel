<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">Failover Groups</h1>
        <p class="text-slate-400 text-sm mt-1">Group origins together for high-availability and auto-failover</p>
      </div>
      <button @click="showCreateModal = true"
              class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Group
      </button>
    </div>

    <!-- Groups list -->
    <div v-if="loading" class="text-center text-slate-500 py-12">Loading…</div>
    <div v-else-if="groups.length === 0" class="text-center text-slate-500 py-12 bg-slate-900/30 border border-slate-800 rounded-xl">
      No failover groups yet. Create one to get started.
    </div>

    <div v-else class="space-y-4">
      <div v-for="g in groups" :key="g.group"
           class="bg-slate-900/50 border border-slate-800 rounded-xl p-5 space-y-4">
        <!-- Group header -->
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-300">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
              </svg>
            </div>
            <div>
              <h3 class="text-slate-100 font-semibold">{{ g.group }}</h3>
              <p class="text-xs text-slate-500">
                {{ g.members }} member{{ g.members !== 1 ? 's' : '' }} · {{ g.healthy }} healthy
              </p>
            </div>
          </div>
          <div class="flex gap-2">
            <button @click="openReorder(g)" class="text-xs text-slate-400 hover:text-slate-200">Reorder</button>
            <button @click="dissolve(g)" class="text-xs text-rose-400 hover:text-rose-300">Dissolve</button>
          </div>
        </div>

        <!-- Leader indicator -->
        <div v-if="g.leader" class="flex items-center gap-2 text-xs text-emerald-400">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
          </svg>
          Leader: <span class="font-medium">{{ g.leader.name }}</span>
        </div>

        <!-- Members -->
        <div class="space-y-2">
          <div v-for="(m, idx) in g.list" :key="m.id"
               class="flex items-center justify-between bg-slate-800/40 rounded-lg px-3 py-2">
            <div class="flex items-center gap-3">
              <span class="w-6 h-6 rounded bg-slate-700 text-slate-300 text-xs flex items-center justify-center">
                {{ idx + 1 }}
              </span>
              <div>
                <div class="text-sm text-slate-200">{{ m.name }}</div>
                <div class="text-xs text-slate-500">{{ m.host }}:{{ m.port }}</div>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="m.is_active" class="px-2 py-0.5 text-xs rounded bg-emerald-500/20 text-emerald-300">active</span>
              <span v-else class="px-2 py-0.5 text-xs rounded bg-slate-500/20 text-slate-400">standby</span>
              <span :class="healthClass(m.health_status)" class="px-2 py-0.5 text-xs rounded">
                {{ m.health_status }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showCreateModal" class="fixed inset-0 bg-slate-950/70 flex items-center justify-center z-50 p-4">
      <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h2 class="text-lg font-semibold text-slate-100">New Failover Group</h2>
        <form @submit.prevent="create" class="space-y-4">
          <div>
            <label class="block text-sm text-slate-400 mb-1">Group Name</label>
            <input v-model="createForm.group" type="text" required
                   placeholder="web-prod"
                   class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-slate-100" />
          </div>
          <div>
            <label class="block text-sm text-slate-400 mb-1">Origins (priority order)</label>
            <select v-model="createForm.origins" multiple required
                    class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-slate-100 h-32">
              <option v-for="o in availableOrigins" :key="o.id" :value="o.id">
                {{ o.name }} ({{ o.host }}:{{ o.port }})
              </option>
            </select>
            <p class="text-xs text-slate-500 mt-1">Hold Ctrl/Cmd to select multiple. Top of list = highest priority.</p>
          </div>
          <div class="flex gap-2 justify-end">
            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-slate-400">Cancel</button>
            <button type="submit" :disabled="creating"
                    class="px-4 py-2 bg-indigo-600 text-white rounded disabled:opacity-50">
              {{ creating ? 'Creating…' : 'Create' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'

const groups = ref([])
const origins = ref([])
const loading = ref(false)
const showCreateModal = ref(false)
const creating = ref(false)
const createForm = ref({ group: '', origins: [] })

const availableOrigins = computed(() =>
  origins.value.filter(o => !o.failover_group)
)

function healthClass(status) {
  return {
    up:   'bg-emerald-500/20 text-emerald-300',
    down: 'bg-rose-500/20 text-rose-300',
  }[status] || 'bg-slate-500/20 text-slate-300'
}

async function load() {
  loading.value = true
  try {
    const [g, o] = await Promise.all([
      axios.get('/api/failover-groups'),
      axios.get('/api/origin-servers'),
    ])
    groups.value = g.data.data || []
    origins.value = o.data.data || []
  } catch (e) {
    console.error('Failed to load groups', e)
  } finally {
    loading.value = false
  }
}

async function create() {
  creating.value = true
  try {
    const origins = createForm.value.origins.map((id, idx) => ({ id, failover_priority: idx }))
    await axios.post('/api/failover-groups', {
      group: createForm.value.group,
      origins,
    })
    showCreateModal.value = false
    createForm.value = { group: '', origins: [] }
    await load()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  } finally {
    creating.value = false
  }
}

async function openReorder(g) {
  // Simple version: ask for new order
  const order = prompt(
    `Reorder ${g.group}\n\nEnter origin names in new order (one per line):\n` +
    g.list.map(m => m.name).join('\n')
  )
  if (!order) return
  const newOrder = order.split('\n').map(s => s.trim()).filter(Boolean)
  // Build priorities map based on new order
  const priorities = []
  for (let i = 0; i < newOrder.length; i++) {
    const member = g.list.find(m => m.name === newOrder[i])
    if (member) priorities.push({ id: member.id, failover_priority: i })
  }
  try {
    await axios.post(`/api/failover-groups/${encodeURIComponent(g.group)}/reorder`, { priorities })
    await load()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

async function dissolve(g) {
  if (!confirm(`Dissolve group "${g.group}"? Members will be ungrouped (not deleted).`)) return
  try {
    await axios.delete(`/api/failover-groups/${encodeURIComponent(g.group)}`)
    await load()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

onMounted(load)
</script>
