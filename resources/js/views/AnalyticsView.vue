<template>
  <div class="space-y-6">
    <!-- Header + range selector -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">Traffic Analytics</h1>
        <p class="text-slate-400 text-sm mt-1">
          Aggregated from the hourly rollup table — no raw log scans.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="block text-xs text-slate-400 mb-1">From</label>
          <input
            type="datetime-local"
            v-model="fromInput"
            @change="reload"
            class="bg-slate-900 border border-slate-700 text-slate-100 rounded px-2 py-1 text-sm"
          />
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">To</label>
          <input
            type="datetime-local"
            v-model="toInput"
            @change="reload"
            class="bg-slate-900 border border-slate-700 text-slate-100 rounded px-2 py-1 text-sm"
          />
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Bucket</label>
          <select
            v-model="interval"
            @change="reload"
            class="bg-slate-900 border border-slate-700 text-slate-100 rounded px-2 py-1 text-sm"
          >
            <option value="minute">Minute</option>
            <option value="hour">Hour</option>
            <option value="day">Day</option>
          </select>
        </div>
        <button
          @click="reload"
          class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-100 rounded text-sm"
        >
          Refresh
        </button>
      </div>
    </div>

    <!-- Summary cards -->
    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <SummaryCard label="Total requests" :value="formatNumber(summary.total.requests)" tone="emerald" />
      <SummaryCard label="Total traffic" :value="formatBytes(summary.total.bytes)" tone="sky" />
      <SummaryCard label="Cache hit" :value="`${summary.cache_hit_ratio_pct}%`" tone="amber" />
      <SummaryCard label="5xx ratio" :value="ratio5xx + '%'" :tone="ratio5xx > 5 ? 'rose' : 'emerald'" />
    </div>

    <!-- Status code breakdown -->
    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <StatusPill code="2xx" :value="summary.status['2xx']" :total="summary.status.total" tone="emerald" />
      <StatusPill code="3xx" :value="summary.status['3xx']" :total="summary.status.total" tone="sky" />
      <StatusPill code="4xx" :value="summary.status['4xx']" :total="summary.status.total" tone="amber" />
      <StatusPill code="5xx" :value="summary.status['5xx']" :total="summary.status.total" tone="rose" />
    </div>

    <!-- Time series chart (pure SVG, no chart lib dependency) -->
    <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-slate-100 font-semibold">Requests over time</h2>
        <span class="text-xs text-slate-500">{{ series.points?.length || 0 }} buckets</span>
      </div>
      <div v-if="!series.points || series.points.length === 0" class="text-slate-500 text-sm py-12 text-center">
        No traffic data in the selected window yet.
      </div>
      <RequestsChart v-else :points="series.points" />
    </div>

    <!-- Top lists -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-5">
        <h2 class="text-slate-100 font-semibold mb-3">Top domains</h2>
        <table class="w-full text-sm">
          <thead class="text-xs uppercase text-slate-500 border-b border-slate-800">
            <tr>
              <th class="text-left py-2">Domain</th>
              <th class="text-right py-2">Requests</th>
              <th class="text-right py-2">Bytes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in topDomains.rows" :key="r.id" class="border-b border-slate-800/50">
              <td class="py-2 text-slate-200">{{ r.domain }}</td>
              <td class="py-2 text-right text-slate-300">{{ formatNumber(r.requests) }}</td>
              <td class="py-2 text-right text-slate-400">{{ formatBytes(r.bytes) }}</td>
            </tr>
            <tr v-if="!topDomains.rows || topDomains.rows.length === 0">
              <td colspan="3" class="py-6 text-center text-slate-500">No data</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-5">
        <h2 class="text-slate-100 font-semibold mb-3">Top proxy rules</h2>
        <table class="w-full text-sm">
          <thead class="text-xs uppercase text-slate-500 border-b border-slate-800">
            <tr>
              <th class="text-left py-2">Domain / Rule</th>
              <th class="text-right py-2">Requests</th>
              <th class="text-right py-2">Bytes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in topRules.rows" :key="r.id" class="border-b border-slate-800/50">
              <td class="py-2 text-slate-200">
                <span class="text-slate-500 text-xs">{{ r.type }}</span>
                <span class="ml-1">{{ r.domain || '(no domain)' }}</span>
              </td>
              <td class="py-2 text-right text-slate-300">{{ formatNumber(r.requests) }}</td>
              <td class="py-2 text-right text-slate-400">{{ formatBytes(r.bytes) }}</td>
            </tr>
            <tr v-if="!topRules.rows || topRules.rows.length === 0">
              <td colspan="3" class="py-6 text-center text-slate-500">No data</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue';
import axios from 'axios';

const interval = ref('hour');
const fromInput = ref(toLocalInput(new Date(Date.now() - 24 * 3600 * 1000)));
const toInput   = ref(toLocalInput(new Date()));
const series   = ref({ points: [] });
const summary  = ref(null);
const topDomains = ref({ rows: [] });
const topRules   = ref({ rows: [] });

