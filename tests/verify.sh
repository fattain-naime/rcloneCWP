#!/usr/bin/env bash
# Phase 1 final verification — full clean cycle on the live CWP server:
#   drop orphans -> install x2 (idempotency) -> smoke suite -> checksums -> permissions
set -uo pipefail
PHP=/usr/local/cwp/php71/bin/php
REPO=/root/rcloneCWP
HOME_DIR=/usr/local/cwp/rcloneCWP
T3=/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php
LOG="$CLAUDE_JOB_DIR/tmp/verify.log"
: > "$LOG"

step() { echo -e "\n=== $1 ==="; }
pf()   { if [ "$1" -eq 0 ]; then echo "  PASS $2"; else echo "  FAIL $2"; fi; }

step "0. Clean slate before verification cycle"
# If already installed, run uninstaller first, then ensure all 8 tables are dropped
if [ -f "$HOME_DIR/uninstall.php" ]; then
    printf "n\nn\n" | $PHP "$HOME_DIR/uninstall.php" >>"$LOG" 2>&1 || true
fi
mysql -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS root_cwp.rclone_schedules, root_cwp.rclone_backups, root_cwp.rclone_jobs, root_cwp.rclone_logs, root_cwp.rclone_hooks, root_cwp.rclone_notifications, root_cwp.rclone_api_keys, root_cwp.rclone_destinations; SET FOREIGN_KEY_CHECKS=1;" >>"$LOG" 2>&1
[ $? -eq 0 ]; pf $? "clean slate drop"
COUNT=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='root_cwp' AND TABLE_NAME LIKE 'rclone_%';")
[ "$COUNT" = "0" ]; pf $? "0 rclone_* tables remain (got $COUNT)"

step "1. Deploy repo -> server (current working tree)"
\cp -f "$REPO/sql/install.sql" "$HOME_DIR/sql/install.sql"
\cp -f "$REPO/lib/"*.php "$HOME_DIR/lib/"
\cp -f "$REPO/install.php" "$REPO/uninstall.php" "$REPO/config.php" "$REPO/bootstrap.php" "$HOME_DIR/"
\cp -f "$REPO/rcloneCWP.php" /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php
\cp -f "$REPO/tests/smoke.php" "$HOME_DIR/tests/smoke.php"
pf $? "files deployed"

step "2. Install run 1"
cd "$HOME_DIR" && $PHP install.php >>"$LOG" 2>&1
RUN1=$?
[ $RUN1 -eq 0 ]; pf $RUN1 "install run 1 exits 0"
T=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='root_cwp' AND TABLE_NAME LIKE 'rclone_%';")
[ "$T" = "8" ]; pf $? "8 tables after run 1 (got $T)"

step "3. Install run 2 (idempotency)"
cd "$HOME_DIR" && $PHP install.php >>"$LOG" 2>&1
RUN2=$?
[ $RUN2 -eq 0 ]; pf $RUN2 "install run 2 exits 0"
T=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='root_cwp' AND TABLE_NAME LIKE 'rclone_%';")
[ "$T" = "8" ]; pf $? "still 8 tables (got $T)"
K=$(mysql -N -e "SELECT COUNT(*) FROM root_cwp.rclone_api_keys;")
[ "$K" = "1" ]; pf $? "exactly 1 api_keys row (got $K)"
M=$(grep -c 'rcloneCWP menu entry (begin)' "$T3")
[ "$M" = "1" ]; pf $? "exactly 1 menu block in 3rdparty.php (got $M)"

step "4. Smoke suite"
cd "$HOME_DIR" && $PHP tests/smoke.php >>"$LOG" 2>&1
SMOKE=$?
[ $SMOKE -eq 0 ]; pf $SMOKE "smoke.php all-pass (52 checks)"

