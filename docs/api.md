# Xerex Panel — REST API Reference

Base URL: `https://panel.example.com/api`

All endpoints (except `auth/*` and `agent/*`) require an
`Authorization: Bearer <token>` header from Sanctum.

## Authentication

### `POST /auth/login`
```json
{ "email": "admin@xerex.local", "password": "secret" }
```
Response:
```json
{
  "user": { "id": 1, "uuid": "...", "name": "Admin", "email": "admin@xerex.local", "is_admin": true },
  "token": "1|abc...",
  "permissions": ["admin.dashboard.view", "admin.edges.manage"],
  "roles": ["admin"]
}
```

### `POST /auth/register`
Public registration. Returns a `customer` token.
```json
{ "name": "Alice", "email": "a@b.com", "password": "secret", "password_confirmation": "secret" }
```

### `GET /auth/me`
Returns the authenticated user + roles + permissions.

### `POST /auth/logout`
Revokes the current access token.

### `POST /auth/change-password`
Revokes all tokens (forces re-login).
```json
{ "current_password": "old", "password": "new", "password_confirmation": "new" }
```

---

## Edge Servers

| Method | Path | Description |
|-------:|------|-------------|
| GET    | `/edge-servers` | List (paginated) |
| POST   | `/edge-servers` | Create — returns `token` once |
| GET    | `/edge-servers/{id}` | Show one |
| PUT    | `/edge-servers/{id}` | Update |
| DELETE | `/edge-servers/{id}` | Delete |
| POST   | `/edge-servers/{id}/test` | TCP-probe agent port |
| POST   | `/edge-servers/{id}/rotate-token` | New agent token |

### Example: Create
```json
POST /edge-servers
{
  "name": "Iran-Edge-1",
  "hostname": "edge1.ir.example.com",
  "ip_address": "1.2.3.4",
  "location": "Tehran",
  "country_code": "IR",
  "capabilities": ["http2", "http3", "websocket"]
}
```
Response 201:
```json
{
  "edge": { "id": 1, "name": "Iran-Edge-1", "status": "provisioning", ... },
  "token": "xerx_AbC123..."   // Save this — only shown once
}
```

---

## Origin Servers

| Method | Path | Description |
|-------:|------|-------------|
| GET    | `/origin-servers` | List |
| POST   | `/origin-servers` | Create |
| PUT    | `/origin-servers/{id}` | Update |
| DELETE | `/origin-servers/{id}` | Delete |
| POST   | `/origin-servers/{id}/test` | TCP-probe upstream |

---

## Domains

| Method | Path | Description |
|-------:|------|-------------|
| GET    | `/domains` | List (auto-filtered to current user unless admin) |
| POST   | `/domains` | Add domain |
| GET    | `/domains/{id}` | Show with proxy rules + active certificate |
| PUT    | `/domains/{id}` | Update |
| DELETE | `/domains/{id}` | Delete |

---

## Proxy Rules

| Method | Path | Description |
|-------:|------|-------------|
| GET    | `/proxy-rules` | List |
| POST   | `/proxy-rules` | Create (triggers edge sync) |
| PUT    | `/proxy-rules/{id}` | Update |
| DELETE | `/proxy-rules/{id}` | Delete |
| POST   | `/proxy-rules/{id}/toggle` | Enable / disable |

### Example: Create
```json
POST /proxy-rules
{
  "domain_id": 1,
  "edge_server_id": 1,
  "origin_server_id": 1,
  "type": "websocket",
  "path": "/ws",
  "path_match_type": "prefix",
  "priority": 50,
  "weight": 100
}
```

---

## Edge Agent API

These endpoints use **edge-specific bearer tokens** (not user tokens).
See [`docs/edge-agent.md`](edge-agent.md) for the full protocol.

| Method | Path | Description |
|-------:|------|-------------|
| GET    | `/agent/config` | Get all rules assigned to this edge |
| POST   | `/agent/telemetry` | CPU / RAM / bandwidth / RPS |
| POST   | `/agent/traffic` | Bulk upload access logs (max 1000/batch) |
| POST   | `/agent/health` | Submit health check result |

---

## Dashboard

| Method | Path | Description |
|-------:|------|-------------|
| GET    | `/dashboard/stats` | Counts of edges/origins/domains/rules + 24h traffic |
| GET    | `/dashboard/traffic-series` | 24h hourly bucket series |
| GET    | `/dashboard/health-checks` | Last 50 health check results |

---

## Error Format

All errors return JSON:
```json
{ "message": "Validation failed", "errors": { "email": ["Invalid email"] } }
```

HTTP status codes follow REST conventions:
- `200` OK
- `201` Created
- `204` No Content
- `400` Bad Request
- `401` Unauthorized
- `403` Forbidden
- `404` Not Found
- `422` Validation Error
- `429` Rate Limited
- `500` Server Error
