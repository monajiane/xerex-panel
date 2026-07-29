#!/usr/bin/env bash
# ============================================================================
# Xerex Panel — One-shot bootstrap script for fresh Ubuntu/Debian servers.
#
# What it does:
#   1. Installs PHP 8.3, PostgreSQL, Redis, Nginx, Certbot and friends.
#   2. Clones the Xerex Panel into /var/www/xerex-panel.
#   3. Runs composer install, copies .env.example -> .env, generates APP_KEY.
#   4. Writes a sane production .env (APP_URL, DB creds, Redis, etc.).
#   5. Installs the systemd unit (xerox-panel.service) and enables it.
#   6. Runs `php artisan xerex:install` for the final migration + admin steps.
#
# Usage (run as root on a clean VPS):
#   curl -fsSL https://raw.githubusercontent.com/YOUR-ORG/xerex-panel/main/install.sh | sudo bash
#   # or, locally:
#   sudo ./install.sh --domain panel.example.com --email admin@example.com
#
# Tested on: Ubuntu 22.04, Debian 12. Other distros may need small tweaks.
# ============================================================================

set -euo pipefail

# ----- Defaults -------------------------------------------------------------
PANEL_USER="xerex"
PANEL_HOME="/var/www/xerex-panel"
PANEL_REPO="https://github.com/YOUR-ORG/xerex-panel.git"
PANEL_BRANCH="main"
PANEL_PORT="8000"
PHP_VERSION="8.3"
DB_NAME="xerex_panel"
DB_USER="xerex"
DB_PASS="$(openssl rand -hex 16)"
DOMAIN="${PANEL_DOMAIN:-}"
ADMIN_EMAIL="${PANEL_ADMIN_EMAIL:-}"
ADMIN_PASS="${PANEL_ADMIN_PASSWORD:-$(openssl rand -hex 12)}"
SKIP_DEPS=0
SKIP_CLONE=0
SKIP_NGINX=0
NONINTERACTIVE=0

# ----- CLI parsing ----------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)        DOMAIN="$2"; shift 2 ;;
    --email)         ADMIN_EMAIL="$2"; shift 2 ;;
    --admin-password) ADMIN_PASS="$2"; shift 2 ;;
    --branch)        PANEL_BRANCH="$2"; shift 2 ;;
    --repo)          PANEL_REPO="$2"; shift 2 ;;
    --home)          PANEL_HOME="$2"; shift 2 ;;
    --port)          PANEL_PORT="$2"; shift 2 ;;
    --skip-deps)     SKIP_DEPS=1; shift ;;
    --skip-clone)    SKIP_CLONE=1; shift ;;
    --skip-nginx)    SKIP_NGINX=1; shift ;;
    --noninteractive) NONINTERACTIVE=1; shift ;;
    -h|--help)
      sed -n '2,30p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown flag: $1" >&2
      exit 1
      ;;
  esac
done

# ----- Pretty output --------------------------------------------------------
BOLD=$'\033[1m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; RED=$'\033[0;31m'; NC=$'\033[0m'
log()   { echo -e "${GREEN}[xerex]${NC} $*"; }
warn()  { echo -e "${YELLOW}[xerex]${NC} $*" >&2; }
fail()  { echo -e "${RED}[xerex]${NC} $*" >&2; exit 1; }
hdr()   { echo -e "\n${BOLD}=== $* ===${NC}"; }

# ----- Pre-flight -----------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  fail "Please run as root (use sudo)."
fi

. /etc/os-release
case "$ID" in
  ubuntu) PKG="apt-get" ;;
  debian) PKG="apt-get" ;;
  *)      fail "This script supports Ubuntu/Debian only. Detected: $ID" ;;
esac

# ----- Step 1: System packages ----------------------------------------------
if [[ $SKIP_DEPS -eq 0 ]]; then
  hdr "Installing system packages"
  export DEBIAN_FRONTEND=noninteractive
  $PKG update -y
  $PKG install -y --no-install-recommends \
      ca-certificates curl wget git unzip zip \
      software-properties-common gnupg lsb-release \
      nginx certbot python3-certbot-nginx \
      redis-server \
      postgresql postgresql-contrib

  # PHP 8.3 (use sury repo on older distros).
  if ! command -v php${PHP_VERSION} >/dev/null 2>&1; then
    log "Adding PHP ${PHP_VERSION} repository (Sury)"
    wget -qO /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" >/etc/apt/sources.list.d/php.list
    $PKG update -y
  fi
  $PKG install -y --no-install-recommends \
      php${PHP_VERSION}-cli php${PHP_VERSION}-fpm \
      php${PHP_VERSION}-{mbstring,xml,bcmath,curl,zip,intl,gd,pgsql,redis,opcache} \
      php${PHP_VERSION}-{tokenizer,fileinfo,ctype,dom,simplexml}
  log "PHP $(php${PHP_VERSION} -r 'echo PHP_VERSION;') installed."
else
  log "Skipping system package install (--skip-deps)."
fi

# ----- Step 2: Database -----------------------------------------------------
hdr "Configuring PostgreSQL"
if command -v psql >/dev/null 2>&1; then
  sudo -u postgres psql -tAc \
      "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 \
    || sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}' CREATEDB;"
  sudo -u postgres psql -tAc \
      "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 \
    || sudo -u postgres createdb -O "${DB_USER}" "${DB_NAME}"
  log "Database ${DB_NAME} ready (user: ${DB_USER})."
