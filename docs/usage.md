# rcloneCWP Usage Guide

## Quick Start

### 1. Add a Backup Destination

1. Navigate to **Destinations** tab
2. Click **Add Destination**
3. Select provider (Local, S3, GCS, Azure, B2, OneDrive, Dropbox, SFTP, WebDAV, Swift, Tencent COS)
4. Fill in credentials:
   - **Local**: Path (e.g., `/backup/rcloneCWP`)
   - **S3**: Access Key, Secret Key, Region, Bucket, Endpoint (optional)
   - **SFTP**: Host, User, Port, Password or SSH Key path
   - **OneDrive/Dropbox/GCS/Azure**: Use OAuth flow (click "Authorize" button)
5. Click **Test Connection** — verify success
6. Click **Save**

### 2. Create a Backup Job

1. Navigate to **Backup Jobs** tab
2. Click **Add Job**
3. Configure:
   - **Name**: Descriptive name (e.g., "Daily User Backups")
   - **Destination**: Select from created destinations
   - **Type**: Full / Incremental / Selective
   - **What to backup**:
     - All CWP accounts
     - Specific accounts (select from list)
     - Custom paths
   - **Options**: Compression, encryption, excludes
4. Click **Save**

### 3. Run a Backup

- **Manual**: Click **Run Now** on any job in Backup Jobs tab
- **Scheduled**: Create a schedule (see below)

### 4. Schedule Backups

1. Navigate to **Schedules** tab
2. Click **Add Schedule**
3. Configure:
   - **Name**: e.g., "Daily at 2 AM"
   - **Cron Expression**: `0 2 * * *` (daily 2 AM) or use preset dropdown
   - **Job**: Select backup job
   - **Retention**: Keep last N backups, or by time (7d, 30d, etc.)
4. Click **Save**
5. In **Settings** tab, click **Install Crontab** (one-time setup)

---

## Dashboard Overview

The **Dashboard** tab provides:
- **Quick Stats**: Total destinations, jobs, schedules, recent backups
- **Recent Activity**: Last 10 log entries with status colors
- **Storage Usage**: Per-destination usage (if supported)
- **Quick Actions**: Run backup, view logs, add destination

---

## Managing Destinations

### Add Destination

| Provider | Required Fields | Notes |
|----------|----------------|-------|
| Local | Path | Ensure path exists and is writable |
| S3 | Access Key, Secret Key, Region, Bucket | Supports custom endpoint (MinIO, Wasabi) |
| GCS | Service Account JSON, Project ID, Bucket | Paste JSON or upload file |
| Azure | Account Name, Account Key, Container | Or use SAS token |
| B2 | Key ID, Application Key, Bucket | Backblaze B2 |
| OneDrive | — | OAuth: click Authorize, grant permissions |
| Dropbox | — | OAuth: click Authorize, grant permissions |
| SFTP | Host, User, Port, Auth (Password/Key) | SSH key: paste private key or upload |
| WebDAV | URL, User, Password | Nextcloud, ownCloud, etc. |
| Swift | Auth URL, User, Password, Tenant, Container | OpenStack Swift |
| Tencent COS | Secret ID, Secret Key, Region, Bucket | Tencent Cloud COS |

### Test Connection

Click **Test Connection** before saving — validates credentials and network reachability.

### Edit/Delete

- **Edit**: Click pencil icon, modify, save
- **Delete**: Click trash icon — only allowed if no jobs/schedules reference it

### Destination Encryption

All sensitive config (keys, passwords, tokens) stored in `rclone_destinations.config` encrypted with **AES-256-GCM** (unique IV per field). Decrypted only in memory at runtime.

---

## Managing Backup Jobs

### Job Types

| Type | Description | Use Case |
|------|-------------|----------|
| **Full** | Complete backup every run | Small datasets, weekly archives |
| **Incremental** | Only changes since last backup | Large datasets, daily backups |
| **Selective** | Specific paths/accounts only | Custom requirements |

### Job Configuration Options

