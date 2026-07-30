#!/usr/bin/env bash
# ============================================================================
# Xerex Panel — post-install verification script.
#
# Run this on the server after install.sh finishes (or fails mid-way) to
# get a one-shot green/red report of what's working and what's broken.
#
# Usage (as root or with sudo):
#     sudo /var/www/xerex-panel/verify-install.sh
# ============================================================================

set -u
GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; YELLOW=$'\033[0;33m'; BOLD=$'\033[1m'; NC=$'\033[0m'
pass=0; fail=0
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; pass=$((pass+1)); }
bad()  { echo -e "${RED}[FAIL]${NC}  $*"; fail=$((fail+1)); }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

PANEL_HOME="${PANEL_HOME:-/var/www/xerex-panel}"
DB_NAME="${DB_NAME:-xerex_panel}"
DB_USER="${DB_USER:-xerex}"

echo -e "${BOLD}=== Xerex Panel post-install verification ===${NC}"
echo

# 1. PHP 8.3 installed --------------------------------------------------------
if command -v php8.3 >/dev/null 2>&1; then
  ok "php8.3 binary present (version: $(php8.3 -r 'echo PHP_VERSION;'))"
else
  bad "php8.3 not installed (run install.sh or apt install php8.3-cli php8.3-fpm ...)"
fi

# 2. Required PHP extensions -------------------------------------------------
for ext in cli fpm mbstring xml bcmath curl zip intl gd pgsql redis opcache; do
  if dpkg -l "php8.3-${ext}" 2>/dev/null | grep -q '^ii'; then
    ok "php8.3-${ext} package installed"
  else
    bad "php8.3-${ext} package missing"
  fi
done

# 3. Composer dependencies ---------------------------------------------------
if [[ -d "${PANEL_HOME}/vendor" ]]; then
  ok "Composer vendor/ directory exists"
else
  bad "${PANEL_HOME}/vendor missing — run: cd ${PANEL_HOME} && composer install"
fi

# 4. .env exists with APP_KEY -----------------------------------------------
if [[ -f "${PANEL_HOME}/.env" ]]; then
  if grep -qE '^APP_KEY=base64:[A-Za-z0-9+/=]+' "${PANEL_HOME}/.env"; then
    ok ".env exists and APP_KEY is set"
  else
    bad ".env exists but APP_KEY is missing or malformed"
  fi
else
  bad "${PANEL_HOME}/.env missing"
fi

# 5. Database connection -----------------------------------------------------
if command -v psql >/dev/null 2>&1 && [[ -f "${PANEL_HOME}/.env" ]]; then
  DB_PASS=$(grep '^DB_PASSWORD=' "${PANEL_HOME}/.env" 2>/dev/null | head -1 | cut -d= -f2-)
  if PGPASSWORD="${DB_PASS}" psql -h 127.0.0.1 -U "${DB_USER}" -d "${DB_NAME}" \
       -tAc 'SELECT 1' >/dev/null 2>&1; then
    ok "PostgreSQL '${DB_NAME}' reachable as user '${DB_USER}'"
  else
    bad "PostgreSQL '${DB_NAME}' NOT reachable — check DB creds and pg_hba.conf"
  fi
else
  warn "psql not available or .env missing — skipping DB check"
fi

# 6. Migrations ran ----------------------------------------------------------
if [[ -x "${PANEL_HOME}/artisan" ]] && [[ -d "${PANEL_HOME}/vendor" ]]; then
  if (cd "${PANEL_HOME}" && php artisan migrate:status 2>/dev/null | grep -qE '^\s*Y\s'); then
    ok "At least one migration has been run"
  else
    bad "No migrations have been run — execute: cd ${PANEL_HOME} && php artisan migrate --force"
  fi
else
  warn "artisan binary missing — skipping migration check"
fi

# 7. Install lock (means the full installer finished) ------------------------
if [[ -f "${PANEL_HOME}/storage/installed.lock" ]]; then
  ok "storage/installed.lock present — full installer completed"
else
  bad "storage/installed.lock MISSING — installer did not finish. Re-run: cd ${PANEL_HOME} && sudo -u xerex php artisan xerex:install --force"
fi

