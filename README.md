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

### Phase 1 (current)
- ✅ Laravel 12 + PHP 8.3 backend with REST API
- ✅ Vue 3 + TailwindCSS responsive dashboard
- ✅ PostgreSQL / MariaDB + Redis
- ✅ Authentication (Sanctum tokens) with role-based permissions (admin / operator / customer)
- ✅ Full CRUD: Edge servers, Origin servers, Domains, Proxy rules
- ✅ Edge Agent API (`/api/agent/*`) with bearer token auth
- ✅ Nginx config generator (HTTP, WebSocket, gRPC, TCP, SSE, Redirect)
- ✅ Auto-sync proxy rule changes to edges via queued jobs
- ✅ Health checks (HTTP probes, latency tracking)
- ✅ Audit log for all sensitive actions
- ✅ Docker Compose development environment

### Coming (Phase 2+)
- 🚧 Golang Edge Agent (will live in `docker/edge-agent/`)
- 🚧 PowerDNS integration (zone & record management)
- 🚧 Certbot automation with renewal scheduler
- 🚧 Real-time monitoring dashboard (WebSocket push)
- 🚧 Traffic log aggregation & analytics
- 🚧 Multi-tenant billing
- 🚧 Failover groups & global load balancing
- 🚧 WAF / rate limiting / IP allow/block lists

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

> The Go agent source will be added in **Phase 4** of the roadmap.

To install an agent manually today (stub):

```bash
# On the edge server (Ubuntu/Debian):
apt install -y nginx certbot
echo "deb [trusted=yes] https://apt.xerex.dev /" > /etc/apt/sources.list.d/xerex.list
apt update && apt install -y xerex-agent
systemctl enable --now xerex-agent
# Register the agent token (printed by the panel UI)
xerex-agent register --server https://panel.example.com --token <TOKEN>
```

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
| 2 | ⏳ | Edge server & origin management, telemetry |
| 3 | ⏳ | Proxy rule engine, nginx config generator, sync jobs |
| 4 | ⏳ | Go-based Edge Agent |
| 5 | ⏳ | PowerDNS & Let's Encrypt automation |
| 6 | ⏳ | Real-time monitoring dashboard & alerts |

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
│   ├── Observers/             # Model observers
│   ├── Providers/             # Service providers
│   ├── Repositories/          # Repository pattern (contracts + Eloquent impls)
│   └── Services/              # Business logic (NginxConfigGenerator, EdgeSync, ...)
├── bootstrap/
├── config/                    # Including xerex.php
├── database/
│   ├── migrations/            # 11 migrations
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
│   │   ├── stores/            # Pinia
│   │   └── views/
│   └── views/                 # Blade (app shell)
├── routes/
│   ├── api.php
│   ├── web.php
│   └── console.php
├── storage/
├── tests/
└── docker-compose.yml
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