step "5. CWP core untouched"
grep 'db_conn.php' "$CLAUDE_JOB_DIR/tmp/pre-install.md5" | md5sum -c >/dev/null 2>&1
pf $? "db_conn.php checksum unchanged"
# 3rdparty.php prefix before our marker must match original size/content
PRISTINE_MD5="a4085d122a57855236b87435a88d0df1"
BASE_MD5=$(sed -e '/<!-- rcloneCWP menu entry (begin) -->/,$d' "$T3" | md5sum | awk '{print $1}')
ORIG_BASE_MD5=$(sed -e '/<!-- rcloneCWP menu entry (begin) -->/,$d' "$T3" | head -n 12 | md5sum | awk '{print $1}') # verify non-empty
[ "$BASE_MD5" = "$PRISTINE_MD5" ]; pf $? "3rdparty.php core content before menu block untouched"

step "6. File permission audit"
P=$(stat -c '%a' "$HOME_DIR/key.bin")
[ "$P" = "600" ]; pf $? "key.bin is 0600 (got $P)"
P=$(stat -c '%a' /var/log/rcloneCWP)
[ "$P" = "700" ]; pf $? "log dir is 0700 (got $P)"
P=$(stat -c '%a' /var/cache/rcloneCWP)
[ "$P" = "700" ]; pf $? "cache dir is 0700 (got $P)"
P=$(stat -c '%a' "$HOME_DIR")
[ "$P" = "700" ]; pf $? "runtime home is 0700 (got $P)"
WW=$(find /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php -perm -o+w 2>/dev/null | wc -l)
[ "$WW" = "0" ]; pf $? "module entry not world-writable"

step "7. Full lint sweep (CWP PHP 7.2 = 7.1-floor compatible)"
LINT_FAIL=0
for f in "$REPO"/config.php "$REPO"/bootstrap.php "$REPO"/install.php "$REPO"/uninstall.php \
         "$REPO"/rcloneCWP.php "$REPO"/lib/*.php "$REPO"/tests/smoke.php; do
    $PHP -l "$f" >/dev/null 2>&1 || { echo "  lint FAIL: $f"; LINT_FAIL=1; }
done
[ $LINT_FAIL -eq 0 ]; pf $? "all source files lint clean"

step "8. Test uninstaller (clean teardown)"
printf "n\nn\n" | $PHP "$HOME_DIR/uninstall.php" >>"$LOG" 2>&1
pf $? "uninstall.php exits 0"
REMAINING_TABLES=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='root_cwp' AND TABLE_NAME LIKE 'rclone_%';")
[ "$REMAINING_TABLES" = "0" ]; pf $? "0 rclone_* tables remain after uninstall (got $REMAINING_TABLES)"
[ ! -f "$HOME_DIR/key.bin" ]; pf $? "encryption key removed"
M_AFTER=$(grep -c 'rcloneCWP menu entry (begin)' "$T3")
[ "$M_AFTER" = "0" ]; pf $? "menu entry removed from 3rdparty.php (got $M_AFTER)"
md5sum -c "$CLAUDE_JOB_DIR/tmp/pre-install.md5" >/dev/null 2>&1
pf $? "3rdparty.php restored byte-exact after uninstall"

step "9. Re-install to leave module deployed and operational"
cd "$HOME_DIR" && $PHP install.php >>"$LOG" 2>&1
RUN3=$?
[ $RUN3 -eq 0 ]; pf $RUN3 "re-install exits 0"
T_FINAL=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='root_cwp' AND TABLE_NAME LIKE 'rclone_%';")
[ "$T_FINAL" = "8" ]; pf $? "all 8 tables recreated (got $T_FINAL)"
[ -f "$HOME_DIR/key.bin" ]; pf $? "encryption key created"
M_FINAL=$(grep -c 'rcloneCWP menu entry (begin)' "$T3")
[ "$M_FINAL" = "1" ]; pf $? "menu entry active in 3rdparty.php (got $M_FINAL)"

echo
echo "=== verify.log tail ==="
tail -20 "$LOG"
