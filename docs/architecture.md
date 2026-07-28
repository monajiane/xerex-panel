# Xerex Panel — Architecture

## Overview

Xerex Panel is a **control plane** for distributed edge proxy networks.
It does not terminate traffic itself — instead, it manages a fleet of
**edge servers** that run Nginx and the **Xerex Agent** (Go).

## Components

### 1. Master (this repo)

| Component      | Stack                         | Role                                      |
|----------------|-------------------------------|-------------------------------------------|
| API            | Laravel 12 (PHP 8.3)          | REST API, business logic, validation      |
| Dashboard      | Vue 3 + TailwindCSS           | Admin & customer UI                       |
| Database       | PostgreSQL / MariaDB          | System of record                          |
| Queue          | Redis + Laravel Horizon       | Async jobs (edge sync, SSL renewal)       |
| Activity log   | spatie/laravel-activitylog    | Audit trail                               |
| Auth           | Laravel Sanctum               | Bearer tokens for SPA & API               |

### 2. Edge Server (separate host, per location)

| Component      | Stack                | Role                              |
|----------------|----------------------|-----------------------------------|
| Reverse proxy  | Nginx 1.27 / OpenResty | Terminates TLS, proxies to origin |
| Config gen     | Xerex Agent (Go)     | Renders config, reloads nginx    |
| Certbot        | Certbot              | Let's Encrypt ACME client         |

The edge **never** talks to the database directly. It only calls the
panel's REST API.

### 3. Origin Server

Any HTTP(S)/TCP/gRPC server. The edge treats it as an opaque upstream.

## Data Flow

```
            ┌────────── Xerex Panel ──────────┐
            │  Admin creates a ProxyRule      │
            │  (domain, edge, origin)         │
            └────────────────┬─────────────────┘
                             │ Observer triggers
                             ▼
            ┌─────────────────────────────────┐
            │  SyncEdgeConfig job (queued)    │
            │  - generates nginx config       │
            │  - computes hash                │
            │  - HTTP POST to edge            │
            └────────────────┬─────────────────┘
                             │ Bearer token
                             ▼
            ┌─────────────────────────────────┐
            │  Xerex Agent (Go) on edge       │
            │  - writes /etc/nginx/conf.d/x   │
            │  - nginx -t && nginx -s reload   │
            │  - returns OK/error              │
            └─────────────────────────────────┘
```

## Multi-Tenancy Model

Xerex supports three role types out of the box:

- **admin** — full access, can manage edges, origins, all users
- **operator** — manage infrastructure (edges, DNS, SSL) but no users
- **customer** — manage their own domains, origins, and proxy rules

Permissions are enforced via `spatie/laravel-permission` middleware.

## Scalability Notes

- The **traffic_logs** table grows fast. In production, partition by month
  (Postgres native partitioning) or move to ClickHouse.
- All write paths are queued (Horizon), so the API stays fast under load.
- Edges can be horizontally scaled — add a new edge, deploy the agent,
  attach proxy rules to it.
- For high-availability, deploy the master behind a load balancer with
  shared Postgres and Redis.

## Security

- Bearer tokens with expiry (configurable in `config/xerex.php`)
- Per-edge HMAC signing of config payloads (planned for Phase 4)
- TLS 1.2+ enforced on the agent <-> panel link
- Audit log records every mutating API call
- Rate limiting via Laravel's `throttle:api` middleware
