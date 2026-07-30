#!/usr/bin/env bash
# ============================================================================
# Xerex Panel — Recovery script for a half-broken install
#
# Use this when the systemd unit is crash-looping because the database
# driver was switched to a backend whose tables don't exist yet
# (e.g. CACHE_STORE=database before the `cache` table was created),
# or when `php artisan migrate` is failing with "relation already exists"
# because an earlier interrupted run left the `sessions` table behind.
#
# The script is safe to re-run. It only does the following:
#
#   1. Stops the crash-looping service.
#   2. Pulls the latest code from the configured git remote.
#   3. Switches CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION to safe
#      file/sync backends so artisan commands don't blow up.
#   4. Runs the installer's built-in repair step, which drops the
#      orphan `sessions` table from the old users migration and
#      cleans up any "failed" rows in the migrations table.
#   5. Re-runs `php artisan migrate` (now idempotent).
#   6. Restores the production database backends.
#   7. Brings the service back up.
#
# Usage (run as root on the broken server):
#
#     cd /var/www/xerex-panel
#     sudo ./recover-install.sh
#
# ============================================================================

set -euo pipefail
umask 022

PANEL_HOME="/var/www/xerex-panel"
PANEL_USER="xerex"
SERVICE_NAME="xerex-panel"

BOLD=$'\033[1m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; RED=$'\033[0;31m'; NC=$'\033[0m'
log()  { echo -e "${GREEN}[recover]${NC} $*"; }
warn() { echo -e "${YELLOW}[recover]${NC} $*" >&2; }
fail() { echo -e "${RED}[recover]${NC} $*" >&2; exit 1; }
hdr()  { echo -e "\n${BOLD}=== $* ===${NC}"; }

# ----- Preflight ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    fail "Please run as root:  sudo $0"
fi
if [[ ! -d "${PANEL_HOME}" ]]; then
    fail "Panel directory ${PANEL_HOME} not found. Did the install run?"
fi
cd "${PANEL_HOME}"

if [[ ! -f .env ]]; then
    fail ".env not found in ${PANEL_HOME}. Re-run install.sh first."
fi

if ! id -u "${PANEL_USER}" >/dev/null 2>&1; then
    fail "Service user '${PANEL_USER}' does not exist. Re-run install.sh first."
fi

# ----- 1. Stop the crash-looping service ------------------------------------
hdr "1/7  Stop crash-looping service"
if systemctl is-active --quiet "${SERVICE_NAME}"; then
    log "Stopping ${SERVICE_NAME}..."
    systemctl stop "${SERVICE_NAME}" || true
    # Make sure the auto-restart storm is over before we touch anything.
    systemctl reset-failed "${SERVICE_NAME}" || true
else
    log "${SERVICE_NAME} is not active, nothing to stop."
fi

# ----- 2. Pull the latest code from git -------------------------------------
hdr "2/7  Pull latest code from git"
if [[ -d .git ]]; then
    log "git pull origin main (or current branch)..."
    sudo -u "${PANEL_USER}" git pull --ff-only 2>&1 | sed 's/^/  /'
else
    warn "No .git directory in ${PANEL_HOME}; skipping git pull."
    warn "If you installed from a tarball, re-download the latest release first."
fi

# Make sure storage dirs are still writable by the panel user after the pull.
chown -R "${PANEL_USER}:${PANEL_USER}" storage bootstrap/cache database 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache database 2>/dev/null || true

# ----- 3. Force safe storage backends ---------------------------------------
hdr "3/7  Switch CACHE/SESSION/QUEUE to file backends for the migration step"
# We touch .env directly here (not via `php artisan`) so the change
# sticks even if the framework can't boot due to a missing cache table.
_set_env() {
    local key="$1"; local val="$2"
    if grep -qE "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${val}|" .env
    else
        echo "${key}=${val}" >> .env
    fi
}
_set_env CACHE_STORE      "file"
_set_env SESSION_DRIVER   "file"
_set_env QUEUE_CONNECTION "sync"
chown "${PANEL_USER}:${PANEL_USER}" .env
log "  CACHE_STORE=file  SESSION_DRIVER=file  QUEUE_CONNECTION=sync"

# ----- 4. Self-heal the migrations table -------------------------------------
hdr "4/7  Repair migrations table (drop orphan sessions + failed rows)"
log "  removing any 'failed' migration rows whose target tables are missing"
log "  dropping the legacy 'sessions' table (the new users migration no longer creates it)"
sudo -u "${PANEL_USER}" php artisan xerex:repair-migrations --no-interaction || true
echo

# ----- 5. Re-run migrations -------------------------------------------------
hdr "5/7  Run migrations"
sudo -u "${PANEL_USER}" php artisan config:clear  || true
sudo -u "${PANEL_USER}" php artisan migrate --force 2>&1 | sed 's/^/  /'

# ----- 6. Restore production database backends -----------------------------
hdr "6/7  Restore CACHE/SESSION/QUEUE to database backend"
_set_env CACHE_STORE      "database"
_set_env SESSION_DRIVER   "database"
_set_env QUEUE_CONNECTION "database"
chown "${PANEL_USER}:${PANEL_USER}" .env
sudo -u "${PANEL_USER}" php artisan config:clear
sudo -u "${PANEL_USER}" php artisan cache:clear  || true
log "  CACHE_STORE=database  SESSION_DRIVER=database  QUEUE_CONNECTION=database"

# Make sure APP_KEY is set; key:generate is a no-op if a valid one is there.
if ! grep -qE "^APP_KEY=base64:" .env; then
    log "  regenerating APP_KEY (none found in .env)"
    sudo -u "${PANEL_USER}" php artisan key:generate --force
fi

# ----- 7. Bring the service back up -----------------------------------------
hdr "7/7  Start ${SERVICE_NAME}"
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}" >/dev/null 2>&1 || true
systemctl start  "${SERVICE_NAME}"
sleep 2

if systemctl is-active --quiet "${SERVICE_NAME}"; then
    log "Service is up. Tail of recent log:"
    journalctl -u "${SERVICE_NAME}" -n 15 --no-pager | sed 's/^/    /'
    echo
    log "Port 8000 listener:"
    ss -tlnp 2>/dev/null | grep ':8000' | sed 's/^/    /' || true
else
    warn "Service did NOT stay up. Last status:"
    systemctl status "${SERVICE_NAME}" --no-pager | sed 's/^/    /' || true
    fail "Recovery did not bring the service back up. See logs above."
fi

echo
hdr "Done"
log "If the panel still does not load, check:"
log "  - tail -n 100 /var/log/xerex-panel/panel.error.log"
log "  - sudo -u ${PANEL_USER} php -d variables_order=EGPCS artisan serve --host=127.0.0.1 --port=8000"
log "    (run by hand to see a real error instead of the systemd auto-restart noise)"
