# ⚡ Xerex Panel

**Self-hosted control panel for Edge Proxy / CDN networks, inspired by HestiaCP.**

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Laravel 12](https://img.shields.io/badge/Laravel-12.x-FF2D20)](https://laravel.com)
[![Vue 3](https://img.shields.io/badge/Vue-3.x-42b883)](https://vuejs.org)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3+-777BB4)](https://php.net)

Xerex Panel lets you build a distributed edge network:

```
                        ┌────────────────┐
   Client ────────────▶ │  Edge Server   │  ──┐
                        │  (Iran/Asia)   │    │
                        │  Nginx + Agent │    │   ┌────────────────┐
                        └────────────────┘    └──▶│  Origin Server │
                                                  │  (US/EU)       │
                        ┌────────────────┐    ┌──▶│  Application   │
   Client ────────────▶ │  Edge Server   │ ───┘   └────────────────┘
                        │  (Asia)        │
                        └────────────────┘
```

Manage domains, edge nodes, origin servers, proxy rules (HTTP / WebSocket / gRPC / TCP),
SSL certificates (Let's Encrypt), DNS (PowerDNS), and health monitoring — all from
a single dashboard.

---

## ✨ Features

### Phase 1 — Foundation
- ✅ Laravel 12 + PHP 8.3 backend with REST API
- ✅ Vue 3 + TailwindCSS responsive dashboard
- ✅ PostgreSQL / MariaDB + Redis
- ✅ Authentication (Sanctum tokens) with role-based permissions (admin / operator / customer)
- ✅ Full CRUD: Edge servers, Origin servers, Domains, Proxy rules
- ✅ Edge Agent API (`/api/agent/*`) with bearer token auth
- ✅ Nginx config generator (HTTP, WebSocket, gRPC, TCP, SSE, Redirect)
- ✅ Auto-sync proxy rule changes to edges via queued jobs
- ✅ Audit log for all sensitive actions
- ✅ Docker Compose development environment

### Phase 2 — Operations
- ✅ Health checks (HTTP probes, latency tracking, consecutive success/failure thresholds)
- ✅ Failover groups — group origins for HA, auto-promote the next healthy candidate
- ✅ Multi-origin upstream blocks in generated nginx config (weighted, max_fails, fail_timeout)
- ✅ Real-time updates via Laravel Reverb (WebSocket broadcasting of edge / origin / proxy / SSL / DNS events)
- ✅ PowerDNS HTTP API integration (zone + record CRUD)
- ✅ Let's Encrypt via Certbot with renewal scheduler
- ✅ Full automated test suite (unit + feature, SQLite in-memory, factories for every model)
- ✅ Frontend views for SSL, DNS, and Failover Groups

### Coming (Phase 3+)
- 🚧 Golang Edge Agent (will live in `docker/edge-agent/`)
- 🚧 Traffic log aggregation & analytics
- 🚧 Multi-tenant billing
- 🚧 WAF / rate limiting / IP allow/block lists
- 🚧 Single Sign-On (SSO) and 2FA

---

## 🏗️ Architecture

```
┌──────────────────────────────┐
│   Xerex Panel (Master)       │
│                              │
│   ┌────────────────────┐     │
│   │  Laravel API       │     │
│   │  + Vue 3 SPA       │     │
│   └─────────┬──────────┘     │
│             │                │
│   ┌─────────▼──────────┐     │
│   │  PostgreSQL        │     │
│   │  Redis (queues)    │     │
│   └────────────────────┘     │
└──────────────┬───────────────┘
               │  HTTPS (REST)
               │  + Bearer token
               │
       ┌───────┴───────┐
       │               │
┌──────▼──────┐  ┌─────▼───────┐
│ Edge #1     │  │ Edge #2     │
│ Nginx +     │  │ Nginx +     │
│ Xerex Agent │  │ Xerex Agent │
│ (Go)        │  │ (Go)        │
└──────┬──────┘  └─────┬───────┘
       │               │
       └───────┬───────┘
               │
       ┌───────▼───────┐
       │ Origin Server │
       │ (your app)    │
       └───────────────┘
```

---

## 🚀 Quick Start (Docker)

Requirements: **Docker** 24+ and **Docker Compose** v2.

```bash
# 1. Clone
git clone https://github.com/monajiane/xerex-panel.git
cd xerex-panel

# 2. Copy env
cp .env.example .env

# 3. Boot
docker compose up -d

# 4. Install dependencies & migrate
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# 5. Open
open http://localhost:8000
```

**Default admin login** (change immediately!):

```
Email:    admin@xerex.local
Password: password
```

---

## 🛠️ Manual Installation (without Docker)

Requirements: **PHP 8.3+**, **Composer 2**, **Node 20+**, **PostgreSQL 14+** (or **MariaDB 10.6+**), **Redis 7+**.

```bash
git clone https://github.com/monajiane/xerex-panel.git
cd xerex-panel

cp .env.example .env
composer install
npm install
npm run build

# DB
php artisan key:generate
php artisan migrate --seed

# Serve
php artisan serve
# Queue worker (separate terminal)
php artisan horizon
```

---

## 📡 Edge Agent

Each edge server runs a small Go binary that:

1. Pulls its config from `GET /api/agent/config` every 30s (or on push)
2. Renders the nginx config to `/etc/nginx/conf.d/xerex-<id>.conf`
3. Runs `nginx -t && nginx -s reload`
4. Reports telemetry to `POST /api/agent/telemetry`
5. Ships access logs to `POST /api/agent/traffic`

> The Go agent source will be added in **Phase 3** of the roadmap. The
> nginx config generator, edge sync service, telemetry ingestion and health
> check pipeline are already in place and covered by automated tests.

## 🔁 Real-time updates (Reverb)

Server-pushed events flow over Laravel Reverb (Pusher protocol):

- `edge.status` — edge server came online / went offline / degraded
- `origin.health` — health check result changed (latency, status)
- `origin.failover` — a failover group promoted / demoted a member
- `proxyrule.updated` — created / updated / deleted / toggled
- `ssl.updated` — issued / renewed / revoked
- `dns.updated` — zone / record changed

The SPA subscribes via the `useRealtimeStore` Pinia store
(`resources/js/stores/realtime.js`) which uses `laravel-echo` +
`pusher-js`. The connection details are injected into the page through
Reverb meta tags rendered by `resources/views/app.blade.php` and parsed in
`resources/js/bootstrap.js` — no rebuild required when Reverb host/port
change.

---

## 📚 Documentation

- [Installation](docs/installation.md)
- [Architecture](docs/architecture.md)
- [API Reference](docs/api.md)
- [Edge Agent Protocol](docs/edge-agent.md)
- [Deployment](docs/deployment.md)
- [Contributing](CONTRIBUTING.md)

---

## 🧪 Development

```bash
# Run tests
php artisan test

# Code style
./vendor/bin/pint

# Static analysis
./vendor/bin/phpstan analyse

# Frontend dev server (Vite HMR)
npm run dev
```

---

## 🗺️ Roadmap

| Phase | Status | Description |
|------:|:------:|:------------|
| 1 | ✅ | Laravel setup, migrations, auth, admin dashboard |
| 2 | ✅ | Edge / origin management, health checks, failover groups, Reverb realtime, PowerDNS, Certbot, full test suite |
| 3 | ⏳ | Go-based Edge Agent |
| 4 | ⏳ | Traffic log aggregation & analytics |
| 5 | ⏳ | Multi-tenant billing & quotas |
| 6 | ⏳ | WAF, rate limiting, IP allow/block lists |

---

## 📦 Project Structure

```
xerex-panel/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/   # REST controllers
│   │   ├── Middleware/        # Custom middleware (edge auth, audit)
│   │   └── ...
│   ├── Jobs/                  # Queue jobs (edge sync, SSL renewal)
│   ├── Models/                # Eloquent models
│   ├── Observers/             # Model observers (event dispatch)
│   ├── Providers/             # Service providers
│   ├── Repositories/          # Repository pattern (contracts + Eloquent impls)
│   ├── Services/              # Business logic (NginxConfigGenerator, EdgeSync, HealthCheck, FailoverGroup, ...)
│   └── Events/                # Broadcast events
├── bootstrap/
├── config/                    # Including xerex.php, broadcasting.php
├── database/
│   ├── migrations/            # 12 migrations
│   ├── factories/             # 8 model factories
│   └── seeders/
├── docker/
│   ├── app/                   # PHP-FPM Dockerfile
│   └── nginx/                 # Front nginx config
├── resources/
│   ├── css/                   # Tailwind
│   ├── js/                    # Vue 3 app
│   │   ├── components/
│   │   ├── layouts/
│   │   ├── router/
│   │   ├── stores/            # Pinia (auth, realtime)
│   │   └── views/             # 8 views incl. SSL / DNS / Failover
│   └── views/                 # Blade (app shell)
├── routes/
│   ├── api.php                # REST + agent routes
│   ├── channels.php           # Broadcast auth
│   ├── console.php
│   └── web.php
├── storage/
├── tests/                     # Unit + feature tests
└── docker-compose.yml         # app, postgres, redis, nginx, powerdns, horizon, reverb, vite
```

---

## 🤝 Contributing

PRs are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) first.

1. Fork the repo
2. Create a feature branch (`git checkout -b feat/amazing`)
3. Commit your changes
4. Push and open a Pull Request

---

## 📄 License

[MIT](LICENSE) — use, modify, distribute freely.

---

## 🙏 Credits

Inspired by [HestiaCP](https://hestiacp.com), [cPanel](https://cpanel.net),
[RunCloud](https://runcloud.io), and the broader self-hosting community.