const ratio5xx = computed(() => {
  if (!summary.value?.status?.total) return '0.0';
  return ((summary.value.status['5xx'] / summary.value.status.total) * 100).toFixed(2);
});

function toLocalInput(d) {
  // datetime-local needs YYYY-MM-DDTHH:MM
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function toIso(v) {
  // datetime-local value -> ISO 8601
  return new Date(v).toISOString();
}

async function reload() {
  const params = {
    interval: interval.value,
    from: toIso(fromInput.value),
    to:   toIso(toInput.value),
  };
  const [s, sum, td, tr] = await Promise.all([
    axios.get('/analytics/series',  { params }),
    axios.get('/analytics/summary', { params: { from: params.from, to: params.to } }),
    axios.get('/analytics/top-domains', { params: { from: params.from, to: params.to, limit: 10 } }),
    axios.get('/analytics/top-rules',   { params: { from: params.from, to: params.to, limit: 10 } }),
  ]);
  series.value     = s.data;
  summary.value    = sum.data;
  topDomains.value = td.data;
  topRules.value   = tr.data;
}

function formatNumber(n) {
  if (n == null) return '0';
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 1_000)     return (n / 1_000).toFixed(1) + 'k';
  return String(n);
}
function formatBytes(n) {
  if (n == null) return '0 B';
  const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(n < 10 && i > 0 ? 2 : 1)} ${u[i]}`;
}

onMounted(reload);

// ----------------------------------------------------------------------------
// Sub-components (inline so we don't have to register globally)
// ----------------------------------------------------------------------------

const SummaryCard = {
  props: ['label', 'value', 'tone'],
  setup(p) {
    const tones = {
      emerald: 'border-emerald-500/30 bg-emerald-500/5 text-emerald-300',
      sky:     'border-sky-500/30 bg-sky-500/5 text-sky-300',
      amber:   'border-amber-500/30 bg-amber-500/5 text-amber-300',
      rose:    'border-rose-500/30 bg-rose-500/5 text-rose-300',
    };
    return () => h('div', {
      class: `rounded-lg border p-3 ${tones[p.tone] || tones.sky}`,
    }, [
      h('div', { class: 'text-xs uppercase opacity-70' }, p.label),
      h('div', { class: 'text-xl font-semibold mt-1 text-slate-100' }, p.value),
    ]);
  },
};

const StatusPill = {
  props: ['code', 'value', 'total', 'tone'],
  setup(p) {
    const tones = {
      emerald: 'bg-emerald-500/10 text-emerald-300',
      sky:     'bg-sky-500/10 text-sky-300',
      amber:   'bg-amber-500/10 text-amber-300',
      rose:    'bg-rose-500/10 text-rose-300',
    };
    return () => {
      const pct = p.total > 0 ? ((p.value / p.total) * 100).toFixed(1) : '0.0';
      return h('div', {
        class: `rounded-lg p-3 ${tones[p.tone] || tones.sky}`,
      }, [
        h('div', { class: 'flex items-baseline justify-between' }, [
          h('span', { class: 'text-xs uppercase opacity-70' }, p.code),
          h('span', { class: 'text-xs opacity-70' }, `${pct}%`),
        ]),
        h('div', { class: 'text-xl font-semibold mt-1' }, formatNumber(p.value)),
      ]);
    };
  },
};

const RequestsChart = {
  props: ['points'],
  setup(p) {
    return () => {
      const pts = p.points || [];
      if (pts.length === 0) return null;
      const W = 800, H = 220, P = 30;
      const maxY = Math.max(...pts.map(d => Number(d.requests) || 0), 1);
      const stepX = (W - P * 2) / Math.max(pts.length - 1, 1);
      const yFor = v => H - P - (v / maxY) * (H - P * 2);
      const xFor = i => P + i * stepX;
      const path = pts.map((d, i) => `${i === 0 ? 'M' : 'L'} ${xFor(i)} ${yFor(Number(d.requests) || 0)}`).join(' ');
      const area = `${path} L ${xFor(pts.length - 1)} ${H - P} L ${xFor(0)} ${H - P} Z`;

      // X-axis labels: first, middle, last
      const labels = [];
      const fmt = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
      [0, Math.floor(pts.length / 2), pts.length - 1].forEach(i => {
        if (i < 0 || i >= pts.length) return;
        labels.push(h('text', {
          x: xFor(i), y: H - 6, 'text-anchor': 'middle',
          class: 'fill-slate-500 text-[10px]',
        }, fmt.format(new Date(pts[i].bucket))));
      });
      return h('svg', { viewBox: `0 0 ${W} ${H}`, class: 'w-full h-56' }, [
        h('path', { d: area, fill: 'rgba(16,185,129,0.15)' }),
        h('path', { d: path, fill: 'none', stroke: '#10b981', 'stroke-width': '2' }),
        ...labels,
      ]);
    };
  },
};
</script>
