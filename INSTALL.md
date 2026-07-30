# Xerex Panel — Installation Guide
# راهنمای نصب پنل زِرِکس

This document explains how to install the Xerex Panel in three different
scenarios, from a single dev box to a production VPS.

این سند نحوه نصب پنل زِرِکس را در سه حالت مختلف توضیح می‌دهد؛
از یک سیستم محلی گرفته تا سرور تولیدی.

> 🩺 **If the install fails partway through, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).**
> It documents every error we've hit on real production servers and the
> exact one-line fix for each.
>
> 🩺 **اگه نصب در حین کار خطا داد، [TROUBLESHOOTING.md](TROUBLESHOOTING.md) رو ببین.**
> تمام خطاهایی که روی سرورهای واقعی بهشون برخوردیم و فیکس یک‌خطی
> هر کدوم اونجا هست.

---

## 🇮🇷 فارسی (خلاصه سریع)

```bash
# ۱. روی سرور تازه اوبونتو/دبیان (با دسترسی root)
sudo apt update && sudo apt install -y curl git
curl -fsSL https://raw.githubusercontent.com/YOUR-ORG/xerex-panel/main/install.sh | \
  sudo bash -s -- --domain panel.example.com --email admin@example.com
```

تمام. اسکریپت PHP 8.3، PostgreSQL، Redis، Nginx و Certbot را نصب می‌کند،
سرویس systemd را فعال می‌کند، و در نهایت `php artisan xerex:install`
را برای مهاجرت، سیدر و ساخت ادمین اجرا می‌کند.

### گزینه‌های دیگر نصب

| روش | دستور | مناسب برای |
|-----|-------|-----------|
| نصب تعاملی CLI | `php artisan xerex:install` | توسعه محلی |
| نصب‌گر وب | به `https://panel.example.com/install` بروید | سرور تازه با مرورگر |
| اسکریپت خودکار | `./install.sh --domain … --email …` | سرور تولیدی (Debian/Ubuntu) |
| ویندوز (لوکال) | `install.bat` | توسعه روی ویندوز |

### بازگردانی به حالت اول

```bash
cd /var/www/xerex-panel
sudo -u xerex php artisan xerex:install --reset
```

### اگر به Sury.org (Fastly) دسترسی ندارید

سرورهایی که در بعضی دیتاسنترها هستند به `packages.sury.org` (که Fastly CDN است)
دسترسی ندارند. اسکریپت به‌طور خودکار این کار را می‌کند:

- **Ubuntu 24.04+** → از `universe` خود اوبونتو استفاده می‌کند (اصلاً به Sury نمی‌رود).
- **Ubuntu 22.04 / Debian 12** → چند آینه مختلف را امتحان می‌کند:
  1. `packages.sury.org` (پیش‌فرض، Fastly)
  2. `mirror.iranserver.com/sury-php` (آینه ایرانی)
  3. `ftp.acc.umu.se/mirror/sury-php` (آکادمیک سوئد)
  4. `mirror.its.dal.ca/sury-php` (آکادمیک کانادا)

اگر همه آینه‌ها fail شد، اسکریپت خودش می‌رود سراغ PHP پیش‌فرض OS.

اگر می‌خواهید دستی آینه را عوض کنید:

```bash
# همیشه Sury (حتی روی Ubuntu 24.04)
XEREX_FORCE_SURY=1 sudo ./install.sh --domain …

# اصلاً از Sury استفاده نکن
XEREX_SKIP_SURY=1 sudo ./install.sh --domain …

# آینه سفارشی
XEREX_SURY_MIRRORS="https://my-mirror/sury-php https://packages.sury.org/php" \
  sudo ./install.sh --domain …

# تعداد تلاش مجدد شبکه (پیش‌فرض ۴)
XEREX_NET_RETRY=6 sudo ./install.sh --domain …
```

اگر نصب قبلی گیر کرده، اول این را اجرا کنید:

