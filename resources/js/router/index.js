import { createRouter, createWebHistory } from 'vue-router';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../views/LoginView.vue'),
        meta: { public: true },
    },
    {
        path: '/',
        component: () => import('../layouts/AdminLayout.vue'),
        children: [
            { path: '', redirect: '/dashboard' },
            { path: 'dashboard',      name: 'dashboard',       component: () => import('../views/DashboardView.vue') },
            { path: 'edge-servers',   name: 'edge-servers',    component: () => import('../views/EdgeServersView.vue') },
            { path: 'origins',        name: 'origins',         component: () => import('../views/OriginsView.vue') },
            { path: 'failover-groups',name: 'failover-groups', component: () => import('../views/FailoverGroupsView.vue') },
            { path: 'domains',        name: 'domains',         component: () => import('../views/DomainsView.vue') },
            { path: 'proxy-rules',    name: 'proxy-rules',     component: () => import('../views/ProxyRulesView.vue') },
            { path: 'dns',            name: 'dns-zones',       component: () => import('../views/DnsZonesView.vue') },
            { path: 'ssl',            name: 'ssl',             component: () => import('../views/SslCertificatesView.vue') },
            { path: 'analytics',      name: 'analytics',       component: () => import('../views/AnalyticsView.vue') },
            { path: 'billing',        name: 'billing',         component: () => import('../views/BillingView.vue') },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to, from, next) => {
    const token = localStorage.getItem('xerex_token');
    if (!to.meta.public && !token) {
        return next({ name: 'login', query: { redirect: to.fullPath } });
    }
    if (to.name === 'login' && token) {
        return next({ name: 'dashboard' });
    }
    next();
});

export default router;