- **Compression**: `zstd` (default), `gzip`, `none`
- **Encryption**: AES-256-GCM (uses module master key)
- **Excludes**: Glob patterns (e.g., `*.log`, `node_modules/`, `cache/`)
- **Bandwidth Limit**: `--bwlimit` for rclone (e.g., `10M`)
- **Retries**: Number of retry attempts (default 3)

### Run Job Manually

```bash
# Via CLI
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rcloneCWP job:run <job_id>

# Via API
curl -X POST "https://server:2030/index.php?module=rcloneCWP&ajax=run_job" \
  -H "X-CSRF-Token: <token>" \
  -d "job_id=<job_id>&csrf_token=<token>"
```

### Job Status

| Status | Meaning |
|--------|---------|
| `pending` | Queued, not started |
| `running` | In progress |
| `success` | Completed successfully |
| `failed` | Error occurred (check logs) |
| `partial` | Some files failed, rest succeeded |

---

## Scheduling Backups

### Cron Expressions

| Schedule | Expression |
|----------|------------|
| Every 5 minutes | `*/5 * * * *` |
| Hourly | `0 * * * *` |
| Daily at 2 AM | `0 2 * * *` |
| Weekly (Sunday 3 AM) | `0 3 * * 0` |
| Monthly (1st, 4 AM) | `0 4 1 * *` |

### Retention Policies

- **Count-based**: Keep last N backups (e.g., `keep: 7`)
- **Time-based**: Keep backups within period (e.g., `keep: 30d`, `keep: 12m`)
- **Hybrid**: `keep: 7d,30` (keep 30 backups max, but at least 7 days)

### Crontab Runner

The module installs a system crontab entry that runs every 5 minutes:

```bash
*/5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1
```

This runner:
1. Checks all active schedules
2. Executes due backup jobs
3. Applies retention policies
4. Runs hooks (pre/post backup)
5. Sends notifications

**Install once** in Settings tab → Crontab Management → Install Crontab.

---

## Restoring from Backup

### Via Web UI (Restore Tab)

1. Navigate to **Restore** tab
2. Select **Destination** containing backups
3. Browse snapshots — shows date, size, job name
4. Select **Restore Type**:
   - **Full Restore**: Entire backup to original location
   - **Partial Restore**: Select specific accounts/paths
   - **Cross-Server**: Restore to different server (enter target details)
5. Configure options:
   - **Target Path**: Override restore location
   - **Overwrite**: Replace existing files
   - **Dry Run**: Preview without writing
6. Click **Start Restore**

### Via CLI

```bash
# List available snapshots
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rclone-restore list --destination=<dest_id>

# Full restore
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rclone-restore run \
  --destination=<dest_id> \
  --snapshot=<snapshot_id> \
  --target=/home/restore

# Partial restore (specific account)
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rclone-restore run \
  --destination=<dest_id> \
  --snapshot=<snapshot_id> \
  --account=username \
  --target=/home/username/restore
```

### Restore Process

1. Downloads snapshot metadata
2. Validates integrity (checksums)
3. Streams restore via rclone (supports resume)
4. Runs post-restore hooks
5. Sends notification on completion

---

## Configuring Hooks

Hooks are custom scripts that run at specific points in the backup/restore lifecycle.

### Hook Types

| Event | Trigger |
|-------|---------|
| `pre_backup` | Before backup starts |
| `post_backup` | After backup completes (success/fail) |
| `pre_restore` | Before restore starts |
| `post_restore` | After restore completes |
| `pre_retention` | Before retention cleanup |
| `post_retention` | After retention cleanup |

### Creating a Hook

1. Navigate to **Hooks** tab
2. Click **Add Hook**
3. Configure:
   - **Name**: e.g., "Slack Notification"
   - **Event**: Select from dropdown
   - **Type**: Shell / PHP / Python
   - **Script**: Path to executable or inline code
   - **Timeout**: Max execution time (default 300s)
   - **Run on Failure**: Whether to run if parent operation failed

### Hook Examples

