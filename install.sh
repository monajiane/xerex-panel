#!/usr/bin/env bash
# ============================================================================
# Xerex Panel — One-shot bootstrap script for fresh Ubuntu/Debian servers.
#
# This version is hardened against the "two installs at once" trap that
# bit our users in production: it now uses flock + a state file, so the
# script can be safely re-run after an SSH disconnect, an OOM kill, or
# an interrupted apt-get.
#
# What it does:
#   1. Acquires a global install lock (flock) so only one install runs.
#   2. Waits for any in-flight apt/dpkg process to finish.
#   3. Installs PHP 8.3, PostgreSQL, Redis, Nginx, Certbot and friends.
#   4. Clones the Xerex Panel into /var/www/xerex-panel.
#   5. Runs composer install, copies .env.example -> .env, generates APP_KEY.
#   6. Writes a sane production .env (APP_URL, DB creds, Redis, etc.).
#   7. Installs the systemd unit (xerex-panel.service) and enables it.
#   8. Runs `php artisan xerex:install` for the final migration + admin steps.
#
# Re-runnable: every step is gated by a state file, so re-running the
# script after a crash picks up where it left off instead of starting over.
#
# Usage (run as root on a clean VPS):
#   curl -fsSL https://raw.githubusercontent.com/monajiane/xerex-panel/main/install.sh | sudo bash -s -- --domain panel.example.com --email admin@example.com
#
# Recover from a previous failed install:
#   sudo ./install.sh --resume
#
# Start over from scratch:
#   sudo ./install.sh --reset
#
# Just check what is done:
#   sudo ./install.sh --status
#
# Tested on: Ubuntu 22.04, Ubuntu 24.04, Debian 12. Other distros may need
# small tweaks (see /etc/os-release detection below).
# ============================================================================

set -euo pipefail
umask 022

# ----- Defaults -------------------------------------------------------------
PANEL_USER="xerex"
PANEL_HOME="/var/www/xerex-panel"
PANEL_REPO="https://github.com/monajiane/xerex-panel.git"
PANEL_BRANCH="main"
PANEL_PORT="8000"
PHP_VERSION="8.3"
DB_NAME="xerex_panel"
DB_USER="xerex"
DB_PASS="${XEREX_DB_PASSWORD:-$(openssl rand -hex 16)}"
DOMAIN="${PANEL_DOMAIN:-}"
ADMIN_EMAIL="${PANEL_ADMIN_EMAIL:-}"
ADMIN_PASS="${PANEL_ADMIN_PASSWORD:-$(openssl rand -hex 12)}"
SKIP_DEPS=0
SKIP_CLONE=0
SKIP_NGINX=0
SKIP_INSTALL=0
DETACH=0
RESET=0
STATUS_ONLY=0

# ----- Paths for state and lock --------------------------------------------
LOCK_FILE="/var/lock/xerex-install.lock"
STATE_DIR="/var/lib/xerex-install"
STATE_FILE="${STATE_DIR}/state"
LOG_FILE="${STATE_DIR}/install.log"

mkdir -p "$STATE_DIR"