# 8. Systemd service --------------------------------------------------------
if systemctl is-active --quiet xerex-panel 2>/dev/null; then
  ok "xerex-panel.service is active (running)"
else
  bad "xerex-panel.service is NOT active — check: journalctl -u xerex-panel -n 50"
fi

# 9. Scheduler timer --------------------------------------------------------
if systemctl is-active --quiet xerex-scheduler.timer 2>/dev/null; then
  ok "xerex-scheduler.timer is active"
else
  warn "xerex-scheduler.timer is NOT active (artisan schedule:run won't fire automatically)"
fi

# 10. Nginx ------------------------------------------------------------------
if systemctl is-active --quiet nginx 2>/dev/null; then
  ok "nginx is active"
else
  warn "nginx not active (ok if you used --skip-nginx)"
fi

# 11. HTTP responds ----------------------------------------------------------
HTTP_OK=0
if curl -fsSL --max-time 5 -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/ 2>/dev/null | grep -qE '^(200|301|302)$'; then
  ok "Panel responds on http://127.0.0.1:8000 (direct)"
  HTTP_OK=1
elif curl -fsSL --max-time 5 -o /dev/null -w '%{http_code}' http://127.0.0.1/ 2>/dev/null | grep -qE '^(200|301|302)$'; then
  ok "Panel responds on http://127.0.0.1 (via nginx)"
  HTTP_OK=1
fi
if [[ $HTTP_OK -eq 0 ]]; then
  bad "Panel not responding on port 8000 or 80 — check: ss -tlnp | grep -E ':(80|8000) '"
fi

# 12. Redis ------------------------------------------------------------------
if systemctl is-active --quiet redis-server 2>/dev/null \
   || systemctl is-active --quiet redis 2>/dev/null \
   || pgrep -x redis-server >/dev/null 2>&1; then
  ok "redis is running"
else
  warn "redis is not running (cache/queue won't work)"
fi

# 13. Sury source hygiene (only if Sury was added) ---------------------------
if [[ -f /etc/apt/sources.list.d/php.list ]]; then
  HOST=$(awk '{print $2}' /etc/apt/sources.list.d/php.list 2>/dev/null | head -1)
  if [[ -n "${HOST}" ]] && ! curl -fsSL --max-time 5 -o /dev/null \
       "${HOST}/dists/$(. /etc/os-release 2>/dev/null && echo "${VERSION_CODENAME:-noble}")/Release" 2>/dev/null; then
    warn "Sury source ${HOST} is unreachable (script will sanitize on next run)"
  fi
fi

# Summary ---------------------------------------------------------------------
echo
echo -e "${BOLD}=== Summary ===${NC}"
echo -e "  passed: ${GREEN}${pass}${NC}"
echo -e "  failed: ${RED}${fail}${NC}"
echo
if [[ $fail -eq 0 ]]; then
  echo -e "${GREEN}✓ Xerex Panel is fully installed and reachable.${NC}"
  echo
  echo "  Open it in your browser: https://$(grep '^APP_URL=' "${PANEL_HOME}/.env" 2>/dev/null | cut -d= -f2- | sed 's|https\?://||')"
  echo "  Or:                      http://127.0.0.1:8000"
  echo
  echo "  Default admin login: $(grep '^ADMIN_EMAIL=' "${PANEL_HOME}/.env" 2>/dev/null | cut -d= -f2-)"
  echo "  (check install.log for the generated password if you didn't pass --admin-password)"
  echo
  echo "  Saved install log:    /var/lib/xerex-install/install.log"
  exit 0
else
  echo -e "${YELLOW}⚠ ${fail} check(s) failed. See lines above.${NC}"
  echo
  echo "  Common fixes:"
  echo "    1. Re-run the install script:      sudo ${PANEL_HOME}/install.sh --resume"
  echo "    2. Recover from a stuck apt:       sudo ${PANEL_HOME}/install-recover.sh"
  echo "    3. Re-run the panel installer:     cd ${PANEL_HOME} && sudo -u xerex php artisan xerex:install --force"
  echo "    4. See what was already done:      sudo ${PANEL_HOME}/install.sh --status"
  echo "    5. View install log:               sudo tail -100 /var/lib/xerex-install/install.log"
  exit 1
fi