**Shell Hook (Slack notification):**
```bash
#!/bin/bash
# /usr/local/cwp/rcloneCWP/hooks/slack-notify.sh
curl -X POST "$SLACK_WEBHOOK" \
  -H 'Content-Type: application/json' \
  -d "{\"text\": \"Backup $BACKUP_STATUS: $JOB_NAME ($DESTINATION)\"}"
```

**PHP Hook (Database cleanup):**
```php
<?php
// /usr/local/cwp/rcloneCWP/hooks/cleanup.php
require_once '/usr/local/cwp/rcloneCWP/bootstrap.php';
use CWP\RcloneCWP\Database;
$db = Database::getInstance();
$db->exec("DELETE FROM rclone_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
```

**Python Hook (Custom API call):**
```python
#!/usr/bin/env python3
# /usr/local/cwp/rcloneCWP/hooks/custom-api.py
import requests, os
requests.post(os.getenv('CUSTOM_API_URL'), json={
    'job': os.getenv('JOB_NAME'),
    'status': os.getenv('BACKUP_STATUS'),
    'size': os.getenv('BACKUP_SIZE')
})
```

### Environment Variables Available to Hooks

| Variable | Description |
|----------|-------------|
| `JOB_ID` | Backup job ID |
| `JOB_NAME` | Backup job name |
| `DESTINATION_ID` | Destination ID |
| `DESTINATION_NAME` | Destination name |
| `BACKUP_ID` | Backup run ID |
| `BACKUP_STATUS` | success/failed/partial |
| `BACKUP_SIZE` | Backup size in bytes |
| `BACKUP_DURATION` | Duration in seconds |
| `SNAPSHOT_ID` | Snapshot ID (restore) |
| `HOOK_EVENT` | Event type (pre_backup, etc.) |

---

## Setting Up Notifications

### Notification Channels

| Channel | Configuration |
|---------|---------------|
| **Email** | SMTP host, port, user, password, from/to addresses |
| **Telegram** | Bot token, chat ID |
| **Slack** | Webhook URL |
| **Discord** | Webhook URL |
| **Webhook** | Custom URL, headers, payload template |

### Creating a Notification

1. Navigate to **Notifications** tab
2. Click **Add Notification**
3. Configure:
   - **Name**: e.g., "Admin Email on Failure"
   - **Channel**: Select type
   - **Events**: Check boxes (backup_success, backup_failed, restore_success, restore_failed, schedule_missed, retention_applied)
   - **Channel Config**: Fill in credentials
4. Click **Test** to verify delivery
5. Click **Save**

### Notification Templates

Use placeholders in custom messages:
- `{job_name}`, `{destination_name}`, `{backup_status}`
- `{backup_size}`, `{backup_duration}`, `{timestamp}`
- `{error_message}` (on failure)

---

## CLI Usage Examples

### Main CLI Tool (`rcloneCWP`)

```bash
# Show help
/usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cli/rcloneCWP --help

# Destination management
rcloneCWP destination:list
rcloneCWP destination:add --type=s3 --name="AWS Backups" --config='{"key":"...","secret":"...","region":"us-east-1","bucket":"my-bucket"}'
rcloneCWP destination:test <dest_id>
rcloneCWP destination:delete <dest_id>

# Job management
rcloneCWP job:list
rcloneCWP job:add --name="Daily Full" --destination=<dest_id> --type=full --accounts=all
rcloneCWP job:run <job_id>
rcloneCWP job:status <job_id>

# Schedule management
rcloneCWP schedule:list
rcloneCWP schedule:add --name="Daily 2AM" --cron="0 2 * * *" --job=<job_id> --retention="7d"
rcloneCWP schedule:enable <schedule_id>
rcloneCWP schedule:disable <schedule_id>

# Backup history
rcloneCWP backup:list
rcloneCWP backup:show <backup_id>
rcloneCWP backup:delete <backup_id>

# System
rcloneCWP system:status
rcloneCWP system:install-crontab
rcloneCWP system:uninstall-crontab
```

### Restore CLI (`rclone-restore`)

