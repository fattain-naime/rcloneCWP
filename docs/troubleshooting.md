# rcloneCWP Troubleshooting Guide

## Module Not Appearing in CWP Sidebar

### Symptoms
- No "rcloneCWP" menu item in CWP admin panel
- 404 when accessing `https://server:2030/index.php?module=rcloneCWP`

### Checks

```bash
# Verify module entry exists
ls -la /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php

# Verify module file is readable
head -5 /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php

# Verify 3rdparty menu registration
grep -n "rcloneCWP" /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php
```

### Fixes

**If module file missing:**
```bash
# Re-run installer
curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash
```

**If 3rdparty registration missing:**
Add to `/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php`:
```php
[
    'title' => 'rcloneCWP',
    'file'  => 'resources/admin/modules/rcloneCWP.php',
    'icon'  => 'fa-hdd-o',
],
```

**Clear CWP cache and browser cache:**
```bash
# Clear CWP compiled templates
rm -f /usr/local/cwpsrv/htdocs/resources/admin/templates_c/*.php
systemctl reload cwpsrv
```

---

## rclone Binary Not Found

### Symptoms
- Backup fails with "rclone command not found"
- Settings tab shows "Not installed" for rclone binary

### Checks

```bash
# Check if rclone exists
which rclone
rclone version

# Check PHP's view of PATH
/usr/local/cwp/php71/bin/php -r 'echo getenv("PATH");'
```

### Fixes

**Install rclone:**
```bash
curl https://rclone.org/install.sh | bash

# Verify version >= 1.75.0
rclone version
```

**Set fallback path in config.php:**
```php
// In /usr/local/cwp/rcloneCWP/config.php
define('RCLONE_BIN_PATH', '/usr/local/bin/rclone'); // or wherever it's installed
```

**For cron jobs, PATH may differ — use full path:**
```bash
# Edit crontab manually if needed
crontab -e
# Add full path to PHP binary
*/5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1
```

---

## Database Connection Errors

### Symptoms
- "Database connection failed" on module load
- Installer reports schema failure
- PHP fatal error about PDO/MySQL

### Checks

```bash
# Verify database credentials from CWP config
cat /usr/local/cwpsrv/htdocs/resources/admin/include/db_conn.php

# Test direct database connection
mysql -u root -p$(grep 'dbpass' /usr/local/cwpsrv/htdocs/resources/admin/include/db_conn.php | cut -d"'" -f2) -e "USE root_cwp; SHOW TABLES LIKE 'rclone_%';"

# Check MySQL service
systemctl status mariadb
systemctl status mysql

# Check if database exists
mysql -u root -p -e "SHOW DATABASES LIKE 'root_cwp';"
```

### Fixes

**Wrong credentials:**
The module reads CWP's db_conn.php at runtime — ensure it hasn't been modified. If your CWP uses a different credential file, edit `/usr/local/cwp/rcloneCWP/bootstrap.php` to point to it.

**MySQL not running:**
```bash
systemctl start mariadb
systemctl enable mariadb
```

**Missing root_cwp database:**
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS root_cwp;"
```

**Table prefix conflicts:**
Check no existing tables clash with `rclone_` prefix:
```bash
mysql -u root -p root_cwp -e "SHOW TABLES LIKE 'rclone_%';"
```

---

## CSRF Token Errors

### Symptoms
- "Invalid or expired CSRF security token. Please refresh the page."
- AJAX calls fail with 400/403
- All forms submit but return errors

### Causes
- Session expired (long idle)
- Browser with cookies blocked
- API calls missing header
- Multiple tabs/windows with stale tokens
- Reverse proxy stripping headers

### Fixes

**Web UI:**
- Refresh the page (generates new token)
- Re-login to CWP admin

**API calls:**
Always include CSRF token in every request:
```bash
# Get token from page source or session
TOKEN="your_csrf_token_here"

# Include in request
curl -X POST "https://server:2030/index.php?module=rcloneCWP&ajax=run_job" \
  -H "X-CSRF-Token: $TOKEN" \
  -d "csrf_token=$TOKEN&job_id=1"
