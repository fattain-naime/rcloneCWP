#!/usr/bin/env bash
# rcloneCWP One-Command Installer
#
# Usage: curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash
#
# Steps:
#   1. Preflight  — confirm CWP present, root user, curl/git available
#   2. Fetch      — clone repo (--depth 1)
#   3. Deploy     — copy web-exposed files to module dir, runtime files to off-htdocs home
#   4. Install    — run PHP installer (CLI): schema, key, dirs, menu entry
#   5. Report     — print admin URL and summary
set -euo pipefail

# ============================================================================
# CONFIGURATION — pinned at release (see plan §9)
# ============================================================================
REPO_URL="https://github.com/fattain_naive/rcloneCWP.git"
BRANCH="main"
MODULES_DIR="/usr/local/cwpsrv/htdocs/resources/admin/modules"
MODULE_FILE="${MODULES_DIR}/rcloneCWP.php"
HOME_DIR="/usr/local/cwp/rcloneCWP"
PHP_BIN="/usr/local/cwp/php71/bin/php"
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

# ============================================================================
# HELPERS
# ============================================================================
log()  { echo -e "\033[1;34m[install]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[ok]\033[0m $*"; }
err()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; }

# ============================================================================
# 1. PREFLIGHT
# ============================================================================
log "Preflight checks..."

if [[ $EUID -ne 0 ]]; then
    err "Must run as root (try: curl -sSL ... | sudo bash)"
    exit 1
fi

for cmd in curl git; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        err "Required command not found: $cmd"
        exit 1
    fi
done

if [[ ! -d "$MODULES_DIR" ]]; then
    err "CWP modules directory not found: $MODULES_DIR"
    err "Is CentOS Web Panel installed?"
    exit 1
fi

# Resolve PHP binary (fallback to 'php' in PATH)
if [[ ! -x "$PHP_BIN" ]]; then
    if command -v php >/dev/null 2>&1; then
        PHP_BIN=$(command -v php)
    else
        err "PHP binary not found at $PHP_BIN nor in PATH"
        exit 1
    fi
fi
ok "Using PHP: $PHP_BIN ($($PHP_BIN -r 'echo PHP_VERSION;'))"

# Check rclone presence (warn only — module can run without it until first backup)
RCLONE_BIN=$(command -v rclone || echo "")
if [[ -n "$RCLONE_BIN" ]]; then
    ok "rclone found: $RCLONE_BIN ($($RCLONE_BIN version 2>/dev/null | head -1))"
else
    log "WARNING: rclone not found — install it first: https://rclone.org/install/"
fi

# ============================================================================
# 2. FETCH
# ============================================================================
# RCLONECWP_LOCAL=<path> installs from a local checkout instead of GitHub
# (used for development/testing before the repo is public). Must be an
# absolute path to a directory the operator controls — never a URL or
# relative path.
if [[ -n "${RCLONECWP_LOCAL:-}" ]]; then
    log "Installing from local checkout: $RCLONECWP_LOCAL"
    case "$RCLONECWP_LOCAL" in
        /*) ;;  # absolute path: OK
        *) err "RCLONECWP_LOCAL must be an absolute path"; exit 1 ;;
    esac
    [[ -d "$RCLONECWP_LOCAL" ]] || { err "RCLONECWP_LOCAL not a directory"; exit 1; }
    cp -r "$RCLONECWP_LOCAL" "$TMP_DIR/repo"
    rm -rf "$TMP_DIR/repo/.git"
else
    if [[ ! "$BRANCH" =~ ^[a-zA-Z0-9._-]+$ ]]; then
        err "Invalid branch name: $BRANCH"
        exit 1
    fi
    if [[ ! "$REPO_URL" =~ ^https?://[a-zA-Z0-9._/-]+$ ]]; then
        err "Invalid repository URL: $REPO_URL"
        exit 1
    fi
    log "Fetching rcloneCWP from $REPO_URL (branch: $BRANCH)..."
    git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/repo" || {
        err "Failed to clone repository"
        exit 1
    }
fi
ok "Source ready at $TMP_DIR/repo"

# Verify expected layout
[[ -f "$TMP_DIR/repo/rcloneCWP.php" ]]   || { err "repo layout invalid (rcloneCWP.php missing)"; exit 1; }
[[ -d "$TMP_DIR/repo/lib" ]]             || { err "repo layout invalid (lib/ missing)"; exit 1; }
[[ -f "$TMP_DIR/repo/sql/install.sql" ]] || { err "repo layout invalid (sql/install.sql missing)"; exit 1; }

# ============================================================================
# 3. DEPLOY
# ============================================================================
# CWP's dispatcher includes modules as FLAT files: modules/<name>.php
# (observed pattern of all 203 stock modules). So the web-exposed surface is
# exactly ONE file; the CLI scripts live off-htdocs in HOME_DIR itself.
log "Deploying module entry (flat file) to $MODULES_DIR/rcloneCWP.php..."
cp "$TMP_DIR/repo/rcloneCWP.php" "$MODULES_DIR/rcloneCWP.php"
chmod 644 "$MODULES_DIR/rcloneCWP.php"
ok "Module entry deployed"

log "Deploying runtime files to $HOME_DIR..."
mkdir -p "$HOME_DIR/lib" "$HOME_DIR/sql" "$HOME_DIR/views" "$HOME_DIR/cron"
for f in config.php bootstrap.php install.php uninstall.php; do
    cp "$TMP_DIR/repo/$f" "$HOME_DIR/$f"
done
cp -r "$TMP_DIR/repo/lib/"* "$HOME_DIR/lib/"
cp -r "$TMP_DIR/repo/views/"* "$HOME_DIR/views/"
cp -r "$TMP_DIR/repo/cron/"* "$HOME_DIR/cron/"
cp "$TMP_DIR/repo/sql/install.sql" "$HOME_DIR/sql/"
chmod 700 "$HOME_DIR"
chmod 644 "$HOME_DIR/config.php" "$HOME_DIR/bootstrap.php" "$HOME_DIR/sql/install.sql"
find "$HOME_DIR/lib" -type d -exec chmod 755 {} +
find "$HOME_DIR/lib" -type f -exec chmod 644 {} +
find "$HOME_DIR/views" -type d -exec chmod 755 {} +
find "$HOME_DIR/views" -type f -exec chmod 644 {} +
find "$HOME_DIR/cron" -type d -exec chmod 755 {} +
find "$HOME_DIR/cron" -type f -exec chmod 755 {} +
chmod 700 "$HOME_DIR/install.php" "$HOME_DIR/uninstall.php"
chmod 755 "$HOME_DIR/lib" "$HOME_DIR/sql" "$HOME_DIR/views" "$HOME_DIR/cron"
ok "Runtime files deployed"

# ============================================================================
# 4. RUN PHP INSTALLER
# ============================================================================
log "Running PHP installer (schema, keys, dirs, menu entry)..."
cd "$HOME_DIR"
if ! "$PHP_BIN" install.php; then
    err "PHP installer failed — see output above"
    exit 1
fi

# ============================================================================
# 5. REPORT
# ============================================================================
SERVER_IP=$(curl -s --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')
echo
ok "=============================================="
ok " rcloneCWP installed successfully!"
ok "=============================================="
ok " Admin URL: https://${SERVER_IP}:2030/index.php?module=rcloneCWP"
ok " Runtime home: $HOME_DIR"
ok " Module entry: $MODULE_FILE"
echo
log "Uninstall anytime with:"
log "   $HOME_DIR/uninstall.sh (or: $PHP_BIN $HOME_DIR/uninstall.php)"
