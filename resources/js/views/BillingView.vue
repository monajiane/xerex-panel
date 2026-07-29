<template>
    <div class="space-y-8">
        <!-- Current plan -->
        <section>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-100">Current Subscription</h3>
                <button v-if="sub?.cancel_at_period_end" @click="resumeSub" class="btn-secondary text-xs">
                    Resume subscription
                </button>
                <button v-else-if="sub?.status === 'active' || sub?.status === 'trialing'" @click="cancelSub" class="btn-secondary text-xs text-rose-300">
                    Cancel at period end
                </button>
            </div>
            <div v-if="!sub" class="card text-slate-400">
                You don't have an active subscription yet. Pick a plan below to get started.
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card">
                    <p class="text-xs text-slate-500 uppercase">Plan</p>
                    <p class="text-2xl font-bold text-emerald-400 mt-1">{{ sub.plan?.name || effectivePlan?.name || '—' }}</p>
                    <p v-if="sub.status === 'trialing'" class="text-xs text-amber-400 mt-2">
                        Trialing until {{ formatDate(sub.trial_ends_at) }}
                    </p>
                    <p v-else-if="sub.cancel_at_period_end" class="text-xs text-rose-300 mt-2">
                        Cancels on {{ formatDate(sub.current_period_end) }}
                    </p>
                </div>
                <div class="card">
                    <p class="text-xs text-slate-500 uppercase">Period ends</p>
                    <p class="text-2xl font-bold text-slate-200 mt-1">{{ formatDate(sub.current_period_end) || '—' }}</p>
                    <p class="text-xs text-slate-500 mt-2">Renews monthly</p>
                </div>
                <div class="card">
                    <p class="text-xs text-slate-500 uppercase">Status</p>
                    <p class="mt-1">
                        <span :class="['badge', statusBadgeClass(sub.status)]">{{ sub.status }}</span>
                    </p>
                    <p v-if="sub.is_trialing" class="text-xs text-slate-500 mt-2">{{ daysLeft(sub.trial_ends_at) }} day(s) left in trial</p>
                </div>
            </div>
        </section>

        <!-- Quota usage -->
        <section v-if="quotas.metrics && quotas.metrics.length">
            <h3 class="text-lg font-semibold text-slate-100 mb-4">Quota Usage</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-for="m in quotas.metrics" :key="m.metric" class="card">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-slate-300">{{ metricLabel(m.metric) }}</p>
                        <span v-if="m.unlimited" class="badge-info text-xs">unlimited</span>
                        <span v-else class="text-xs text-slate-500">{{ m.used }} / {{ m.limit }} <span class="text-slate-600">{{ m.period }}</span></span>
                    </div>
                    <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                        <div
                            :class="progressBarClass(m)"
                            class="h-full transition-all"
                            :style="{ width: progressWidth(m) + '%' }"
                        ></div>
                    </div>
                    <p v-if="!m.unlimited && m.remaining !== null" class="text-xs text-slate-500 mt-2">
                        {{ m.remaining }} remaining ({{ m.percent }}%)
                    </p>
                </div>
            </div>
        </section>

        <!-- Plan catalog -->
        <section>
            <h3 class="text-lg font-semibold text-slate-100 mb-4">Plans</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    :class="['card flex flex-col', currentSlug === plan.slug ? 'ring-2 ring-emerald-400' : '']"
                >
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-bold text-slate-100">{{ plan.name }}</p>
                        <span v-if="plan.is_default" class="badge text-xs">default</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">{{ plan.tagline }}</p>
                    <p class="text-3xl font-bold text-emerald-400 mt-4">{{ plan.formatted_price }}</p>
                    <ul class="mt-4 space-y-1.5 flex-1">
                        <li v-for="(v, k) in plan.features" :key="k" class="text-xs text-slate-300 flex items-start gap-2">
                            <span class="text-emerald-400 mt-0.5">✓</span>
                            <span>{{ k }}: <span class="text-slate-400">{{ v }}</span></span>
                        </li>
                    </ul>
                    <button
                        v-if="currentSlug !== plan.slug"
                        @click="subscribe(plan)"
                        :disabled="busyPlanId === plan.id"
                        class="btn-primary w-full mt-4 text-sm disabled:opacity-50"
                    >
                        {{ busyPlanId === plan.id ? '…' : (sub ? 'Switch to this plan' : 'Subscribe') }}
                    </button>
                    <div v-else class="mt-4 text-center text-xs text-emerald-400 font-medium">Current plan</div>
                </div>
            </div>
        </section>

        <!-- Invoices -->
        <section>
            <h3 class="text-lg font-semibold text-slate-100 mb-4">Invoices</h3>
            <div v-if="!invoices.length" class="card text-slate-500 text-sm">
                No invoices yet. You'll see your monthly billing history here.
            </div>
            <div v-else class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="pb-3">Number</th>
                            <th class="pb-3">Issued</th>
                            <th class="pb-3">Period</th>
                            <th class="pb-3">Amount</th>
                            <th class="pb-3">Status</th>
                            <th class="pb-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="inv in invoices" :key="inv.uuid" class="border-t border-slate-800">
                            <td class="py-3 font-mono text-xs text-slate-300">{{ inv.number }}</td>
                            <td class="py-3 text-slate-400">{{ formatDate(inv.issued_at) }}</td>
                            <td class="py-3 text-slate-400">{{ formatDate(inv.period_start) }} → {{ formatDate(inv.period_end) }}</td>
                            <td class="py-3 font-semibold text-slate-200">{{ inv.formatted_total }}</td>
                            <td class="py-3">
                                <span :class="['badge text-xs', invoiceStatusClass(inv)]">{{ inv.status }}</span>
                            </td>
                            <td class="py-3 text-right">
                                <button
                                    v-if="inv.status === 'open'"
                                    @click="payInvoice(inv)"
                                    class="text-xs text-emerald-400 hover:text-emerald-300"
                                >Pay now</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();