```bash
# List snapshots for a destination
rclone-restore list --destination=<dest_id>

# Show snapshot details
rclone-restore show --destination=<dest_id> --snapshot=<snapshot_id>

# Run restore
rclone-restore run --destination=<dest_id> --snapshot=<snapshot_id> --target=/restore/path
rclone-restore run --destination=<dest_id> --snapshot=<snapshot_id> --account=username --target=/home/username

# Dry run
rclone-restore run --destination=<dest_id> --snapshot=<snapshot_id> --target=/restore/path --dry-run
```

### Destination CLI (`rclone-destination`)

```bash
# Test all destinations
rclone-destination test-all

# Sync rclone.conf from destination configs
rclone-destination sync-config

# Export destination config (encrypted)
rclone-destination export <dest_id> > backup.json

# Import destination config
rclone-destination import < backup.json
```

### Schedule CLI (`rclone-schedule`)

```bash
# List due schedules
rclone-schedule due

# Run due schedules now
rclone-schedule run-due

# Check specific schedule
rclone-schedule check <schedule_id>
```

---

## API Reference Basics

### Base URL

```
https://YOUR_SERVER:2030/index.php?module=rcloneCWP
```

### Authentication

All API requests require:
- **CSRF Token** in header: `X-CSRF-Token` or POST field `csrf_token`
- **Admin Session** (CWP root admin)

### Common Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?ajax=list_destinations` | GET | List all destinations |
| `?ajax=add_destination` | POST | Create destination |
| `?ajax=test_destination` | POST | Test connection |
| `?ajax=delete_destination` | POST | Delete destination |
| `?ajax=list_jobs` | GET | List backup jobs |
| `?ajax=add_job` | POST | Create backup job |
| `?ajax=run_job` | POST | Execute job manually |
| `?ajax=list_schedules` | GET | List schedules |
| `?ajax=add_schedule` | POST | Create schedule |
| `?ajax=list_backups` | GET | List backup history |
| `?ajax=restore_start` | POST | Start restore |
| `?ajax=list_hooks` | GET | List hooks |
| `?ajax=add_hook` | POST | Create hook |
| `?ajax=list_notifications` | GET | List notifications |
| `?ajax=add_notification` | POST | Create notification |
| `?ajax=get_logs` | GET | Get recent logs |
| `?ajax=get_crontab_status` | GET | Check crontab status |
| `?ajax=install_crontab` | POST | Install crontab |
| `?ajax=uninstall_crontab` | POST | Uninstall crontab |

### Example API Call

```bash
# Get CSRF token first (from page load or login)
CSRF_TOKEN="your_token_here"

# List destinations
curl -s "https://server:2030/index.php?module=rcloneCWP&ajax=list_destinations" \
  -H "X-CSRF-Token: $CSRF_TOKEN" | jq .

# Create S3 destination
curl -s -X POST "https://server:2030/index.php?module=rcloneCWP&ajax=add_destination" \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "csrf_token=$CSRF_TOKEN&name=AWS%20Backups&type=s3&config[key]=AKIA...&config[secret]=secret&config[region]=us-east-1&config[bucket]=my-bucket" | jq .
```

### API Response Format

```json
{
  "ok": true,
  "data": [...],
  "message": "Optional message"
}
```

Error response:
```json
{
  "ok": false,
  "error": "Human-readable error message",
  "code": "ERROR_CODE"
}
```

---

## Tips & Best Practices

1. **Test destinations** before creating jobs — saves time debugging
2. **Use incremental backups** for large datasets — faster, less storage
3. **Set retention policies** — prevents unlimited storage growth
4. **Install crontab** — enables automatic scheduling
5. **Monitor logs** — Activity Logs tab shows real-time status
6. **Use hooks** for custom integrations (Slack, email, custom APIs)
7. **Test restores periodically** — ensures backups are valid
8. **Keep rclone updated** — `rclone selfupdate` for latest provider support
9. **Secure encryption key** — `/usr/local/cwp/rcloneCWP/.encryption.key` must stay 0600
10. **Backup the encryption key** — without it, encrypted backups are unrecoverable