else
  warn "PostgreSQL not installed; web installer will prompt for connection."
fi

# ----- Step 3: Clone the repo -----------------------------------------------
hdr "Cloning the panel"
if [[ $SKIP_CLONE -eq 0 ]]; then
  if [[ -d "${PANEL_HOME}/.git" ]]; then
    log "${PANEL_HOME} already has a git checkout; pulling latest."
    sudo -u "${PANEL_USER}" git -C "${PANEL_HOME}" pull --ff-only
  else
    mkdir -p "${PANEL_HOME%/*}"
    git clone --branch "${PANEL_BRANCH}" --depth 1 "${PANEL_REPO}" "${PANEL_HOME}"
    chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_HOME}"
  fi
fi

# ----- Step 4: Composer + .env ----------------------------------------------
hdr "Installing PHP dependencies"
sudo -u "${PANEL_USER}" -H bash -lc "
  set -e
  cd ${PANEL_HOME}
  if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  fi
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  [[ -f .env ]] || cp .env.example .env
"

if [[ -n "${DOMAIN}" ]]; then
  APP_URL="https://${DOMAIN}"
  APP_ENV="production"
else
  APP_URL="http://localhost:${PANEL_PORT}"
  APP_ENV="local"
fi

sudo -u "${PANEL_USER}" -H bash -lc "
  set -e
  cd ${PANEL_HOME}
  php artisan key:generate --force
  php artisan config:clear
"

# Patch the .env in place with the chosen DB / app settings.
ENV_FILE="${PANEL_HOME}/.env"
set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV_FILE"
  else
    echo "${key}=${val}" >> "$ENV_FILE"
  fi
}
set_env APP_NAME        '"Xerex Panel"'
set_env APP_ENV         "${APP_ENV}"
set_env APP_URL         "${APP_URL}"
set_env APP_DEBUG       "$([[ $APP_ENV == production ]] && echo false || echo true)"
set_env DB_CONNECTION   pgsql
set_env DB_HOST         127.0.0.1
set_env DB_PORT         5432
set_env DB_DATABASE     "${DB_NAME}"
set_env DB_USERNAME     "${DB_USER}"
set_env DB_PASSWORD     "${DB_PASS}"
set_env SESSION_DRIVER  database
set_env QUEUE_CONNECTION database
set_env CACHE_STORE     database
set_env BROADCAST_CONNECTION log
chown "${PANEL_USER}:${PANEL_USER}" "${ENV_FILE}"

# ----- Step 5: Systemd unit -------------------------------------------------
hdr "Installing systemd service"
install -m 0644 "${PANEL_HOME}/xerex-panel.service" /etc/systemd/system/xerex-panel.service
systemctl daemon-reload
systemctl enable xerex-panel
systemctl restart xerex-panel
sleep 1
systemctl is-active --quiet xerex-panel \
  &&log "xerex-panel.service is running." \
  || warn "xerex-panel.service is not running. Check: journalctl -u xerex-panel -n 50"

# ----- Step 6: Nginx reverse proxy ------------------------------------------
if [[ $SKIP_NGINX -eq 0 ]] && [[ -n "${DOMAIN}" ]]; then
  hdr "Configuring Nginx"
  cat > /etc/nginx/sites-available/xerex-panel <<NGINX
server {
    server_name ${DOMAIN};
    client_max_body_size 100m;

    location / {
        proxy_pass http://127.0.0.1:${PANEL_PORT};
        proxy_set_header Host              \$host;
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
  ln -sf /etc/nginx/sites-available/xerex-panel /etc/nginx/sites-enabled/xerex-panel
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx

  if command -v certbot >/dev/null 2>&1; then
    certbot --nginx --non-interactive --agree-tos -m "${ADMIN_EMAIL:-admin@${DOMAIN}}" -d "${DOMAIN}" || \
      warn "Certbot failed. Run it manually: sudo certbot --nginx -d ${DOMAIN}"
  fi
fi

# ----- Step 7: Run the installer --------------------------------------------
hdr "Running the panel installer"
sudo -u "${PANEL_USER}" -H bash -lc "
  cd ${PANEL_HOME}
  php artisan xerex:install \
    --no-migrate=false \
    --db-driver=pgsql \
    --db-host=127.0.0.1 --db-port=5432 \
    --db-name='${DB_NAME}' --db-user='${DB_USER}' --db-password='${DB_PASS}' \
    --app-url='${APP_URL}' \
    --admin-name='Xerex Admin' \
    --admin-email='${ADMIN_EMAIL:-admin@${DOMAIN:-localhost}}' \
    --admin-password='${ADMIN_PASS}' \
    --force
" || warn "Installer returned non-zero. Re-run manually: cd ${PANEL_HOME} && php artisan xerex:install --reset"

# ----- Step 8: Scheduler worker ---------------------------------------------
hdr "Adding scheduler timer"
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

[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now xerex-scheduler.timer
log "Scheduler timer active."

# ----- Done ------------------------------------------------------------------
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

  Re-run install:
      cd ${PANEL_HOME} && sudo -u xerex php artisan xerex:install --reset

EOF