const sub = ref(null);
const effectivePlan = ref(null);
const plans = ref([]);
const quotas = ref({});
const invoices = ref([]);
const busyPlanId = ref(null);

const currentSlug = computed(() => sub.value?.plan?.slug || effectivePlan.value?.slug);

function metricLabel(m) {
    return ({
        domains: 'Domains',
        edges: 'Edge Servers',
        origins: 'Origin Servers',
        proxy_rules: 'Proxy Rules',
        ssl_certs: 'SSL Certificates',
        dns_zones: 'DNS Zones',
        team_members: 'Team Members',
        bandwidth_bytes: 'Bandwidth',
        requests: 'Requests',
    })[m] || m;
}

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function daysLeft(d) {
    if (!d) return 0;
    const ms = new Date(d).getTime() - Date.now();
    return Math.max(0, Math.ceil(ms / 86400000));
}

function statusBadgeClass(s) {
    if (s === 'active')   return 'bg-emerald-500/10 text-emerald-400';
    if (s === 'trialing') return 'bg-amber-500/10 text-amber-300';
    if (s === 'past_due') return 'bg-rose-500/10 text-rose-400';
    if (s === 'canceled') return 'bg-slate-500/10 text-slate-400';
    if (s === 'expired')  return 'bg-rose-500/10 text-rose-400';
    return 'bg-slate-500/10 text-slate-400';
}

function invoiceStatusClass(inv) {
    if (inv.status === 'paid') return 'bg-emerald-500/10 text-emerald-400';
    if (inv.status === 'open' && inv.is_overdue) return 'bg-rose-500/10 text-rose-400';
    if (inv.status === 'open') return 'bg-amber-500/10 text-amber-300';
    return 'bg-slate-500/10 text-slate-400';
}

function progressWidth(m) {
    if (m.unlimited) return 5;
    return Math.min(100, Math.max(0, m.percent));
}

function progressBarClass(m) {
    if (m.unlimited) return 'bg-slate-700';
    if (m.percent >= 100) return 'bg-rose-500';
    if (m.percent >= 80)  return 'bg-amber-500';
    return 'bg-emerald-500';
}

async function load() {
    const headers = { headers: { Authorization: `Bearer ${auth.token}` } };
    const [subRes, qRes, invRes, plansRes] = await Promise.all([
        axios.get('/api/billing/subscription', headers),
        axios.get('/api/billing/quotas', headers),
        axios.get('/api/billing/invoices', headers),
        axios.get('/api/billing/plans', headers),
    ]);
    sub.value = subRes.data.subscription;
    effectivePlan.value = subRes.data.effective_plan;
    quotas.value = qRes.data;
    invoices.value = invRes.data.invoices || [];
    plans.value = plansRes.data.plans || [];
}

async function subscribe(plan) {
    if (!confirm(`Switch to the ${plan.name} plan?`)) return;
    busyPlanId.value = plan.id;
    try {
        await axios.post('/api/billing/subscription', {
            plan_slug: plan.slug,
            with_trial: plan.trial_days > 0,
        }, { headers: { Authorization: `Bearer ${auth.token}` } });
        await load();
    } catch (e) {
        alert(e.response?.data?.message || 'Failed to switch plan');
    } finally {
        busyPlanId.value = null;
    }
}

async function cancelSub() {
    if (!confirm('Cancel your subscription at the end of the current period?')) return;
    await axios.post('/api/billing/subscription/cancel', {}, { headers: { Authorization: `Bearer ${auth.token}` } });
    await load();
}

async function resumeSub() {
    await axios.post('/api/billing/subscription/resume', {}, { headers: { Authorization: `Bearer ${auth.token}` } });
    await load();
}

async function payInvoice(inv) {
    if (!confirm(`Mark invoice ${inv.number} as paid?`)) return;
    await axios.post(`/api/billing/invoices/${inv.uuid}/pay`, {}, { headers: { Authorization: `Bearer ${auth.token}` } });
    await load();
}

onMounted(load);
</script>