```bash
sudo ./install-recover.sh
sudo ./install.sh --resume
```

---

## 🇬🇧 English — full guide

### 1. Prerequisites

| Component   | Minimum         | Recommended       |
|-------------|-----------------|-------------------|
| PHP         | 8.3             | 8.3 or 8.4        |
| Extensions  | pdo, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, curl, zip, gd, intl | + pgsql / mysql, redis |
| Composer    | 2.6             | latest            |
| Node.js     | 18              | 20 LTS            |
| Database    | PostgreSQL 14, MySQL 8, MariaDB 10.6, or SQLite 3.35+ | PostgreSQL 15+ |
| RAM         | 1 GB            | 2 GB+             |
| Disk        | 5 GB            | 20 GB+            |

### 2. Method A — Web installer (recommended for new servers)

The easiest way: drop the panel on a server, point a browser at it, and click through the wizard.

```bash
# 1. Get the code
git clone https://github.com/YOUR-ORG/xerex-panel.git /var/www/xerex-panel
cd /var/www/xerex-panel

# 2. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 3. Make storage writable
chmod -R ug+rw storage bootstrap/cache

# 4. (Optional) Build the front-end bundle
npm ci && npm run build

# 5. Start the dev server
php artisan serve --host=0.0.0.0 --port=8000
```

Now open `http://your-server:8000/install` in a browser. The wizard takes you through five steps:

1. **Requirements** — checks PHP version, extensions, writable directories.
2. **Database** — choose MySQL / PostgreSQL / SQLite, enter credentials. The wizard probes the connection before saving.
3. **App + admin** — public URL, environment, first admin user.
4. **Run** — applies migrations, seeds default plans / WAF / rate-limit rules, creates admin, writes the install lock.
5. **Done** — print next steps.

To start over, delete `storage/installed.lock` (or run `php artisan xerex:install --reset`).

### 3. Method B — CLI installer (great for automation)

```bash
php artisan xerox:install
```

You'll be asked for:
- DB driver, host, port, name, user, password
- Public URL of the panel
- Admin name, email, password

All of those can be passed as flags for non-interactive use:

```bash
php artisan xerex:install \
  --db-driver=pgsql --db-host=127.0.0.1 --db-port=5432 \
  --db-name=xerex_panel --db-user=xerex --db-password=secret \
  --app-url=https://panel.example.com \
  --admin-name="Xerex Admin" --admin-email=admin@example.com \
  --admin-password="ChangeMe-1234" \
  --force
```

Useful flags:

| Flag            | Effect                                                |
|-----------------|-------------------------------------------------------|
| `--reset`       | Wipe the install lock and re-run from scratch         |
| `--no-migrate`  | Skip migrations (you've already migrated manually)    |
| `--no-seed`     | Skip the default-data seeders                         |
| `--force`       | Don't prompt even when the panel is already installed |

### 4. Method C — One-shot server bootstrap (production)

For a brand-new Ubuntu 22.04+ or Debian 12+ VPS:

```bash
curl -fsSL https://raw.githubusercontent.com/YOUR-ORG/xerex-panel/main/install.sh | \
  sudo bash -s -- --domain panel.example.com --email admin@example.com
```

What the script does, in order:

1. Installs PHP 8.3 (Sury repo), PostgreSQL, Redis, Nginx, Certbot.
2. Creates a `xerex` system user.
3. Clones the panel into `/var/www/xerex-panel`.
4. Runs `composer install --no-dev --optimize-autoloader`.
5. Writes a production-grade `.env` (APP_URL, DB creds, sane session/queue defaults).
6. Installs the systemd units (`xerex-panel.service`, `xerex-scheduler.{service,timer}`).
7. Configures Nginx as a reverse proxy + requests a Let's Encrypt certificate.
8. Runs `php artisan xerex:install --force` to apply migrations, seeders, and create the admin user.

After it finishes you'll see:

```
URL:           https://panel.example.com
Admin email:   admin@example.com
Admin password: <random>
```

Re-run with `./install.sh --help` to see all flags. The script is idempotent
except for `--reset`, so it's safe to run on a partially-set-up server.

#### 4.1 Network resilience & mirror selection

The script picks the PHP source **automatically** based on the OS:

| OS                        | PHP source                                  |
|---------------------------|---------------------------------------------|
| Ubuntu 24.04+ (noble/24.10) | Native `universe` repo (no third-party)   |
| Ubuntu 22.04 (jammy)      | Sury, with mirror fallback                  |
| Debian 12 (bookworm)      | Sury, with mirror fallback                  |
| anything else             | Sury, with mirror fallback                  |

For Sury, the default mirror list (tried in order) is:

1. `https://packages.sury.org/php` (official, served by Fastly)
2. `https://mirror.iranserver.com/sury-php` (Iranian mirror)
3. `https://ftp.acc.umu.se/mirror/sury-php` (Swedish academic)
4. `https://mirror.its.dal.ca/sury-php` (Canadian academic)

You can override any of this with environment variables:

```bash
# Always use Sury (even on Ubuntu 24.04)
curl -fsSL https://.../install.sh | XEREX_FORCE_SURY=1 sudo bash -s -- --domain …

# Never use Sury — install whatever the OS already has
curl -fsSL https://.../install.sh | XEREX_SKIP_SURY=1 sudo bash -s -- --domain …

# Use a custom list of Sury mirrors
XEREX_SURY_MIRRORS="https://my-mirror.example/sury-php https://packages.sury.org/php" \
  sudo ./install.sh --domain …

# Change the number of network retries (default 4)
XEREX_NET_RETRY=6 sudo ./install.sh --domain …
```

The script also retries every network call with exponential backoff
(2s, 4s, 8s, 16s) before giving up — so a single transient timeout won't
kill the install.

#### 4.2 Recovering from a failed install

If the script dies halfway (SSH disconnect, OOM, network outage), just
re-run it. It uses `flock` for process safety and a state file at
`/var/lib/xerex-install/state` for step-level idempotency, so it picks
up where it left off.

For **really** stuck installs (a zombie apt/dpkg holding the lock), use
the recovery script shipped in the repo:

```bash
# On a fresh clone:
curl -fsSL https://raw.githubusercontent.com/YOUR-ORG/xerex-panel/main/install-recover.sh | sudo bash

# Or if you already have the repo:
sudo /var/www/xerex-panel/install-recover.sh

# Just check state:
sudo ./install.sh --status

# Start over from scratch:
sudo ./install.sh --reset
```

### 5. Method D — Windows / local development

For local dev on Windows:

```bat
git clone https://github.com/YOUR-ORG/xerex-panel.git
cd xerex-panel
install.bat
```

`install.bat`will:
- Copy `.env.example` to `.env`
- Generate `APP_KEY`
- Configure SQLite at `database/database.sqlite`
- Run migrations + seeders
- Create the default admin (`admin@xerex.local` / `password`)

Then start the dev server with:

```bat
php artisan serve
npx vite      # in another terminal
```

### 6. After installation

A few things you should do on a fresh install:

```bash
# 1. Build production assets (skipped on dev)
npm ci && npm run build

# 2. Storage symlink (so user uploads work)
php artisan storage:link

# 3. Front-end cache for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. (Optional) Start Horizon for queue supervision
php artisan horizon
```

### 7. Running on a cluster / behind a load balancer

The panel is stateless. The only stateful components are:
- PostgreSQL (or MySQL) — central
- Redis — cache + queue + broadcasting
- `storage/` — must be shared (NFS / S3 / EFS) if you run >1 web pod

The included `docker-compose.yml` brings up:
- `xerex-panel` (PHP-FPM + nginx)
- `xerex-nginx` (OpenResty edge proxy)
- `xerex-postgres`
- `xerex-redis`
- `xerex-powerdns` (DNS server for the API)
- `xerex-reverb` (WebSockets)
- `xerex-horizon` (queue worker)
- `xerex-scheduler` (cron)

To deploy the stack:

```bash
cp .env.example .env       # then edit
docker compose pull
docker compose up -d
docker compose exec panel php artisan xerex:install --force
```

### 8. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Class "PDO" not found` | PHP missing pdo extension | `apt install php8.3-pgsql` (or `-mysql`) |
| `SQLSTATE[42P01]: Undefined table` | Migrations not run | `php artisan migrate --force` |
| `No application encryption key` | `APP_KEY` is empty | `php artisan key:generate` |
| 419 page on submit | Session driver failing | Set `SESSION_DRIVER=file` in `.env` |
| Stuck on "Running…" at step 4 | Migrations conflict | `php artisan xerex:install --reset` after fixing the DB |
| 500 on `/api/*` with `NOT_INSTALLED` | Install lock missing | Visit `/install` or `php artisan xerex:install` |
| `Permission denied` on storage | Wrong file owner | `chown -R xerex:www-data storage bootstrap/cache` |

### 9. Upgrading

```bash
cd /var/www/xerex-panel
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
sudo systemctl restart xerex-panel
```

The install lock is preserved across upgrades — you do **not** need to re-run
the installer for routine version bumps. The installer is only for the first
boot, or when you want to reset the admin password / database connection.

### 10. Uninstalling

```bash
sudo systemctl disable --now xerex-panel xerex-scheduler.timer
sudo rm -rf /var/www/xerex-panel /etc/systemd/system/xerex-*
sudo -u postgres dropdb xerex_panel
sudo -u postgres dropuser xerex
```

That's it. The panel leaves no traces outside `/var/www/xerex-panel`,
the systemd units, and the database.

---

## File map

```
xerex-panel/
├── install.sh                       # One-shot bootstrap (Debian/Ubuntu)
├── install.bat                      # Windows helper for local dev
├── xerex-panel.service              # systemd unit for the HTTP server
├── xerex-scheduler.service          # systemd oneshot for the scheduler
├── xerex-scheduler.timer            # systemd timer (every minute)
├── app/
│   ├── Console/Commands/
│   │   └── InstallCommand.php       # php artisan xerex:install
│   ├── Http/Controllers/
│   │   └── InstallController.php    # /install wizard
│   ├── Http/Middleware/
│   │   └── EnsureInstalled.php      # Redirects to /install when not locked
│   └── Support/
│       └── Installer.php            # Shared install logic (used by CLI + web)
├── resources/views/install/
│   ├── layout.blade.php             # Wizard shell
│   ├── welcome.blade.php            # Step 1: requirements
│   ├── database.blade.php           # Step 2: DB connection
│   ├── app.blade.php                # Step 3: APP_URL + admin user
│   ├── run.blade.php                # Step 4: run migrate/seed
│   └── done.blade.php               # Step 5: success page
├── routes/
│   ├── web.php                      # /install/* + SPA routes
│   └── api.php                      # (unchanged)
├── bootstrap/
│   └── app.php                      # Registers EnsureInstalled in web+api groups
└── storage/
    └── installed.lock               # Touched by the installer on success
```

---

## Security notes

- The install wizard accepts unauthenticated requests by design (the panel
  has no users yet). Once `storage/installed.lock` exists, the
  `EnsureInstalled` middleware stops redirecting and normal auth kicks in.
- If you want to lock down `/install/*` after installation (recommended on
  internet-facing servers), add a rule in your reverse proxy that allows
  `/install` only from your office IP.
- The installer never writes secrets anywhere except `.env` and `installed.lock`.
  Database passwords are passed via flags, written to `.env` with permission
  0640, and never logged.
- The systemd unit runs as a dedicated `xerex` user with `ProtectSystem=strict`
  and `PrivateTmp=true`, so even an RCE in the panel cannot touch the host OS.