```

**If tokens constantly expire:**
Check session storage and cookie settings:
```bash
# Verify PHP sessions directory exists and is writable
ls -la /tmp/sessions/
mkdir -p /tmp/sessions
chmod 1733 /tmp/sessions
```

**Reverse proxy header check:**
If behind nginx/HAProxy, ensure `X-CSRF-Token` header is forwarded:
```nginx
proxy_pass_request_headers on;
proxy_set_header X-CSRF-Token $http_x_csrf_token;
```

---

## Backup Job Fails to Start

### Symptoms
- Job shows "failed" or "error" status
- Nothing happens when clicking "Run Now"
- Log shows "job not found" or similar

### Diagnostic Steps

```bash
# 1. Check job exists in database
mysql -u root -p root_cwp -e "SELECT id, name, status FROM rclone_jobs LIMIT 10;"

# 2. Check destination exists and is accessible
mysql -u root -p root_cwp -e "SELECT id, name, type FROM rclone_destinations;"
rclone ls remote:bucket --config=/etc/rclone/rclone.conf

# 3. View today's log
tail -50 /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log

# 4. Check file permissions
ls -la /usr/local/cwp/rcloneCWP/lib/
ls -la /etc/rclone/rclone.conf

# 5. Test rclone directly
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rcloneCWP system:status
```

### Common Fixes

**Destination config error:**
The module reads destination config as a JSON blob from database — credentials are AES-256-GCM encrypted. Verify encryption key exists:
```bash
ls -la /usr/local/cwp/rcloneCWP/.encryption.key
cat /usr/local/cwp/rcloneCWP/.encryption.key | wc -c  # Should be 32 bytes
```

**Re-encrypt credentials:**
If key was lost/regenerated, destinations must be re-saved:
```bash
# Delete and recreate destination (re-encrypts with current key)
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rcloneCWP destination:delete <id>
# Re-add via UI or CLI
```

**PHP version compatibility:**
```bash
/usr/local/cwp/php71/bin/php -v
# Must be >= 7.1
```

**Missing PHP extensions:**
```bash
/usr/local/cwp/php71/bin/php -m | grep -iE 'openssl|pdo_mysql|mbstring|json'
```

---

## Restore Fails

### Symptoms
- Restore button returns error
- Restore job hangs then fails
- Partial restore errors with checksum mismatch

### Diagnostic Steps

```bash
# 1. Check snapshot exists on destination
rclone lsd remote:bucket/path

# 2. Check restore destination permissions
ls -la /home/restore/target  # Ensure writable

# 3. View logs
tail -100 /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log

# 4. Dry run first
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rclone-restore run \
  --destination=<dest_id> \
  --snapshot=<snapshot_id> \
  --target=/tmp/test-restore \
  --dry-run
```

### Common Fixes

**Network timeout:**
Increase rclone timeout in destination config:
```json
{"extra_args": "--timeout=1h"}
```

**Disk space:**
```bash
df -h /home/
```

**Interrupted restore — resume:**
rclone supports `--ignore-existing` flag via extra args. Retrying will skip restored files.

**Cross-server restore auth:**
Ensure target server has rclone configured with same destination remote, or use `rsync`/`sftp` method instead.

---

## Cron Jobs Not Running

### Symptoms
- Schedules tab shows "installed" but backups don't run
- Log shows no cron activity
- Manual job run works, scheduled doesn't

### Diagnostic Steps

```bash
# 1. Check crontab entry exists
crontab -l | grep rcloneCWP

# 2. Check cron log
tail -50 /var/log/cron
journalctl -u crond --since "1 hour ago"

# 3. Check rcloneCWP cron log
tail -50 /var/log/rcloneCWP_cron.log

# 4. Manually run cron script
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php

# 5. Check schedule status in database
mysql -u root -p root_cwp -e "SELECT id, name, cron, next_run, active FROM rclone_schedules;"
```

### Common Fixes

**Crontab not installed:**
Go to Settings → Crontab Management → Install Crontab, or:
```bash
(crontab -l 2>/dev/null; echo "*/5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1") | crontab -
```

**Cron service not running:**
```bash
systemctl status crond
systemctl restart crond
systemctl enable crond
```

**PHP binary path wrong:**
The cron entry hardcodes `/usr/local/cwp/php71/bin/php`. If PHP is elsewhere:
```bash
which php
# Update crontab with correct path
```

**Script permission denied:**
```bash
ls -la /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php
chmod 755 /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php
```

**CWP cron isolation:**
CWP may restrict crontab for system cron. Use root's crontab explicitly:
```bash
crontab -u root -e
```

---

## Notification Delivery Issues

### Symptoms
- Hooks run but no notification sent
- Email never arrives
- Telegram/Discord/Slack messages not received

### Diagnostic Steps

```bash
# 1. Check hook execution logs
grep -i "hook\|notification" /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log

