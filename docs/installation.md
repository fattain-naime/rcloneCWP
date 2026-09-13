# rcloneCWP Installation Guide

## Requirements

| Component | Minimum Version | Notes |
|-----------|-----------------|-------|
| PHP | 7.1+ | CWP uses `/usr/local/cwp/php71/bin/php` (PHP 7.2.30 on AlmaLinux 8.10) |
| rclone | 1.75.0+ | Install from [rclone.org](https://rclone.org/install/) |
| CWP | Any recent | CentOS Web Panel with admin access |
| Database | MariaDB/MySQL | Uses CWP's `root_cwp` database |
| OS | AlmaLinux 8+, CentOS 7+, Rocky Linux 8+ | Tested on AlmaLinux 8.10 |
| User | root | Installation must run as root |

## One-Command Install (Recommended)

```bash
curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash
```

### What the installer does:

1. **Preflight checks** - Verifies root access, curl/git availability, CWP installation, PHP binary, rclone presence
2. **Fetch** - Clones the repository (depth 1, main branch) to a temporary directory
3. **Deploy** - Copies files to two locations:
   - Web module entry: `/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php`
   - Runtime home: `/usr/local/cwp/rcloneCWP/` (lib/, views/, cron/, sql/, config.php, bootstrap.php, install.php, uninstall.php)
4. **Install** - Runs the PHP installer (CLI) which:
   - Applies the database schema (8 tables)
   - Generates encryption key for AES-256-GCM
   - Creates required directories with secure permissions
   - Registers the module in CWP's 3rdparty menu
5. **Report** - Prints the admin URL and summary

### Installer Options

```bash
# Install from local checkout (development)
RCLONECWP_LOCAL=/path/to/rcloneCWP curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash

# The script requires absolute path for RCLONECWP_LOCAL
```

## Manual Installation

If you prefer manual control or the one-command installer fails:

```bash
# 1. Clone the repository
git clone --depth 1 https://github.com/fattain_naive/rcloneCWP.git
cd rcloneCWP

# 2. Deploy web module entry
cp rcloneCWP.php /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php
chmod 644 /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php

# 3. Deploy runtime files
HOME_DIR="/usr/local/cwp/rcloneCWP"
mkdir -p "$HOME_DIR/lib" "$HOME_DIR/sql" "$HOME_DIR/views" "$HOME_DIR/cron"
for f in config.php bootstrap.php install.php uninstall.php; do
    cp "$f" "$HOME_DIR/$f"
done
cp -r lib/* "$HOME_DIR/lib/"
cp -r views/* "$HOME_DIR/views/"
cp -r cron/* "$HOME_DIR/cron/"
cp sql/install.sql "$HOME_DIR/sql/"

# 4. Set permissions
chmod 700 "$HOME_DIR"
chmod 644 "$HOME_DIR/config.php" "$HOME_DIR/bootstrap.php" "$HOME_DIR/sql/install.sql"
find "$HOME_DIR/lib" -type d -exec chmod 755 {} \;
find "$HOME_DIR/lib" -type f -exec chmod 644 {} \;
find "$HOME_DIR/views" -type d -exec chmod 755 {} \;
find "$HOME_DIR/views" -type f -exec chmod 644 {} \;
find "$HOME_DIR/cron" -type d -exec chmod 755 {} \;
find "$HOME_DIR/cron" -type f -exec chmod 755 {} \;
chmod 700 "$HOME_DIR/install.php" "$HOME_DIR/uninstall.php"

# 5. Run PHP installer
/usr/local/cwp/php71/bin/php "$HOME_DIR/install.php"
```

## Post-Installation Verification

### 1. Check Admin URL Access

Open in browser:
```
https://YOUR_SERVER_IP:2030/index.php?module=rcloneCWP
```

You should see the rcloneCWP dashboard with 9 tabs: Dashboard, Destinations, Backup Jobs, Restore, Schedules, Activity Logs, Hooks, Notifications, Settings.

### 2. Verify Module Registration

In CWP admin panel, look for **rcloneCWP** under the sidebar menu (typically under "3rd Party" or "Addons" section).

### 3. Check Runtime Directories

```bash
ls -la /usr/local/cwp/rcloneCWP/
# Should show: config.php, bootstrap.php, install.php, uninstall.php, lib/, views/, cron/, sql/

ls -la /usr/local/cwp/rcloneCWP/lib/
# Should show PHP class files

ls -la /var/log/rcloneCWP/
# Log directory created by installer
```

### 4. Verify Database Tables

```bash
mysql -u root -p root_cwp -e "SHOW TABLES LIKE 'rclone_%';"
# Should show 8 tables: rclone_api_keys, rclone_backups, rclone_destinations, rclone_hooks, rclone_jobs, rclone_logs, rclone_notifications, rclone_schedules
```

### 5. Check rclone Binary

```bash
rclone version
# Should show rclone v1.75.0 or higher
```

### 6. Verify Encryption Key

```bash
ls -la /usr/local/cwp/rcloneCWP/.encryption.key
# Should exist with 0600 permissions
```

## Directory Structure After Install

```
/usr/local/cwpsrv/htdocs/resources/admin/modules/
└── rcloneCWP.php                    # Web module entry (flat file, 644)

/usr/local/cwp/rcloneCWP/            # Runtime home (700, root:root)
├── config.php                       # Configuration constants (644)
├── bootstrap.php                    # Autoloader & constants (644)
├── install.php                      # CLI installer (700)
├── uninstall.php                    # CLI uninstaller (700)
├── sql/
│   └── install.sql                  # Database schema (644)
├── lib/                             # Core PHP classes (755/644)
│   ├── Autoloader.php
│   ├── Database.php
│   ├── Logger.php
│   ├── Encryption.php
│   ├── Rclone.php
│   ├── Validator.php
│   ├── CSRF.php
│   ├── Backup/
│   │   ├── BackupJobManager.php
│   │   └── CwpAccountDiscovery.php
│   ├── Destinations/
│   │   ├── DestinationManager.php
│   │   ├── DestinationFactory.php
│   │   ├── BaseDestination.php
│   │   ├── Local.php
│   │   ├── S3.php
│   │   ├── GCS.php
│   │   ├── Azure.php
│   │   ├── B2.php
│   │   ├── OneDrive.php
│   │   ├── Dropbox.php
│   │   ├── SFTP.php
│   │   ├── WebDAV.php
│   │   ├── Swift.php
│   │   └── TencentCOS.php
│   ├── Restore/
│   │   ├── RestoreEngine.php
│   │   └── SnapshotBrowser.php
│   ├── Scheduling/
│   │   ├── ScheduleManager.php
│   │   ├── CronParser.php
│   │   ├── CrontabService.php
│   │   └── RetentionManager.php
│   ├── Hook.php
│   ├── Notification.php
│   └── Api/
│       ├── ApiRouter.php
│       ├── Auth.php
│       └── RateLimiter.php
├── views/                           # UI templates (755/644)
│   ├── layout.php
│   ├── dashboard.php
│   ├── destinations.php
│   ├── jobs.php
│   ├── restore.php
│   ├── schedules.php
│   ├── logs.php
│   ├── hooks.php
│   ├── notifications.php
│   └── settings.php
├── cron/                            # Cron scripts (755)
│   ├── rcloneCWP.php                # Main cron runner
│   └── retention.php                # Retention cleanup
├── api/                             # REST API endpoints
│   └── index.php
├── cli/                             # CLI tools
│   ├── rcloneCWP
│   ├── rclone-restore
│   ├── rclone-destination
│   └── rclone-schedule
├── language/                        # Translations
│   ├── en.php
│   └── bn.php
├── assets/                          # CSS/JS/images
└── templates/                       # Email templates

/var/log/rcloneCWP/                  # Log directory (755)
└── rcloneCWP-YYYY-MM-DD.log         # Daily log files

/etc/rclone/rclone.conf              # rclone config (600, root:root)
```

## Uninstall

### Option 1: Using uninstall script (recommended)

```bash
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/uninstall.php
```

### Option 2: Manual cleanup

```bash
# Remove web module entry
rm -f /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php

# Remove runtime home
rm -rf /usr/local/cwp/rcloneCWP/

# Remove log directory
rm -rf /var/log/rcloneCWP/

# Remove crontab entry (if installed)
crontab -l | grep -v rcloneCWP | crontab -

# Drop database tables (optional - preserves data for reinstall)
mysql -u root -p root_cwp -e "
DROP TABLE IF EXISTS rclone_api_keys, rclone_backups, rclone_destinations, 
rclone_hooks, rclone_jobs, rclone_logs, rclone_notifications, rclone_schedules;
"

# Remove rclone config (if no other apps use it)
rm -f /etc/rclone/rclone.conf
```

## Troubleshooting Installation

| Issue | Solution |
|-------|----------|
| "Must run as root" | Run with `sudo` or as root user |
| "CWP modules directory not found" | Verify CWP is installed at `/usr/local/cwpsrv/` |
| "PHP binary not found" | Check `/usr/local/cwp/php71/bin/php` exists, or install PHP |
| "rclone not found" | Install rclone: `curl https://rclone.org/install.sh | bash` |
| "Module not appearing in CWP" | Clear browser cache, verify 3rdparty.php registration |
| "Database connection failed" | Check `/usr/local/cwpsrv/htdocs/resources/admin/include/db_conn.php` |
| "Encryption key generation failed" | Ensure `/usr/local/cwp/rcloneCWP/` is writable by root |

## Upgrading

```bash
# Re-run the installer (preserves database and configuration)
curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash
```

The installer is idempotent - it backs up the existing encryption key and preserves database data.