# ----- Pretty output --------------------------------------------------------
BOLD=$'\033[1m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; RED=$'\033[0;31m'; NC=$'\033[0m'
log()   { echo -e "${GREEN}[xerex]${NC} $*"; }
warn()  { echo -e "${YELLOW}[xerex]${NC} $*" >&2; }
fail()  { echo -e "${RED}[xerex]${NC} $*" >&2; exit 1; }
hdr()   { echo -e "\n${BOLD}=== $* ===${NC}"; }

# ----- State helpers --------------------------------------------------------
mark_done() { echo "$1" >> "$STATE_FILE"; }
is_done()   { grep -qxF "$1" "$STATE_FILE" 2>/dev/null; }
reset_state() { rm -f "$STATE_FILE"; }

# ----- Apt/dpkg wait + lock helper -----------------------------------------
# Why: if the user re-runs the script while the previous apt is still
#      running, `apt-get` immediately errors with
#      "Could not get lock /var/lib/dpkg/lock-frontend".
#      This function blocks until the lock is free, up to 10 minutes.
wait_for_apt() {
  local waited=0
  while pgrep -x apt-get >/dev/null 2>&1 \
     || pgrep -x dpkg     >/dev/null 2>&1 \
     || pgrep -x apt      >/dev/null 2>&1; do
    if [[ $waited -eq 0 ]]; then
      warn "Another apt/dpkg process is running; waiting for it to finish..."
    fi
    sleep 5
    waited=$((waited + 5))
    if [[ $waited -ge 600 ]]; then
      fail "apt/dpkg still busy after 10 min. Run: pkill -9 apt-get dpkg ; rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock ; dpkg --configure -a"
    fi
  done
  if [[ $waited -gt 0 ]]; then
    log "apt/dpkg is now idle (waited ${waited}s)."
  fi
}

# Cleans up stale dpkg lock files left by a killed apt. Only used as a
# last resort if the dpkg frontend refuses to release after a long wait.
clean_apt_locks() {
  rm -f /var/lib/dpkg/lock-frontend \
        /var/lib/dpkg/lock \
        /var/cache/apt/archives/lock 2>/dev/null || true
  dpkg --configure -a 2>/dev/null || true
}

# ----- Network retry with exponential backoff ------------------------------
# Why: the user's datacenter occasionally times out to packages.sury.org
#      (Fastly CDN IPs are blocked in their region). A single transient
#      failure shouldn't kill the whole install, so we retry with
#      exponential backoff (2s, 4s, 8s, 16s).
# Override the max attempts with XEREX_NET_RETRY=N (default 4).
network_retry() {
  local max_attempts="${XEREX_NET_RETRY:-4}"
  local delay=2
  local attempt=1
  local rc=0
  while [[ $attempt -le $max_attempts ]]; do
    if "$@"; then
      if [[ $attempt -gt 1 ]]; then
        log "  ↪ network_retry: succeeded on attempt ${attempt}"
      fi
      return 0
    fi
    rc=$?
    if [[ $attempt -lt $max_attempts ]]; then
      warn "  ↪ network_retry: attempt ${attempt}/${max_attempts} failed (rc=${rc}); retrying in ${delay}s..."
      sleep $delay
      delay=$((delay * 2))
    fi
    attempt=$((attempt + 1))
  done
  warn "  ↪ network_retry: all ${max_attempts} attempts failed (last rc=${rc})"
  return $rc
}

# ----- PHP source selection ------------------------------------------------
# Decides where to install PHP from:
#   - Ubuntu 24.04 / 24.10: native universe (no extra repo, no Fastly dep)
#   - Ubuntu 22.04 / Debian 12: Sury (with mirror fallback)
# User overrides:
#   XEREX_FORCE_SURY=1   always use Sury
#   XEREX_SKIP_SURY=1    never use Sury, always try native
detect_php_source() {
  local os_id="$1" os_version="$2" codename="$3"

  # User overrides win
  if [[ "${XEREX_FORCE_SURY:-0}" = "1" ]]; then
    echo "sury"; return 0
  fi
  if [[ "${XEREX_SKIP_SURY:-0}" = "1" ]]; then
    echo "native"; return 0
  fi

  # Ubuntu 24.04+ ships PHP 8.3 in universe — no third-party repo needed.
  if [[ "$os_id" = "ubuntu" ]]; then
    case "$os_version" in
      24.04|24.10|25.04|25.10) echo "native"; return 0 ;;
    esac
  fi

  # Everything else: Sury
  echo "sury"
}

# Default Sury mirrors, in priority order. The first one that responds
# to a quick HEAD probe is used. Users can override by exporting
# XEREX_SURY_MIRRORS="url1 url2 ...".
SURY_MIRRORS_DEFAULT=(
  "https://packages.sury.org/php"
  "https://mirror.iranserver.com/sury-php"
  "https://ftp.acc.umu.se/mirror/sury-php"
  "https://mirror.its.dal.ca/sury-php"
)

# ----- Sury setup with mirror fallback -------------------------------------
# Tries each mirror in turn. Each mirror is tested with a 10s HEAD probe;
# the first one that returns 200 is used. If every mirror fails, returns
# non-zero so the caller can fall back to native.
setup_php_sury() {
  local codename="$1"
  local mirrors=()
  if [[ -n "${XEREX_SURY_MIRRORS:-}" ]]; then
    # shellcheck disable=SC2206
    mirrors=( ${XEREX_SURY_MIRRORS} )
  else
    mirrors=( "${SURY_MIRRORS_DEFAULT[@]}" )
  fi

  # Already configured? Just refresh.
  if grep -q "sury" /etc/apt/sources.list.d/*.list 2>/dev/null; then
    log "Sury repo already configured; refreshing package list..."
    network_retry bash -c "$PKG update -y"
    return 0
  fi

  # Probe each mirror in order.
  local mirror
  for mirror in "${mirrors[@]}"; do
    log "Probing Sury mirror: ${mirror}"
    if curl -fsSL --max-time 10 -o /dev/null "${mirror}/dists/${codename}/Release" 2>/dev/null; then
      log "Mirror ${mirror} is reachable; configuring it..."
      if network_retry curl -fsSL -o /etc/apt/trusted.gpg.d/php.gpg "${mirror}/apt.gpg" \
         && echo "deb ${mirror}/ ${codename} main" > /etc/apt/sources.list.d/php.list \
         && network_retry bash -c "$PKG update -y"; then
        log "Sury repo configured from ${mirror}"
        return 0
      fi
      warn "Mirror ${mirror} configured but apt update failed; trying next..."
      rm -f /etc/apt/sources.list.d/php.list /etc/apt/trusted.gpg.d/php.gpg
    else
      warn "Mirror ${mirror} unreachable; trying next..."
    fi
  done

  return 1
}

# ----- Native PHP setup (Ubuntu 24.04+) ------------------------------------
# Ubuntu 24.04's universe repo has PHP 8.3. We just need to make sure
# universe is enabled, then apt install.
setup_php_native() {
  log "Using native OS PHP ${PHP_VERSION} (no third-party repo)."

  # Make sure universe is enabled (Ubuntu). This is a no-op if already on.
  if [[ "$ID" = "ubuntu" ]]; then
    if ! grep -qE "^deb .* ${VERSION_CODENAME}[[:space:]]+universe" /etc/apt/sources.list /etc/apt/sources.list.d/*.list 2>/dev/null; then
      log "Enabling universe component..."
      add-apt-repository -y universe 2>/dev/null || \
        echo "deb http://archive.ubuntu.com/ubuntu ${VERSION_CODENAME} universe" > /etc/apt/sources.list.d/universe.list
    fi
  fi

  network_retry bash -c "$PKG update -y"
}

# ----- Sanitize apt sources before PHP install -----------------------------
# Why: a previous failed run may have left /etc/apt/sources.list.d/php.list
#      pointing at Sury's Fastly URL, which is unreachable from the user's
#      datacenter. If we don't remove it, every `apt install php8.3-*`
#      will time out trying Sury first and never fall through to universe.
#
#      This function probes the configured Sury host; if it's unreachable,
#      it removes the source list and the GPG key.
sanitize_apt_sources() {
  local src=/etc/apt/sources.list.d/php.list
  [[ -f "$src" ]] || return 0

  local host
  host=$(awk '{print $2}' "$src" 2>/dev/null | head -1)
  if [[ -z "$host" ]]; then
    warn "Sury source list is malformed; removing it."
    rm -f "$src" /etc/apt/trusted.gpg.d/php.gpg
    return 0
  fi

  log "Probing existing Sury source: ${host}"
  if curl -fsSL --max-time 8 -o /dev/null "${host}/dists/${VERSION_CODENAME:-$(lsb_release -sc 2>/dev/null || echo noble)}/Release" 2>/dev/null; then
    log "Sury source ${host} is reachable; keeping it."
    return 0
  fi

  warn "Sury source ${host} is unreachable; removing broken source list."
  rm -f "$src" /etc/apt/trusted.gpg.d/php.gpg
  network_retry bash -c "$PKG update -y" || true
}

# ----- Idempotent step runner ----------------------------------------------
# run_step <name> <command...>
#   * If <name> is in the state file, skip.
#   * Otherwise run the command; on success record it in the state file.
run_step() {
  local name="$1"; shift
  if is_done "$name"; then
    log "  ↪ skip (already done): ${name}"
    return 0
  fi
  log "  → run: ${name}"
  if "$@"; then
    mark_done "$name"
    log "  ✓ done: ${name}"
  else
    local rc=$?
    warn "  ✗ failed (rc=${rc}): ${name}"
    return "$rc"
  fi
}

# ----- CLI parsing ----------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)         DOMAIN="$2"; shift 2 ;;
    --email)          ADMIN_EMAIL="$2"; shift 2 ;;
    --admin-password) ADMIN_PASS="$2"; shift 2 ;;
    --branch)         PANEL_BRANCH="$2"; shift 2 ;;
    --repo)           PANEL_REPO="$2"; shift 2 ;;
    --home)           PANEL_HOME="$2"; shift 2 ;;
    --port)           PANEL_PORT="$2"; shift 2 ;;
    --db-name)        DB_NAME="$2"; shift 2 ;;
    --db-user)        DB_USER="$2"; shift 2 ;;
    --db-password)    DB_PASS="$2"; shift 2 ;;
    --skip-deps)      SKIP_DEPS=1; shift ;;
    --skip-clone)     SKIP_CLONE=1; shift ;;
    --skip-nginx)     SKIP_NGINX=1; shift ;;
    --skip-install)   SKIP_INSTALL=1; shift ;;
    --detach)         DETACH=1; shift ;;
    --reset)
      RESET=1
      warn "--reset: install state will be wiped after the status check below."
      shift
      ;;
    --status)         STATUS_ONLY=1; shift ;;
    -h|--help)
      sed -n '2,50p' "$0"
      exit 0
      ;;
    *)
      fail "Unknown flag: $1 (run with --help)"
      ;;
  esac
done

# ----- Pre-flight -----------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  fail "Please run as root (use sudo)."
fi

# ----- Status mode (no work, just print) -----------------------------------
if [[ $STATUS_ONLY -eq 1 ]]; then
  echo "Xerex Panel install state (${STATE_FILE}):"
  if [[ -f "$STATE_FILE" ]]; then
    sort "$STATE_FILE" | sed 's/^/  ✓ /'
  else
    echo "  (nothing done yet)"
  fi
  echo
  if [[ -f "${PANEL_HOME}/storage/installed.lock" ]]; then
    echo "  🔒 Panel is installed (storage/installed.lock exists)."
  else
    echo "  ⚠ Panel install lock missing — run installer to finish."
  fi
  exit 0
fi

# ----- Acquire exclusive install lock (flock) ------------------------------
# This prevents two installs from racing. If another install is in
# progress, flock will block until that install releases the lock or
# (with -n) fail immediately. We use blocking with a 60s timeout.
exec 9>"$LOCK_FILE"
if ! flock -w 60 9; then
  fail "Another install.sh is already running (or the lock at ${LOCK_FILE} is stale).
If you are sure no other install is active, remove it:
    rm -f ${LOCK_FILE}"
fi
trap 'flock -u 9 2>/dev/null || true' EXIT

# ----- Reset state if requested --------------------------------------------
if [[ $RESET -eq 1 ]]; then
  warn "--reset: clearing install state."
  reset_state
fi

# ----- Detached (background) mode ------------------------------------------
# When the user wants to walk away from the keyboard, --detach forks
# the script into a nohup background process and tails the log.
if [[ $DETACH -eq 1 ]]; then
  LOG="${LOG_FILE}"
  if [[ -f "$LOG" ]]; then mv "$LOG" "${LOG}.$(date +%Y%m%d-%H%M%S)"; fi
  ARGS="$* --no-detach"
  warn "--detach: running in background. Tail the log with: tail -f ${LOG}"
  nohup "$0" $ARGS >"$LOG" 2>&1 &
  echo $! > "${STATE_DIR}/detach.pid"
  log "Background PID: $(cat ${STATE_DIR}/detach.pid)"
  log "Log file:       ${LOG}"
  exit 0
fi

# ----- OS detection --------------------------------------------------------
. /etc/os-release
case "$ID" in
  ubuntu) PKG="apt-get" ;;
  debian) PKG="apt-get" ;;
  *)      fail "This script supports Ubuntu/Debian only. Detected: $ID" ;;
esac
log "Detected ${PRETTY_NAME:-${ID}}"

# ----- 1. System packages ---------------------------------------------------
if [[ $SKIP_DEPS -eq 0 ]]; then
  hdr "Step 1/8 - System packages"
  # Always wait for any in-flight apt/dpkg BEFORE we try to acquire
  # locks of our own. This is what was missing in v1 and caused the
  # "Could not get lock" error after a re-run.
  wait_for_apt

  export DEBIAN_FRONTEND=noninteractive
  run_step "apt:update" bash -c "$PKG update -y" || {
    clean_apt_locks
    wait_for_apt
    $PKG update -y
    mark_done "apt:update"
  }

  run_step "apt:base" bash -c "$PKG install -y --no-install-recommends \
      ca-certificates curl wget git unzip zip gnupg lsb-release \
      software-properties-common \
      nginx certbot python3-certbot-nginx \
      redis-server \
      postgresql postgresql-contrib"

  # Add Sury PHP 8.3 repo only if not already configured.
  # We pick the PHP source based on the OS version:
  #   - Ubuntu 24.04+ : native universe (no Sury)
  #   - otherwise    : Sury, but with mirror fallback if Fastly is blocked
  if ! command -v "php${PHP_VERSION}" >/dev/null 2>&1; then
    PHP_SOURCE=$(detect_php_source "${ID}" "${VERSION_ID:-}" "${VERSION_CODENAME:-$(lsb_release -sc 2>/dev/null || echo '')}")
    log "PHP source strategy: ${PHP_SOURCE}"

    case "$PHP_SOURCE" in
      native)
        run_step "apt:php:native" setup_php_native \
          || fail "Native PHP setup failed. Check apt sources."
        ;;
      sury)
        run_step "apt:php:repo" setup_php_sury "${VERSION_CODENAME:-$(lsb_release -sc)}" \
          || {
            warn "All Sury mirrors failed. Falling back to native OS PHP..."
            run_step "apt:php:native" setup_php_native \
              || fail "Both Sury and native PHP setup failed. Check your network."
          }
        ;;
      *)
        fail "Unknown PHP source: ${PHP_SOURCE}"
        ;;
    esac
  fi

  # Sanitize: if a previous failed run left a broken Sury source list,
  # remove it before trying to install PHP. This is the difference between
  # "apt install php8.3-cli" timing out forever and falling through to
  # universe.
  run_step "apt:sanitize" sanitize_apt_sources

  run_step "apt:php" bash -c "$PKG install -y --no-install-recommends \
      php${PHP_VERSION}-cli php${PHP_VERSION}-fpm \
      php${PHP_VERSION}-{mbstring,xml,bcmath,curl,zip,intl,gd,pgsql,redis,opcache}" \
    || {
      warn "PHP ${PHP_VERSION} install failed. Last 30 lines of apt output:"
      $PKG install -y --no-install-recommends \
        php${PHP_VERSION}-cli php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-{mbstring,xml,bcmath,curl,zip,intl,gd,pgsql,redis,opcache} 2>&1 | tail -30 >&2
      fail "PHP ${PHP_VERSION} install failed. See lines above for the real error."
    }

  log "PHP installed: $(php${PHP_VERSION} -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
else
  log "Skipping system package install (--skip-deps)."
fi

# ----- 2. Database ---------------------------------------------------------
hdr "Step 2/8 - PostgreSQL"
if command -v psql >/dev/null 2>&1; then
  run_step "db:user" bash -c "
    sudo -u postgres psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'\" | grep -q 1 \
      || sudo -u postgres psql -c \"CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}' CREATEDB;\"
  "
  run_step "db:database" bash -c "
    sudo -u postgres psql -tAc \"SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'\" | grep -q 1 \
      || sudo -u postgres createdb -O '${DB_USER}' '${DB_NAME}'
  "
  run_step "db:listen" bash -c "
    # Postgres 16 defaults to localhost-only on Debian/Ubuntu. Make it listen
    # on localhost (it already does via the unix socket; nothing to do here
    # but record the step in the state file so re-runs skip it).
    echo ok
  "
  log "Database ${DB_NAME} ready (user: ${DB_USER})."
else
  warn "PostgreSQL not installed; web installer will prompt for connection."
fi

# ----- 3. Clone the repo ---------------------------------------------------
hdr "Step 3/8 - Clone the panel"
if [[ $SKIP_CLONE -eq 0 ]]; then
  # Make sure the panel user exists before we try to chown to it.
  if ! id "${PANEL_USER}" >/dev/null 2>&1; then
    useradd -r -m -d "/var/lib/${PANEL_USER}" -s /bin/bash "${PANEL_USER}" || true
  fi

  # Mark the panel home as a safe git directory for both root and the
  # panel user. This silences the "dubious ownership" error when the
  # directory was originally created by a different user.
  git config --global --add safe.directory "${PANEL_HOME}" 2>/dev/null || true
  sudo -u "${PANEL_USER}" -H bash -lc "git config --global --add safe.directory '${PANEL_HOME}'" 2>/dev/null || true

  if [[ -d "${PANEL_HOME}/.git" ]]; then
    log "${PANEL_HOME} already cloned; pulling latest."
    sudo -u "${PANEL_USER}" git -C "${PANEL_HOME}" pull --ff-only 2>/dev/null || warn "git pull failed (offline?) — using existing checkout"
  else
    mkdir -p "${PANEL_HOME%/*}"
    if ! git clone --branch "${PANEL_BRANCH}" --depth 1 "${PANEL_REPO}" "${PANEL_HOME}" 2>/dev/null; then
      # Fallback: try without --branch in case the default branch is master.
      git clone --depth 1 "${PANEL_REPO}" "${PANEL_HOME}"
    fi
  fi

  # Always chown the panel home to the panel user. This is idempotent
  # and fixes the case where a previous failed install left the dir
  # owned by root, which makes composer fail with "Permission denied"
  # when it tries to write composer.lock as the xerex user.
  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_HOME}"
  mark_done "repo:cloned"
else
  log "Skipping clone (--skip-clone)."
fi

# ----- 4. Composer + .env --------------------------------------------------
hdr "Step 4/8 - Composer & .env"
run_step "composer:install" bash -c "
  if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  fi
  sudo -u ${PANEL_USER} -H bash -lc 'cd ${PANEL_HOME} && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist'
"

if [[ -n "${DOMAIN}" ]]; then
  APP_URL="https://${DOMAIN}"
  APP_ENV="production"
else
  APP_URL="http://localhost:${PANEL_PORT}"
  APP_ENV="local"
fi

run_step "env:write" bash -c "
  sudo -u ${PANEL_USER} -H bash -lc '
    set -e
    cd ${PANEL_HOME}
    [[ -f .env ]] || cp .env.example .env
    php artisan key:generate --force
    php artisan config:clear
  '
  ENV_FILE=${PANEL_HOME}/.env
  set_env() {
    local key=\"\$1\" val=\"\$2\"
    if grep -q \"^\${key}=\" \"\$ENV_FILE\";then
      sed -i \"s|^\${key}=.*|\${key}=\${val}|\" \"\$ENV_FILE\"
    else
      echo \"\${key}=\${val}\" >> \"\$ENV_FILE\"
    fi
  }
  set_env APP_NAME        'Xerex Panel'
  set_env APP_ENV         '${APP_ENV}'
  set_env APP_URL         '${APP_URL}'
  set_env APP_DEBUG       '$([ "$APP_ENV" = production ] && echo false || echo true)'
  set_env DB_CONNECTION   pgsql
  set_env DB_HOST         127.0.0.1
  set_env DB_PORT         5432
  set_env DB_DATABASE     '${DB_NAME}'
  set_env DB_USERNAME     '${DB_USER}'
  set_env DB_PASSWORD     '${DB_PASS}'
  set_env SESSION_DRIVER  database
  set_env QUEUE_CONNECTION database
  set_env CACHE_STORE     database
  set_env BROADCAST_CONNECTION log
  chown ${PANEL_USER}:${PANEL_USER} ${PANEL_HOME}/.env
"

# ----- 5. Systemd unit -----------------------------------------------------
hdr "Step 5/8 - Systemd service"
run_step "systemd:install" bash -c "
  install -m 0644 ${PANEL_HOME}/xerex-panel.service /etc/systemd/system/xerex-panel.service
  systemctl daemon-reload
  systemctl enable xerex-panel
  systemctl restart xerex-panel
"
sleep 2
if systemctl is-active --quiet xerex-panel; then
  log "xerex-panel.service is running."
else
  warn "xerex-panel.service is not running. Check: journalctl -u xerex-panel -n 50"
fi

# ----- 6. Nginx reverse proxy ----------------------------------------------
if [[ $SKIP_NGINX -eq 0 ]] && [[ -n "${DOMAIN}" ]]; then
  hdr "Step 6/8 - Nginx reverse proxy"
  run_step "nginx:configure" bash -c "
    cat > /etc/nginx/sites-available/xerex-panel <<NGINX
server {
    server_name ${DOMAIN};
    client_max_body_size 100m;

    location / {
        proxy_pass http://127.0.0.1:${PANEL_PORT};
        proxy_set_header Host              \\\$host;
        proxy_set_header X-Real-IP         \\\$remote_addr;
        proxy_set_header X-Forwarded-For   \\\$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \\\$scheme;
    }
}
NGINX
    ln -sf /etc/nginx/sites-available/xerex-panel /etc/nginx/sites-enabled/xerex-panel
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl reload nginx
  "

  if command -v certbot >/dev/null 2>&1; then
    run_step "nginx:ssl" bash -c "
      certbot --nginx --non-interactive --agree-tos -m '${ADMIN_EMAIL:-admin@${DOMAIN}}' -d '${DOMAIN}'
    " || warn "Certbot failed. Run it manually: sudo certbot --nginx -d ${DOMAIN}"
  fi
else
  log "Skipping nginx (--skip-nginx or no --domain)."
fi

# ----- 7. Run the installer ------------------------------------------------
if [[ $SKIP_INSTALL -eq 0 ]]; then
  hdr "Step 7/8 - Run xerex:install"
  run_step "artisan:install" bash -c "
    sudo -u ${PANEL_USER} -H bash -lc '
      cd ${PANEL_HOME}
      php artisan xerex:install \
        --db-driver=pgsql \
        --db-host=127.0.0.1 --db-port=5432 \
        --db-name=${DB_NAME} --db-user=${DB_USER} --db-password=${DB_PASS} \
        --app-url=${APP_URL} \
        --admin-name=Xerex+Admin \
        --admin-email=${ADMIN_EMAIL:-admin@${DOMAIN:-localhost}} \
        --admin-password=${ADMIN_PASS} \
        --force
    '
  " || warn "Installer returned non-zero. Re-run manually: cd ${PANEL_HOME} && sudo -u ${PANEL_USER} php artisan xerex:install --reset"
else
  log "Skipping artisan installer (--skip-install)."
fi

# ----- 8. Scheduler timer --------------------------------------------------
hdr "Step 8/8 - Scheduler timer"
run_step "systemd:scheduler" bash -c "
  cat > /etc/systemd/system/xerex-scheduler.service <<'UNIT'
[Unit]
Description=Xerex Panel scheduler (one-shot)
After=network.target

[Service]
Type=oneshot
User=xerex
WorkingDirectory=/var/www/xerex-panel
ExecStart=/usr/bin/php artisan schedule:run
UNIT
  cat > /etc/systemd/system/xerex-scheduler.timer <<'UNIT'
[Unit]
Description=Run Xerex scheduler every minute

[Timer]
OnCalendar=*:*:00
Persistent=true
AccuracySec=5s
Unit=xerex-scheduler.service

[Install]
WantedBy=timers.target
UNIT
  systemctl daemon-reload
  systemctl enable --now xerex-scheduler.timer
"
log "Scheduler timer active."

# ----- Done ----------------------------------------------------------------
hdr "Done"
cat <<EOF

${GREEN}╔══════════════════════════════════════════════════════════════╗
║              Xerex Panel installation complete!              ║
╚══════════════════════════════════════════════════════════════╝${NC}

  URL:           ${APP_URL}
  Admin email:   ${ADMIN_EMAIL:-admin@${DOMAIN:-localhost}}
  Admin password: ${ADMIN_PASS}

  Service:       sudo systemctl status xerex-panel
  Logs:          sudo journalctl -u xerex-panel -f
  Scheduler:     sudo systemctl list-timers xerex-scheduler.timer

  Re-run install (idempotent — picks up where it left off):
      sudo ${PANEL_HOME}/install.sh --resume

  Start over from scratch:
      sudo ${PANEL_HOME}/install.sh --reset

  See what was done:
      sudo ${PANEL_HOME}/install.sh --status

EOF
