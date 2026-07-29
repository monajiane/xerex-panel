# Xerex Edge Agent

The Go daemon that runs on every **Edge Server** of the [Xerex Panel](https://github.com/monajiane/xerex-panel) self-hosted CDN.

It is a single static binary (`xerex-agent`, ~10 MB) that:

1. Pulls the set of proxy rules assigned to this edge from the panel over
   REST (`GET /api/agent/config`), or receives them via a WebSocket push
   from Reverb.
2. Renders them into a single nginx config file
   (`/etc/nginx/conf.d/xerex-<edge-name>.conf`).
3. Validates the new config (`nginx -t`) and reloads nginx.
4. Reports host metrics (CPU, RAM, disk, bandwidth, open connections) to
   the panel every 30s (`POST /api/agent/telemetry`).
5. Tails `/var/log/nginx/access.log` and ships parsed entries to the
   panel every 5s (`POST /api/agent/traffic`).
6. Sends active health-check results from the edge side
   (`POST /api/agent/health`).

```
┌──────────┐    GET /api/agent/config      ┌──────────┐
│  Edge    │  ───────────────────────────▶ │  Xerex   │
│  Agent   │  ◀─────────────── nginx cfg   │  Panel   │
│  (Go)    │                                │ (Laravel)│
│          │    POST /api/agent/telemetry   │          │
│          │  ───────────────────────────▶  │          │
│          │    POST /api/agent/traffic     │          │
│          │  ───────────────────────────▶  │          │
│          │    WS  /app/<key>             │          │
│          │  ◀─────────── push events     │          │
└────┬─────┘                                └──────────┘
     │ nginx -s reload
     ▼
   nginx  ◀──── /var/log/nginx/access.log ───▶ shipper
```

---

## Quick start

### 1. Register the edge in the panel

In the panel UI, go to **Edge Servers → New edge** and copy the bearer
token. Treat this like a password — anyone with it can hijack the edge.

### 2. Install on the edge host

```bash
curl -fsSL https://github.com/monajiane/xerex-panel/releases/latest/download/install.sh \
  | sudo bash -s -- \
      --panel-url https://panel.example.com \
      --token xerx_xxx_your_token \
      --edge-name edge-fra-01
```

The script:

- Detects your architecture (amd64 / arm64 / armv7)
- Installs `nginx` if missing
- Drops the binary at `/usr/local/bin/xerex-agent`
- Writes `/etc/xerex/agent.yaml` (mode 0600)
- Installs and starts the `xerex-agent` systemd service

Verify with:

```bash
systemctl status xerex-agent
journalctl -u xerex-agent -f
```

### 3. From source

```bash
git clone https://github.com/monajiane/xerex-panel.git
cd xerex-panel/docker/edge-agent

make build                     # single binary
make build-all                 # cross-compile for linux/amd64, arm64, arm
make docker                    # build xerex/edge-agent:dev
```

The resulting binary is fully static (CGO_ENABLED=0) and runs on any
modern Linux, macOS or Windows.

---

## Configuration

All settings live in `/etc/xerex/agent.yaml`. A complete sample is
[here](config/agent.example.yaml). Sources, lowest to highest priority:

1. Built-in defaults
2. YAML file (`--config /etc/xerex/agent.yaml`)
3. Environment variables prefixed with `XEREX_` (e.g. `XEREX_PANEL_URL`)
4. CLI flags (e.g. `--panel-url https://...`)

CLI wins. Run `xerex-agent --help` (not implemented yet) to see the
current set.

---

## Architecture

```
cmd/xerex-agent/main.go      # entrypoint + signal handling
internal/
  ├── agent/                 # orchestrator (lifecycle, signals, components)
  ├── api/                   # HTTP client (REST to panel)
  ├── config/                # YAML/env/flag loader
  ├── configsync/            # pull → render → reload loop
  ├── logshipper/            # tail nginx access.log, ship batches
  ├── nginx/                 # render nginx config + reload
  ├── telemetry/             # CPU / RAM / disk / net sampling
  └── wspush/                # WebSocket push client (Reverb / Pusher)
```

The orchestrator wires the four long-running components together:

```go
type Component interface {
    Name() string
    Start(ctx context.Context) error
}
```

Each component gets its own goroutine. If any of them exits with a
non-nil error, the orchestrator cancels the others and the process
exits non-zero — systemd then restarts it.

### Config sync flow

```
ticker (30s) ──▶ GET /api/agent/config ──▶ sha256(rules)
                                              │
                                       hash changed?
                                              │
                                    yes ─────┴───── no (skip)
                                     │
                                     ▼
                        write xerex-<name>.conf
                                     │
                                     ▼
                                nginx -t
                                     │
                                     ▼
                              nginx -s reload
```

### WebSocket push (Reverb)

The agent connects to `<ws_url>/app/<key>?protocol=7&client=xerex-agent`
and joins the `private-edges.<id>` channel. The panel's broadcasting
auth endpoint signs the subscription; we never see the Reverb secret.

A `proxyrule.updated` event triggers an immediate `ForcePull()`. Any
disconnect is followed by an exponential-backoff reconnect (max 30s).

### Telemetry

Every 30 s the agent POSTs a sample to `/api/agent/telemetry`:

```json
{
  "agent_version": "1.0.0",
  "cpu_usage": 12.3,
  "ram_usage": 41.0,
  "disk_usage": 67.5,
  "bandwidth_in_bytes":  1819238192,
  "bandwidth_out_bytes": 2829317182,
  "active_connections": 134,
  "capabilities": ["http", "https", "websocket", "grpc", "tcp", "http2", "http3"]
}
```

### Log shipping

The shipper tails `/var/log/nginx/access.log` and re-opens the file
when it rotates (size truncation triggers an EOF; we poll and re-open).

Default format expected:

```
$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent
  "$http_referer" "$http_user_agent" rt=$request_time uct=... uht=... urt=...
```

If you use `log_format` with the `xerex_main` format the agent will pick
up the request/upstream times as well. Batches are flushed every
`log_batch_interval` (default 5 s) or when `log_batch_size` (default
200) lines are buffered.

---

## Security

- The bearer token is the only credential. Store it in `agent.yaml` with
  `chmod 0600` and run the agent as root only because it needs to write
  to `/etc/nginx` and read the access log. The service file enables
  systemd hardening: `ProtectSystem=full`, `PrivateTmp=true`,
  `ReadWritePaths=…`, plus `CAP_NET_BIND_SERVICE` if you want to bind
  privileged ports from nginx.
- TLS verification is **on** by default; use a valid certificate on the
  panel.
- No outbound network is required apart from the panel URL.

---

## Operations

```bash
# Status
systemctl status xerex-agent

# Logs
journalctl -u xerex-agent -f

# Force a config pull
systemctl reload xerex-agent     # sends SIGHUP — re-runs the loop
```

### Health check

The agent itself doesn't expose an HTTP endpoint; instead, treat the
heartbeat as the liveness probe: the panel flips an edge to `offline`
after `XEREX_HEALTH_CHECK_SUCCESS_THRESHOLD` (default 3) failed
telemetry samples. Tune the interval so the edge is marked offline
within the desired window.

---

## Roadmap

- [ ] `agent --help` with full flag listing
- [ ] Structured logging to disk (rotated)
- [ ] Optional mTLS between agent and panel
- [ ] Push-only mode (no polling) with offline config cache
- [ ] GeoIP-aware origin selection
- [ ] Local rate-limit + WAF enforcer (Phase 6)

See [the project roadmap](../../README.md#-roadmap) for the big picture.
