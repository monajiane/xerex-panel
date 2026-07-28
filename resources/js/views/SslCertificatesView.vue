<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">SSL Certificates</h1>
        <p class="text-slate-400 text-sm mt-1">Manage Let's Encrypt and custom TLS certificates</p>
      </div>
      <button @click="showIssueModal = true"
              class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Issue Certificate
      </button>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <StatCard label="Active" :value="stats.active" tone="emerald" />
      <StatCard label="Expiring" :value="stats.expiring" tone="amber" />
      <StatCard label="Expired" :value="stats.expired" tone="rose" />
      <StatCard label="Pending" :value="stats.pending" tone="slate" />
    </div>

    <!-- Certificates table -->
    <div class="bg-slate-900/50 border border-slate-800 rounded-xl overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-800/50 text-slate-300">
          <tr>
            <th class="text-left px-4 py-3">Domain</th>
            <th class="text-left px-4 py-3">Status</th>
            <th class="text-left px-4 py-3">Issuer</th>
            <th class="text-left px-4 py-3">Expires</th>
            <th class="text-left px-4 py-3">Auto Renew</th>
            <th class="text-right px-4 py-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading" class="border-t border-slate-800">
            <td colspan="6" class="px-4 py-12 text-center text-slate-500">Loading…</td>
          </tr>
          <tr v-else-if="certificates.length === 0" class="border-t border-slate-800">
            <td colspan="6" class="px-4 py-12 text-center text-slate-500">
              No certificates yet. Click "Issue Certificate" to start.
            </td>
          </tr>
          <tr v-for="cert in certificates" :key="cert.id" class="border-t border-slate-800 hover:bg-slate-800/30">
            <td class="px-4 py-3">
              <div class="text-slate-100 font-medium">{{ cert.common_name }}</div>
              <div v-if="cert.subject_alt_names && cert.subject_alt_names.length"
                   class="text-xs text-slate-500 mt-0.5">
                +{{ cert.subject_alt_names.length }} SAN
              </div>
            </td>
            <td class="px-4 py-3">
              <span :class="statusClass(cert.status)" class="px-2 py-0.5 text-xs rounded">
                {{ cert.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-slate-400">{{ cert.issuer || '—' }}</td>
            <td class="px-4 py-3 text-slate-400">
              <span v-if="cert.expires_at">
                {{ new Date(cert.expires_at).toLocaleDateString() }}
                <span class="text-xs text-slate-500 ml-1">
                  ({{ daysUntil(cert.expires_at) }}d)
                </span>
              </span>
              <span v-else class="text-slate-500">—</span>
            </td>
            <td class="px-4 py-3">
              <span v-if="cert.auto_renew" class="text-emerald-400 text-xs">Yes</span>
              <span v-else class="text-slate-500 text-xs">No</span>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button @click="renew(cert)" class="text-xs text-indigo-400 hover:text-indigo-300">Renew</button>
              <button @click="revoke(cert)" class="text-xs text-rose-400 hover:text-rose-300">Revoke</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Issue Modal -->
    <div v-if="showIssueModal" class="fixed inset-0 bg-slate-950/70 flex items-center justify-center z-50 p-4">
      <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h2 class="text-lg font-semibold text-slate-100">Issue SSL Certificate</h2>
        <form @submit.prevent="issue" class="space-y-4">
          <div>
            <label class="block text-sm text-slate-400 mb-1">Domain</label>
            <input v-model="issueForm.domain" type="text" required
                   placeholder="example.com"
                   class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-slate-100" />
          </div>
          <div>
            <label class="block text-sm text-slate-400 mb-1">Additional SANs (comma-separated)</label>
            <input v-model="issueForm.sans" type="text"
                   placeholder="www.example.com, api.example.com"
                   class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-slate-100" />
          </div>
          <div class="flex items-center gap-2">
            <input v-model="issueForm.staging" type="checkbox" id="staging" class="rounded" />
            <label for="staging" class="text-sm text-slate-300">Use Let's Encrypt staging (testing only)</label>
          </div>
          <div class="flex gap-2 justify-end">
            <button type="button" @click="showIssueModal = false"
                    class="px-4 py-2 text-slate-400 hover:text-slate-200">Cancel</button>
            <button type="submit" :disabled="issuing"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded disabled:opacity-50">
              {{ issuing ? 'Issuing…' : 'Issue' }}
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
import StatCard from '../components/StatCard.vue'

const certificates = ref([])
const loading = ref(false)
const showIssueModal = ref(false)
const issuing = ref(false)
const issueForm = ref({ domain: '', sans: '', staging: false })

const stats = computed(() => {
  const s = { active: 0, expiring: 0, expired: 0, pending: 0 }
  for (const c of certificates.value) {
    if (c.status === 'active') s.active++
    else if (c.status === 'expiring') s.expiring++
    else if (c.status === 'expired') s.expired++
    else s.pending++
  }
  return s
})

function statusClass(status) {
  return {
    'active':     'bg-emerald-500/20 text-emerald-300',
    'expiring':   'bg-amber-500/20 text-amber-300',
    'expired':    'bg-rose-500/20 text-rose-300',
    'pending':    'bg-slate-500/20 text-slate-300',
    'error':      'bg-rose-500/20 text-rose-300',
  }[status] || 'bg-slate-500/20 text-slate-300'
}

function daysUntil(date) {
  return Math.floor((new Date(date) - new Date()) / 86400000)
}

async function loadCertificates() {
  loading.value = true
  try {
    const { data } = await axios.get('/api/ssl')
    certificates.value = data.data || data || []
  } catch (e) {
    console.error('Failed to load certificates', e)
  } finally {
    loading.value = false
  }
}

async function issue() {
  issuing.value = true
  try {
    const sans = issueForm.value.sans.split(',').map(s => s.trim()).filter(Boolean)
    await axios.post('/api/ssl/issue', {
      domain: issueForm.value.domain,
      subject_alt_names: [issueForm.value.domain, ...sans],
      staging: issueForm.value.staging,
    })
    showIssueModal.value = false
    issueForm.value = { domain: '', sans: '', staging: false }
    await loadCertificates()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  } finally {
    issuing.value = false
  }
}

async function renew(cert) {
  if (!confirm(`Renew certificate for ${cert.common_name}?`)) return
  try {
    await axios.post(`/api/ssl/${cert.id}/renew`)
    await loadCertificates()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

async function revoke(cert) {
  if (!confirm(`Revoke certificate for ${cert.common_name}?`)) return
  try {
    await axios.delete(`/api/ssl/${cert.id}`)
    await loadCertificates()
  } catch (e) {
    alert('Failed: ' + (e.response?.data?.message || e.message))
  }
}

onMounted(loadCertificates)
</script>