# 2. Test SMTP connectivity (email)
telnet smtp.example.com 587
# Or:
nc -zv smtp.example.com 587

# 3. Test webhook endpoint
curl -X POST "https://hooks.slack.com/your-webhook" -d '{"text":"test"}'

# 4. Test Telegram
curl -s "https://api.telegram.org/bot<TOKEN>/getMe"

# 5. Check notification status in database
mysql -u root -p root_cwp -e "SELECT * FROM rclone_notifications;"
```

### Common Fixes

**SMTP rejected:**
- Verify authentication credentials
- Enable TLS/SSL (`port 587` for STARTTLS, `port 465` for SSL)
- Check spam folder

**Webhook 403/401:**
- Regenerate webhook URL (Slack/Discord revoke/recreate)
- Check IP allowlist on webhook endpoint

**Telegram bot not responding:**
- Verify bot token with `@BotFather`
- Send a message to bot first (required for user bot tokens)
- Use channel chat ID (starts with `-100`)

**Hook script errors:**
```bash
# Test hook script manually
/usr/local/cwp/php71/bin/php /path/to/hook.php
# Check shebang line
head -1 /path/to/hook.sh
```

---

## Permission Errors

### Symptoms
- "Permission denied" when module loads
- rclone can't write to config
- PHP can't read log files
- Files owned by root instead of expected user

### Critical Rule
All files under `/home/<user>/` MUST stay `<user>:<user>` — never change to root.

### Diagnostic Commands

```bash
# Check module file permissions
ls -la /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php
# Expected: -rw-r--r-- root root

# Check runtime home permissions
ls -la /usr/local/cwp/rcloneCWP/
# Expected: drwx------ root root

# Check lib files
ls -la /usr/local/cwp/rcloneCWP/lib/
# Expected: drwxr-xr-x root root (dirs), -rw-r--r-- root root (files)

# Check rclone config
ls -la /etc/rclone/rclone.conf
# Expected: -rw------- root root

# Check log directory
ls -la /var/log/rcloneCWP/
# Expected: drwxr-xr-x root root
```

### Fixes

**Restore runtime home permissions:**
```bash
chmod 700 /usr/local/cwp/rcloneCWP
find /usr/local/cwp/rcloneCWP/lib -type d -exec chmod 755 {} +
find /usr/local/cwp/rcloneCWP/lib -type f -exec chmod 644 {} +
find /usr/local/cwp/rcloneCWP/views -type d -exec chmod 755 {} +
find /usr/local/cwp/rcloneCWP/views -type f -exec chmod 644 {} +
find /usr/local/cwp/rcloneCWP/cron -type d -exec chmod 755 {} +
find /usr/local/cwp/rcloneCWP/cron -type f -exec chmod 755 {} +
chmod 700 /usr/local/cwp/rcloneCWP/install.php /usr/local/cwp/rcloneCWP/uninstall.php
chmod 644 /usr/local/cwp/rcloneCWP/config.php /usr/local/cwp/rcloneCWP/bootstrap.php /usr/local/cwp/rcloneCWP/sql/install.sql
```

**Restore encryption key permissions:**
```bash
chmod 600 /usr/local/cwp/rcloneCWP/.encryption.key
chown root:root /usr/local/cwp/rcloneCWP/.encryption.key
```

**rclone config permissions:**
```bash
chmod 600 /etc/rclone/rclone.conf
chown root:root /etc/rclone/rclone.conf
```

**If user files were accidentally changed:**
```bash
# NEVER run chown -R root /home/
# Instead fix specific paths:
chown -R builderh:builderh /home/builderh/
chown -R owndemo:owndemo /home/owndemo/
```

---

## How to Check Logs

### Module Activity Logs (Web UI)
Go to **Settings → Activity Logs** tab — shows last 100 entries from today's log, color-coded by severity.

### Log File Location
```
/var/log/rcloneCWP/rcloneCWP-YYYY-MM-DD.log
```

### View Recent Logs

```bash
# Today's log
tail -f /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log

# Yesterday's log
tail -f /var/log/rcloneCWP/rcloneCWP-$(date -d yesterday +%Y-%m-%d).log

# Search for errors
grep -i "error\|fail\|critical" /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log

