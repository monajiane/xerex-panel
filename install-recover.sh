#!/usr/bin/env bash
# ============================================================================
# Xerex Panel — Recovery script for stuck installs.
#
# Use this when:
#   * install.sh got killed mid-way (SSH disconnect, OOM, ctrl+c).
#   * You see "Could not get lock /var/lib/dpkg/lock-frontend".
#   * Multiple installs started at the same time and left things in a
#     weird state.
#
# What it does:
#   1. Kills any stuck apt / dpkg processes (gracefully first, then -9).
#   2. Removes stale dpkg / apt lock files.
#   3. Runs `dpkg --configure -a` to repair half-installed packages.
#   4. Removes the Xerex install lock so a new install.sh can run.
#   5. Optionally clears the Xerex install state (so the next run starts
#      from scratch) or keeps it (so it resumes from the last step).
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/monajiane/xerex-panel/main/install-recover.sh | sudo bash
#
#   or, locally:
#   sudo ./install-recover.sh
#   sudo ./install-recover.sh --reset     # also wipe install state
#   sudo ./install-recover.sh --status    # just print what is broken
# ============================================================================

set -euo pipefail
umask 022

BOLD=$'\033[1m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; RED=$'\033[0;31m'; NC=$'\033[0m'
log()  { echo -e "${GREEN}[xerex-recover]${NC} $*"; }
warn() { echo -e "${YELLOW}[xerex-recover]${NC} $*" >&2; }
fail() { echo -e "${RED}[xerex-recover]${NC} $*" >&2; exit 1; }
hdr()  { echo -e "\n${BOLD}=== $* ===${NC}"; }

if [[ $EUID -ne 0 ]]; then
  fail "Please run as root (use sudo)."
fi

RESET=0
STATUS_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --reset)  RESET=1; shift ;;
    --status) STATUS_ONLY=1; shift ;;
    -h|--help)
      sed -n '2,30p' "$0"
      exit 0
      ;;
    *) fail "Unknown flag: $1" ;;
  esac
done

LOCK_FILE="/var/lock/xerex-install.lock"
STATE_DIR="/var/lib/xerex-install"
STATE_FILE="${STATE_DIR}/state"

# ----- Status: just print what is broken -----------------------------------
if [[ $STATUS_ONLY -eq 1 ]]; then
  hdr "Current state"
  echo "Stuck apt/dpkg processes:"
  if pgrep -af "apt-get|dpkg|apt " 2>/dev/null | grep -v grep; then
    echo "  (see above)"
  else
    echo "  (none)"
  fi
  echo
  echo "Dpkg locks present:"
  for f in /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock; do
    if [[ -e "$f" ]]; then
      echo "  ✓ $f (held by PID $(fuser "$f" 2>/dev/null || echo unknown))"
    else
      echo "  ✗ $f (absent)"
    fi
  done
  echo
  echo "Xerex install lock:"
  if [[ -e "$LOCK_FILE" ]]; then
    echo "  ✓ ${LOCK_FILE}"
  else
    echo "  ✗ ${LOCK_FILE} (absent)"
  fi
  echo
  echo "Xerex install state:"
  if [[ -f "$STATE_FILE" ]]; then
    sort "$STATE_FILE" | sed 's/^/  ✓ /'
  else
    echo "  (no state file)"
  fi
  exit 0
fi

# ----- 1. Kill stuck apt/dpkg ----------------------------------------------
hdr "1/5 - Killing stuck apt/dpkg processes"
RUNNING=0
for proc in apt-get dpkg apt; do
  if pgrep -x "$proc" >/dev/null 2>&1; then
    RUNNING=1
    log "Found running $proc — sending SIGTERM"
    pkill -TERM -x "$proc" 2>/dev/null || true
  fi
done

if [[ $RUNNING -eq 1 ]]; then
  log "Waiting 10s for graceful shutdown..."
  sleep 10
  for proc in apt-get dpkg apt; do
    if pgrep -x "$proc" >/dev/null 2>&1; then
      warn "$proc still alive — sending SIGKILL"
      pkill -KILL -x "$proc" 2>/dev/null || true
    fi
  done
  sleep 2
fi

if pgrep -af "apt-get|dpkg|apt " 2>/dev/null | grep -v grep >/dev/null; then
  warn "Some processes are still hanging. Force-killing all of them:"
  pkill -KILL -f "apt-get|dpkg|apt " 2>/dev/null || true
  sleep 2
fi

if pgrep -af "apt-get|dpkg|apt " 2>/dev/null | grep -v grep >/dev/null; then
  fail "Could not kill stuck apt/dpkg processes. Try rebooting: sudo reboot"
fi
log "No stuck processes remaining."

# ----- 2. Remove stale lock files ------------------------------------------
hdr "2/5 - Removing stale dpkg/apt locks"
for f in /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock; do
  if [[ -e "$f" ]]; then
    rm -fv "$f"
  else
    log "  ↪ $f already absent"
  fi
done

# ----- 3. Repair dpkg state ------------------------------------------------
hdr "3/5 - Repairing dpkg state"
if dpkg --configure -a 2>&1 | tee -a /tmp/dpkg-recover.log; then
  log "dpkg state repaired."
else
  warn "dpkg --configure -a reported errors. See /tmp/dpkg-recover.log."
fi

# ----- 4. Remove the Xerex install lock -----------------------------------
hdr "4/5 - Removing Xerex install lock"
if [[ -e "$LOCK_FILE" ]]; then
  rm -fv "$LOCK_FILE"
else
  log "  ↪ ${LOCK_FILE} already absent"
fi

# ----- 5. Reset install state if asked -------------------------------------
hdr "5/5 - Install state"
if [[ -f "$STATE_FILE" ]]; then
  if [[ $RESET -eq 1 ]]; then
    warn "--reset: removing install state file (next install starts from scratch)."
    rm -fv "$STATE_FILE"
  else
    log "Keeping existing install state. The next install.sh will resume from:"
    sort "$STATE_FILE" | sed 's/^/  ✓ /'
  fi
else
  log "No state file. The next install.sh will run every step."
fi

# ----- Done ----------------------------------------------------------------
hdr "Recovery complete"
cat <<EOF

${GREEN}╔══════════════════════════════════════════════════════════════╗
║          Xerex Panel recovery completed successfully         ║
╚══════════════════════════════════════════════════════════════╝${NC}

Next steps:

  1. Verify the system is healthy:
     sudo apt update && sudo apt upgrade -y

  2. Re-run the installer. It will pick up where it left off:
     cd /var/www/xerex-panel
     sudo ./install.sh --domain panel.xerex-app.ir --email monajiane@hotmail.com

  3. If you want a completely fresh install:
     sudo ./install.sh --reset

If install.sh still complains about a dpkg lock afterwards, run:
     sudo ./install-recover.sh --status    # see what is still broken
     sudo reboot                          # nuclear option

EOF
