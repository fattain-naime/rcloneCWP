#!/usr/bin/env bash
# rcloneCWP Uninstaller — remote convenience wrapper
#
# The module is self-contained on the server after install, so uninstall
# normally does NOT need this script or the repo. Simply run:
#
#   /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/uninstall.php
#
# This wrapper is for users who want a one-liner. It downloads nothing but
# itself — it just invokes the already-installed module uninstaller.
set -euo pipefail

MODULE_FILE="/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php"
HOME_DIR="/usr/local/cwp/rcloneCWP"
PHP_BIN="/usr/local/cwp/php71/bin/php"

log()  { echo -e "\033[1;34m[uninstall]\033[0m $*"; }
err()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; }

if [[ $EUID -ne 0 ]]; then
    err "Must run as root"
    exit 1
fi

if [[ ! -f "$HOME_DIR/uninstall.php" ]]; then
    err "Module not found at $HOME_DIR/uninstall.php"
    err "Is rcloneCWP installed?"
    exit 1
fi

if [[ ! -x "$PHP_BIN" ]]; then
    PHP_BIN=$(command -v php || true)
    if [[ -z "$PHP_BIN" ]]; then
        err "PHP binary not found"
        exit 1
    fi
fi

log "Running module uninstaller (interactive — will ask about keeping data)..."
cd "$HOME_DIR"
"$PHP_BIN" uninstall.php "$@"

log "Done. Remove leftover files manually if desired:"
log "   rm -f $MODULE_FILE"
log "   rm -rf $HOME_DIR"
