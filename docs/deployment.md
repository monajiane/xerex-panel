# Deployment Guide

## Recommended Topology

```
                ┌──────────────┐
                │   Cloudflare │  (optional CDN / DDoS shield)
                └──────┬───────┘
                       │
       ┌───────────────┼───────────────┐
       │               │               │
  ┌────▼─────┐    ┌────▼─────┐    ┌────▼─────┐
  │ Edge IR  │    │ Edge EU  │    │ Edge US  │
  │ Iran     │    │ Germany  │    │ USA      │
  │ Nginx+Go │    │ Nginx+Go │    │ Nginx+Go │
  └────┬─────┘    └────┬─────┘    └────┬─────┘
       └───────────────┼───────────────┘
                       │
              ┌────────▼────────┐
              │ Origin Server   │
              │ (your app)      │
              └─────────────────┘
```

For the **Master Panel** itself, a single VM with Docker is enough to start.
For HA, run two replicas behind a load balancer with shared Postgres + Redis.

## Master: Single VM (small scale)

- 2 vCPU, 4 GB RAM, 40 GB SSD
- Ubuntu 22.04
- Docker + Compose
- Postgres + Redis on the same host

## Master: HA (production scale)

- 2× master VMs (behind LB, sticky session optional)
- Managed Postgres (RDS, Cloud SQL, or self-hosted with replication)
- Managed Redis (ElastiCache, Upstash, or self-hosted with Sentinel)
- S3-compatible object storage for cert backups

## Edge Server Sizing

| Traffic / day | vCPU | RAM  | Disk | Bandwidth |
|---------------|------|------|------|-----------|
| < 100k req    | 2    | 2 GB | 20 GB | 100 Mbps |
| 100k – 1M     | 4    | 4 GB | 40 GB | 500 Mbps |
| 1M – 10M      | 8    | 8 GB | 80 GB | 1 Gbps   |
| > 10M         | 16+  | 16+  | 160+  | 10+ Gbps |

## Edge: bare-metal install

See [`docs/installation.md`](installation.md) for the full agent install.
At minimum, each edge needs:

- Ubuntu 22.04+ / Debian 12+
- Nginx 1.27+
- Certbot
- Xerex Agent (apt repo, `apt install xerex-agent`)
- Open ports: 80, 443, **8443** (agent → panel), 22 (SSH)

## TLS for the Panel Itself

Use Let's Encrypt via Certbot. Renewals are handled by the certbot timer.

## Monitoring

Recommended stack:

- **Prometheus** + **node_exporter** on each VM
- **Grafana** dashboards
- **Loki** for log aggregation
- **Alertmanager** for PagerDuty / Telegram alerts

The panel itself exposes `/up` (Laravel's health endpoint) for LB health checks.

## Backups

```bash
# Database
0 3 * * *  pg_dump -U xerex xerex_panel | gzip > /backups/db-$(date +\%F).sql.gz

# Certificates (in docker volume)
0 4 * * *  docker run --rm -v xerex_certs:/data -v /backups:/backup \
          alpine tar czf /backup/certs-$(date +\%F).tgz /data

# Off-site: use rclone to push to S3 / B2 / etc.
```

## Scaling the Database

- Enable connection pooling (PgBouncer) at > 100 connections
- Add read replicas when `pg_stat_activity` shows > 80% CPU
- Move `traffic_logs` to ClickHouse when it exceeds 50 GB

## CDN / DDoS Protection

For production, put Cloudflare (or equivalent) in front of your edges.
Configure the origin to only accept traffic from Cloudflare IPs
(set this in `access_rules` on each proxy rule).
