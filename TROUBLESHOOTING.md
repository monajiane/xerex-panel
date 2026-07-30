# Xerex Panel — Troubleshooting Guide
# راهنمای رفع مشکلات نصب پنل زِرِکس

This document captures the install-time issues we hit on real servers
during the v0.1.0 release and the exact fixes that landed in `main`.
If your `install.sh` blows up, look here first.

این سند مشکلات نصب واقعی که روی سرورهای مختلف در نسخه v0.1.0 بهشون
برخوردیم و فیکس‌هایی که در `main` اعمال شد رو ثبت می‌کنه. اگه
`install.sh` روی سرور شما هم خطا داد، اول اینجا رو ببینید.

---

## 🚑 Quick triage — which step failed?

| Symptom | See section |
|---|---|
| `E: Could not get lock /var/lib/dpkg/lock-frontend` | [§1 dpkg lock](#1-dpkg-lock-already-held) |
| `Connection timed out [IP: 146.75.123.52 443]` (Sury / Fastly) | [§2 Sury.org / Fastly blocked](#2-suryorg--fastly-blocked) |
| `PHP 8.3 install failed. Run \`apt install -y php8.3-cli\`` | [§3 Stale Sury source list](#3-stale-sury-source-list) |
| `fatal: detected dubious ownership in repository` | [§4 Git safe.directory](#4-git-safedirectory-warning) |
| `file_put_contents(./composer.lock): Permission denied` | [§5 composer permission denied](#5-composer-permission-denied) |
| `Class ... does not comply with psr-4 autoloading standard` | [§6 PSR-4 class-name vs file-name](#6-psr-4-class-name-vs-file-name) |
| `The /var/www/xerex-panel/bootstrap/cache directory must be present and writable` | [§7 bootstrap/cache missing](#7-bootstrapcache-missing-or-not-writable) |
| `Script @php artisan package:discover ... returned with error code 1` (silent) | [§8 Generic composer error](#8-generic-composer-error-1) |
| `Class "App\Providers\AuthServiceProvider" not found` | [§9 Laravel 11 dropped AuthServiceProvider](#9-laravel-11-dropped-authserviceprovider) |

---

## 1. dpkg lock already held

**Symptom:**
```
E: Could not get lock /var/lib/dpkg/lock-frontend
E: Unable to lock the administration directory (/var/lib/dpkg/)
```

**Cause:** A previous `apt-get` was killed mid-flight (SSH disconnect,
OOM, or you re-ran the installer) and the lock files were never released.

**Fix (one-shot):**
```bash
sudo pkill -9 apt-get dpkg 2>/dev/null
sudo rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock
sudo dpkg --configure -a
```

Or just run the included recovery script:
```bash
sudo /var/www/xerex-panel/install-recover.sh
```

**Why this no longer happens automatically:** `install.sh` now acquires
a `flock` lock at startup, waits up to 10 min for any in-flight
`apt-get` to finish, and keeps a state file at
`/var/lib/xerex-install/state` so re-runs pick up where they left off.
(Commits `758e5ef`, `eaf8427`.)

---

## 2. Sury.org / Fastly blocked

**Symptom:**
```
Err:1 https://packages.sury.org/php noble/main amd64 php8.3-cli
  Connection timed out [IP: 146.75.123.52 443]
```

**Cause:** Sury's packages are served by Fastly CDN. Some datacenters
(Iran, certain VPS providers, behind aggressive corporate firewalls)
block Fastly entirely.

**Status of `php8.3` on the OS family:**

| OS | `php8.3` in base repos? | Solution |
|---|---|---|
| Ubuntu 24.04 (noble) | ✅ yes (`universe`) | install.sh uses native, no Sury needed |
| Ubuntu 22.04 (jammy) | ❌ no | install.sh probes 4 Sury mirrors |
| Debian 12 (bookworm) | ❌ no | install.sh probes 4 Sury mirrors |
| Anything else | maybe | tries Sury, falls back to native |

**The 4 Sury mirrors (tried in order):**
1. `https://packages.sury.org/php` (official, Fastly)
2. `https://mirror.iranserver.com/sury-php` (Iranian mirror)
3. `https://ftp.acc.umu.se/mirror/sury-php` (Swedish academic)
4. `https://mirror.its.dal.ca/sury-php` (Canadian academic)

**Override the list:**
```bash
XEREX_SURY_MIRRORS="https://my-mirror.example/sury-php" \
  sudo ./install.sh --domain ...
```

(Commit `eaf8427`.)

---

## 3. Stale Sury source list

**Symptom:** After a previous failed install:
```
E: Failed to fetch https://packages.sury.org/php/pool/.../php8.3-cli_..._amd64.deb
  Connection timed out [IP: 146.75.123.52 443]
```
…even though you re-ran install.sh on a fresh Ubuntu 24.04 (which
shouldn't even use Sury).

**Cause:** A previous run left `/etc/apt/sources.list.d/php.list`
pointing at Sury. Every `apt install php8.3-*` then times out on Sury
and never falls through to `universe`.

**Fix (one-shot):**
```bash
sudo rm -f /etc/apt/sources.list.d/php.list /etc/apt/trusted.gpg.d/php.gpg
sudo apt update
sudo apt install -y php8.3-cli php8.3-fpm php8.3-mbstring ...
```

**Why this no longer happens automatically:** `install.sh` now calls
`sanitize_apt_sources()` before the `apt:php` step. It probes the
existing Sury host with an 8 s curl, and if it's unreachable it
removes the source list and the GPG key.

(Commit `63b61cd`.)

---

## 4. Git safe.directory warning

**Symptom:**
```
fatal: detected dubious ownership in repository at '/var/www/xerex-panel'
```

**Cause:** A previous install cloned the repo as `root`, but the
panel user (`xerex`) is now trying to `git pull`. Modern Git refuses
to operate on a repo it doesn't own.

**Fix (one-shot):**
```bash
sudo chown -R xerex:xerex /var/www/xerex-panel
git config --global --add safe.directory /var/www/xerex-panel
```

**Why this no longer happens automatically:** `install.sh` always
runs `chown -R xerex:xerex` on the panel home (idempotent) and
configures `git config --global --add safe.directory` for both root
and the panel user.

(Commit `5299d53`.)

---

## 5. composer permission denied

**Symptom:**
```
file_put_contents(./composer.lock): Failed to open stream: Permission denied
```

**Cause:** Same as §4 — the panel home is owned by `root` but
composer is running as the `xerex` user.

**Fix (one-shot):**
```bash
sudo chown -R xerex:xerex /var/www/xerex-panel
sudo bash /var/www/xerex-panel/install.sh --resume
```

(Commit `5299d53` is the root cause; the always-chown fix.)

---

## 6. PSR-4 class-name vs file-name

**Symptom:**
```
Class App\Repositories\RepositoryServiceProvider located in
./app/Repositories/ServiceProvider.php does not comply with
psr-4 autoloading standard (rule: App\ => ./app). Skipping.
```

**Cause:** PSR-4 says the file path must mirror the class name. The
class is `RepositoryServiceProvider` (in namespace `App\Repositories`)
but the file was named `ServiceProvider.php`.

**Fix:** Rename the file to match the class.

```bash
git mv app/Repositories/ServiceProvider.php \
       app/Repositories/RepositoryServiceProvider.php
```

Also: if `bootstrap/app.php` referenced the *wrong namespace*
(`App\Providers\RepositoryServiceProvider` instead of
`App\Repositories\RepositoryServiceProvider`), fix that too.

(Commits `c29d4e6`, `363c91f`.)

---

## 7. bootstrap/cache missing or not writable

**Symptom:**
```
The /var/www/xerex-panel/bootstrap/cache directory must be present and writable.
```

**Cause:** On a fresh clone, the `bootstrap/cache` and
`storage/framework/*` directories don't exist yet. `composer
post-autoload-dump` runs `php artisan package:discover` which needs
to write to them.

**Fix (one-shot):**
```bash
sudo mkdir -p /var/www/xerex-panel/bootstrap/cache \
              /var/www/xerex-panel/storage/framework/{cache/data,sessions,views} \
              /var/www/xerex-panel/storage/logs
sudo chown -R xerex:xerex /var/www/xerex-panel/bootstrap/cache \
                         /var/www/xerex-panel/storage
sudo find /var/www/xerex-panel/bootstrap/cache /var/www/xerex-panel/storage \
     -type d -exec chmod 775 {} \;
sudo find /var/www/xerex-panel/bootstrap/cache /var/www/xerex-panel/storage \
     -type f -exec chmod 664 {} \;
```

(Commit `c29d4e6`.)

---

## 8. Generic composer error 1

**Symptom:**
```
Script @php artisan package:discover --ansi handling the
post-autoload-dump event returned with error code 1
```
…with no other useful output.

**Cause:** Composer is hiding the real error. The most common
underlying causes are §6 (PSR-4), §7 (bootstrap/cache), or §9
(missing service provider). But the real culprit is anything that
makes `php artisan package:discover` throw.

**Fix (one-shot):** Reproduce the error verbosely:
```bash
cd /var/www/xerex-panel
sudo -u xerex php artisan package:discover -v 2>&1 | tail -30
```

That will print the real exception. If the panel already
has `.env` missing, you'll see:
```
No application encryption key has been specified.
```

**Why this no longer happens silently:** `install.sh` now pre-creates
`.env` from `.env.example` and generates a placeholder `APP_KEY`
*before* running composer, and on failure re-runs
`php artisan package:discover -v` to surface the real exception.

(Commit `b3e335e`.)

---

## 9. Laravel 11 dropped AuthServiceProvider

**Symptom:**
```
In ProviderRepository.php line 205:
  Class "App\Providers\AuthServiceProvider" not found
```

**Cause:** Laravel 11+ no longer auto-generates `AuthServiceProvider`
or `RouteServiceProvider`. Authentication and routing are configured
inline via `Application::configure()`. If `bootstrap/app.php`'s
`withProviders([...])` still lists them, the container throws at boot.

**Fix:** Edit `bootstrap/app.php` and remove both lines:
```php
->withProviders([
    App\Providers\AppServiceProvider::class,
    // App\Providers\AuthServiceProvider::class,   // ❌ delete
    // App\Providers\RouteServiceProvider::class,  // ❌ delete
    App\Providers\EventServiceProvider::class,
    App\Providers\XerexServiceProvider::class,
    App\Repositories\RepositoryServiceProvider::class,
])
```

(Commit `363c91f`.)

---

## 10. `.env` parse error: "Encountered unexpected whitespace at [Xerex Panel]"

**Symptom:**
```
The environment file is invalid!
Failed to parse dotenv file. Encountered unexpected whitespace at [Xerex Panel].
```
And immediately after, the systemd unit enters a restart loop:
```
xerex-panel.service: Main PID exited, status=209/STDOUT
```

**Cause:** `install.sh`'s `set_env()` helper wrote values to `.env`
without quoting them, e.g. `APP_NAME=Xerex Panel`. Laravel's strict
dotenv reader splits on whitespace, so it took `Xerex` as the value
and choked on `Panel`. The php-fpm/Laravel boot then threw, the
service exited, and the 502 from nginx was a downstream effect.

**Fix (without re-running the whole installer):**
```bash
cd /var/www/xerex-panel
# 1. Quote APP_NAME (and any other value that has a space)
sudo sed -i 's|^APP_NAME=.*|APP_NAME="Xerex Panel"|' .env
# 2. Re-generate APP_KEY just in case
sudo -u xerex php artisan key:generate --force
# 3. Clear cached config so the new env takes effect
sudo -u xerex php artisan config:clear
sudo -u xerex php artisan cache:clear
# 4. Restart the service
sudo systemctl restart xerex-panel
sudo systemctl status xerex-panel --no-pager
```

**Why the service was restarting:** the systemd unit runs
`php artisan serve`. When Laravel can't parse `.env` at boot, the
process exits with status 209 (PHP terminated with output on stdout),
systemd restarts it, the same thing happens, repeat. nginx sees
nothing on `127.0.0.1:8000` and returns 502.

**Why this is fixed in the installer:** `set_env()` now wraps every
value in double quotes (`APP_NAME="Xerex Panel"`), which the PHP
dotenv reader accepts regardless of spaces, and is harmless for
values that don't need quoting (`APP_DEBUG="false"`,
`DB_HOST="127.0.0.1"`, etc.).

(Commit for the install.sh fix follows in the version history below.)

---

## 🩺 Health check after install

There's a `verify-install.sh` at the repo root that runs 12+ checks
and reports green/red:

```bash
sudo /var/www/xerex-panel/verify-install.sh
```

It checks: PHP binary + extensions, composer vendor, .env + APP_KEY,
PostgreSQL connectivity, migrations, `installed.lock`, systemd service,
scheduler timer, nginx, HTTP 200, redis, and Sury source hygiene.

A green `✓ Xerex Panel is fully installed and reachable.` means
you're done.

(Commit `bf2b334`.)

---

## 📜 Version history of install-time fixes

| Commit  | What it fixed |
|---------|---------------|
| `758e5ef` | `install.sh` made idempotent (flock + state file) |
| `eaf8427` | Native PHP on Ubuntu 24.04 + Sury mirror fallback |
| `63b61cd` | Sanitize broken Sury source before php install |
| `bf2b334` | `verify-install.sh` post-install health check |
| `5299d53` | Always chown the panel home to the panel user |
| `c29d4e6` | PSR-4 + bootstrap/cache + storage permissions |
| `b3e335e` | Pre-create `.env` before composer runs |
| `363c91f` | Remove Laravel 11+ providers that no longer exist |
| `<this>` | `set_env` quotes values (fixes `Encountered unexpected whitespace` for `APP_NAME`) |
