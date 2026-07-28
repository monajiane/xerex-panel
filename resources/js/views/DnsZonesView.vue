<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">DNS Zones</h1>
        <p class="text-slate-400 text-sm mt-1">PowerDNS-backed zones and records</p>
      </div>
      <button @click="showCreateZoneModal = true"
              class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Zone
      </button>
    </div>

    <!-- Zones list -->
    <div v-if="loading" class="text-center text-slate-500 py-12">Loading…</div>
    <div v-else-if="zones.length === 0" class="text-center text-slate-500 py-12 bg-slate-900/30 border border-slate-800 rounded-xl">
      No DNS zones yet. Create your first one to get started.
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="z in zones" :key="z.id"
           class="bg-slate-900/50 border border-slate-800 rounded-xl p-4 space-y-3 hover:border-indigo-500/50 transition cursor-pointer"
           @click="openZone(z)">
        <div class="flex items-center justify-between">
          <h3 class="text-slate-100 font-semibold">{{ z.name }}</h3>
          <span :class="z.is_active ? 'text-emerald-400' : 'text-slate-500'" class="text-xs">
            {{ z.is_active ? 'active' : 'inactive' }}
          </span>
        </div>
        <div class="text-xs text-slate-500 space-y-1">
          <div>Type: <span class="text-slate-300">{{ z.type }}</span></div>
          <div>Serial: <span class="text-slate-300">{{ z.serial }}</span></div>
          <div>TTL: <span class="text-slate-300">{{ z.ttl }}s</span></div>
        </div>
        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button @click.stop="syncZone(z)" class="text-xs text-indigo-400 hover:text-indigo-300">Sync</button>
          <button @click.stop="deleteZone(z)" class="text-xs text-rose-400 hover:text-rose-300">Delete</button>
        </div>
      </div>
    </div>

    <!-- Records modal -->
    <div v-if="activeZone" class="fixed inset-0 bg-slate-950/80 flex items-center justify-center z-50 p-4 overflow-y-auto">
      <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-4xl w-full p-6 space-y-4 my-8">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-slate-100">{{ activeZone.name }} — Records</h2>
          <button @click="activeZone = null" class="text-slate-400 hover:text-slate-200">×</button>
        </div>

        <div class="bg-slate-800/40 rounded-lg overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-slate-800/60 text-slate-300">
              <tr>
                <th class="text-left px-3 py-2">Name</th>
                <th class="text-left px-3 py-2">Type</th>
                <th class="text-left px-3 py-2">Content</th>
                <th class="text-left px-3 py-2">TTL</th>
                <th class="text-right px-3 py-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loadingRecords" class="border-t border-slate-700">
                <td colspan="5" class="px-3 py-6 text-center text-slate-500">Loading…</td>
              </tr>
              <tr v-for="r in records" :key="r.id" class="border-t border-slate-700">
                <td class="px-3 py-2 text-slate-200">{{ r.name }}</td>
                <td class="px-3 py-2 text-slate-400">{{ r.type }}</td>
                <td class="px-3 py-2 text-slate-400 truncate max-w-xs">{{ r.content }}</td>
                <td class="px-3 py-2 text-slate-400">{{ r.ttl }}</td>
                <td class="px-3 py-2 text-right">
                  <button @click="deleteRecord(r)" class="text-xs text-rose-400 hover:text-rose-300">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Add record form -->
        <form @submit.prevent="addRecord" class="grid grid-cols-2 md:grid-cols-5 gap-2 pt-2 border-t border-slate-800">
          <input v-model="recordForm.name" placeholder="name (e.g. www)" required
                 class="bg-slate-800 border border-slate-700 rounded px-2 py-1.5 text-slate-100" />
          <select v-model="recordForm.type"
                  class="bg-slate-800 border border-slate-700 rounded px-2 py-1.5 text-slate-100">
            <option>A</option><option>AAAA</option><option>CNAME</option>
            <option>MX</option><option>TXT</option><option>NS</option><option>SRV</option>
          </select>
          <input v-model="recordForm.content" placeholder="content" required
                 class="bg-slate-800 border border-slate-700 rounded px-2 py-1.5 text-slate-100 col-span-2" />
          <button type="submit" :disabled="adding"
                  class="bg-indigo-600 hover:bg-indigo-700 text-white rounded px-3 py-1.5 disabled:opacity-50">
            {{ adding ? '…' : 'Add' }}
          </button>
        </form>
      </div>
    </div>

    <!-- New zone modal -->
    <div v-if="showCreateZoneModal" class="fixed inset-0 bg-slate-950/70 flex items-center justify-center z-50 p-4">
      <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h2 class="text-lg font-semibold text-slate-100">New DNS Zone</h2>
        <form @submit.prevent="createZone" class="space-y-4">
          <div>
            <label class="block text-sm text-slate-400 mb-1">Zone name</label>
            <input v-model="newZoneName" type="text" required placeholder="example.com"
                   class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-slate-100" />
          </div>
          <div class="flex gap-2 justify-end">
            <button type="button" @click="showCreateZoneModal = false" class="px-4 py-2 text-slate-400">Cancel</button>
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
import { ref, onMounted } from 'vue'
import axios from 'axios'

const zones = ref([])
const records = ref([])
const activeZone = ref(null)
const loading = ref(false)
const loadingRecords = ref(false)
const adding = ref(false)
const showCreateZoneModal = ref(false)
const creating = ref(false)
const newZoneName = ref('')
const recordForm = ref({ name: '', type: 'A', content: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await axios.get('/api/dns/zones')
    zones.value = data.data || data || []
  } catch (e) {
    console.error('Failed to load zones', e)
  } finally {
    loading.value = false
  }
}

async function openZone(z) {
  activeZone.value = z
  loadingRecords.value = true
  try {
    const { data } = await axios.get(`/api/dns/zones/${encodeURIComponent(z.name)}/records`)
    records.value = data.data || data || []
  } catch (e) {
    console.error('Failed to load records', e)
  } finally {
    loadingRecords.value = false
  }
}

async function createZone() {
  creating.value = true
  try {
    await axios.post('/api/dns/zones', { name: newZoneName.value })
    newZoneName.value = ''
    showCreateZoneModal.value = false
    await load()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  } finally {
    creating.value = false
  }
}

async function deleteZone(z) {
  if (!confirm(`Delete zone ${z.name}? This will also delete all its records.`)) return
  try {
    await axios.delete(`/api/dns/zones/${encodeURIComponent(z.name)}`)
    await load()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

async function syncZone(z) {
  try {
    await axios.post(`/api/dns/zones/${encodeURIComponent(z.name)}/sync`)
    alert('Sync triggered.')
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

async function addRecord() {
  if (!activeZone.value) return
  adding.value = true
  try {
    await axios.post(`/api/dns/zones/${encodeURIComponent(activeZone.value.name)}/records`, recordForm.value)
    recordForm.value = { name: '', type: 'A', content: '' }
    await openZone(activeZone.value)
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  } finally {
    adding.value = false
  }
}

async function deleteRecord(r) {
  if (!confirm(`Delete record ${r.name} ${r.type} ${r.content}?`)) return
  try {
    await axios.delete(`/api/dns/zones/${encodeURIComponent(activeZone.value.name)}/records/${r.id}`)
    await openZone(activeZone.value)
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

onMounted(load)
</script>