# Filter by job ID
grep "<job_id>" /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log

# Show last N lines
tail -200 /var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log
```

### Cron Log
```bash
# Cron runner log
tail -f /var/log/rcloneCWP_cron.log

# System cron log
tail -f /var/log/cron
```

### rclone Logs (Verbose)
Enable rclone debug output in destination extra_args:
```json
{"extra_args": "--verbose --log-file=/var/log/rcloneCWP/rclone-debug.log --log-level=DEBUG"}
```

---

## How to Run Diagnostics

### Built-in Diagnostic Command
```bash
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rcloneCWP system:status
```

Output includes:
- PHP version and modules
- rclone version and binary path
- Database connection status
- Encryption key status
- Crontab status
- Directory permissions check

### Full Diagnostic Script
```bash
#!/bin/bash
# Run as root — saves to /tmp/rcloneCWP-diag-$(date +%Y%m%d).txt

echo "=== rcloneCWP Diagnostics $(date) ==="
echo

echo "--- PHP ---"
/usr/local/cwp/php71/bin/php -v
/usr/local/cwp/php71/bin/php -m | grep -iE 'openssl|pdo_mysql|mbstring|json'

echo
echo "--- rclone ---"
rclone version 2>/dev/null || echo "rclone NOT INSTALLED"
which rclone

echo
echo "--- Database ---"
mysql -u root -p -e "SELECT COUNT(*) AS jobs FROM root_cwp.rclone_jobs;" 2>/dev/null
mysql -u root -p -e "SELECT COUNT(*) AS destinations FROM root_cwp.rclone_destinations;" 2>/dev/null

echo
echo "--- Encryption Key ---"
ls -la /usr/local/cwp/rcloneCWP/.encryption.key 2>/dev/null || echo "MISSING"
wc -c < /usr/local/cwp/rcloneCWP/.encryption.key 2>/dev/null

echo
echo "--- Crontab ---"
crontab -l 2>/dev/null | grep rcloneCWP || echo "NOT INSTALLED"

echo
echo "--- Module Entry ---"
ls -la /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php 2>/dev/null || echo "MISSING"

echo
echo "--- Runtime Home ---"
ls -la /usr/local/cwp/rcloneCWP/ | head -20

echo
echo "--- Today's Log ---"
LOGFILE="/var/log/rcloneCWP/rcloneCWP-$(date +%Y-%m-%d).log"
if [ -f "$LOGFILE" ]; then
    grep -E "ERROR|CRITICAL|FAILED" "$LOGFILE" | tail -20 || echo "No errors"
else
    echo "Log file not found"
fi

echo
echo "=== Diagnostics Complete ==="
```

Save and run:
```bash
bash diagnostics.sh > /tmp/rcloneCWP-diag-$(date +%Y%m%d).txt
cat /tmp/rcloneCWP-diag-$(date +%Y%m%d).txt
```

---

## Quick Reference: Error Codes

| Error | Meaning | Fix |
|-------|---------|-----|
| `INVALID_ACCESS` | Direct file access blocked | Access via CWP module only |
| `AUTH_REQUIRED` | No admin session | Re-login to CWP |
| `CSRF_EXPIRED` | Security token expired | Refresh page |
| `DB_CONNECTION_FAILED` | Can't connect to MySQL | Check MariaDB/service |
| `RCLONE_NOT_FOUND` | rclone binary missing | Install rclone |
| `DESTINATION_INVALID` | Bad destination config | Re-save destination |
| `ENCRYPTION_KEY_MISSING` | `.encryption.key` not found | Re-run installer |
| `JOB_NOT_FOUND` | Job ID doesn't exist | Check job list |
| `PERMISSION_DENIED` | File permission issue | Run diagnostic above |
| `BACKUP_IN_PROGRESS` | Another backup running | Wait or cancel existing |
| `SCHEDULE_NOT_FOUND` | Schedule ID invalid | Check schedules list |
| `RESTORE_TARGET_EXISTS` | Target path not empty | Use --overwrite or different path |

---

## Getting Help

1. **Logs first**: Always check `/var/log/rcloneCWP/rcloneCWP-YYYY-MM-DD.log`
2. **Run diagnostics**: `system:status` CLI command
3. **Test isolated**: Verify rclone works independently: `rclone ls remote:bucket`
4. **Check permissions**: Run the permission diagnostic section above
5. **Report with**: PHP version, rclone version, OS version, relevant log lines