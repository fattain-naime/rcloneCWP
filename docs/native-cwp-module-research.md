# Native rcloneCWP — Research & Blueprint (2026-09-06)

## Problem
The FTP bridge approach (rclone → FTP → CWP backup_manager2) works but is limited:
- No native hooks system
- No REST API / CLI
- No per-file/per-db restore from UI
- No incremental backup intelligence in the control panel
- No centralized scheduling, retention, or monitoring

**Goal**: Build a native CWP module that uses rclone as the transport layer with full JetBackup5 feature parity — for free.

---

## Research Summary

### CWP Module System Architecture

**File Locations:**
- Admin modules: `/usr/local/cwpsrv/htdocs/resources/admin/modules/`
- User modules: `/usr/local/cwpsrv/htdocs/resources/client/modules/`
- Menu registration: `/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php`
- Database: `root_cwp` (MySQL/MariaDB)

**Module Access Pattern:**
```
http://server:2030/index.php?module=<filename>
```
CWP includes the PHP file from the modules directory. No routing framework needed.

**Menu Entry Format (3rdparty.php):**
```
Label|URL|Font Awesome Icon|Target
```
Example: `Rclone Backup|/index.php?module=rcloneCWP|fa fa-cloud|main`

**Key Constraints:**
- CWP runs as **root** — modules have full system access
- Modules can be plain PHP (ionCube optional for distribution)
- CWP uses `$_SESSION['lkey']` and `$_SESSION['cp']` for admin auth
- Database credentials in `/usr/local/cwp/.conf/mysql_db.cnf`

### CWP Backup System Internals

**Existing Backup Files (NOT ionCube-encoded):**
| File | Purpose |
|------|---------|
| `cron_newbackup.php` | Main backup orchestrator |
| `cron_backup.php` | Legacy backup |
| `cron_restore_account.php` | Account restore |
| `cron_restore_acc_cpanel.php` | cPanel migration restore |
| `cron_migration_cpanel.php` | cPanel migration |

**Backup Process Flow:**
1. Cron triggers `cron_newbackup.php`
2. Reads from `root_cwp.backups` table
3. Creates temp dir `/backup/.backup_temp/{user}/`
4. Collects: files, MySQL dumps, DNS zones, emails, SSL, cron, FTP accounts
5. Compresses to tar.gz
6. Transfers to destination (local/FTP/SFTP)

**Critical Insight**: The cron scripts are readable PHP. We can study and replicate the collection logic while replacing the transport layer with rclone.

### JetBackup5 Feature Parity Analysis

| Feature | JB5 | rclone Module Plan |
|---------|-----|--------------------|
| Destinations | ~10 | 70+ (via rclone) |
| Incremental | ✅ | ✅ (rclone checksum/sync) |
| Full restore | ✅ | ✅ |
| Partial restore | ✅ | ✅ |
| Hooks | ✅ | ✅ (shell/PHP/Python/URL) |
| REST API | ✅ | ✅ |
| CLI | ✅ | ✅ |
| Encryption | ✅ | ✅ (AES-256-GCM) |
| Scheduling | ✅ | ✅ |
| Retention | ✅ | ✅ |
| Notifications | ✅ | ✅ (email/Slack/Telegram) |
| **Cost** | **$$$** | **FREE** |

### rclone Capabilities Used

**Backends (70+):** S3, GDrive, GCS, B2, Wasabi, Dropbox, OneDrive, Azure, SFTP, FTP, WebDAV, etc.

**Features Used:**
- `rclone copy` / `rclone sync` — transfer with checksum verification
- `rclone ls` / `rclone lsd` — directory listing for restore browser
- `rclone size` — backup size calculation
- `rclone crypt` — client-side encryption
- `--bwlimit` — bandwidth throttling
- `--transfers` — parallel uploads
- `--fast-list` — efficient listing for large dirs
- `--checksum` — incremental detection
- `--vfs-cache-mode` — caching for FTP bridge

---

## Architecture Blueprint

### Directory Structure

```
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
├── rcloneCWP.php              # Main entry point (router)
├── config.php                     # Constants, paths
├── install.php                    # DB schema + setup
├── uninstall.php                  # Cleanup
│
├── lib/                           # PHP classes
│   ├── Database.php               # PDO wrapper
│   ├── Logger.php                 # File + DB logging
│   ├── Rclone.php                 # Binary wrapper
│   ├── Destination.php (abstract) # Base destination class
│   ├── DestinationFactory.php     # Factory pattern
│   ├── BackupEngine.php           # Orchestrator
│   ├── RestoreEngine.php          # Restore orchestrator
│   ├── Schedule.php               # Cron scheduling
│   ├── Hook.php                   # Hook execution
│   ├── Notification.php           # Email/Slack/Telegram
│   ├── Encryption.php             # AES-256-GCM
│   ├── Validator.php              # Input sanitization
│   ├── CSRF.php                   # CSRF tokens
│   ├── Progress.php               # Progress tracking
│   ├── API.php                    # REST API router
│   └── CLI.php                    # CLI handler
│
├── destinations/                  # Backend implementations
│   ├── Local.php
│   ├── FTP.php
│   ├── SFTP.php
│   ├── S3.php
│   ├── GoogleDrive.php
│   ├── GCS.php
│   ├── B2.php
│   ├── Dropbox.php
│   ├── OneDrive.php
│   ├── Azure.php
│   └── WebDAV.php
│
├── views/                         # UI templates
│   ├── dashboard.php
│   ├── backup_jobs.php
│   ├── backup_job_form.php
│   ├── destinations.php
│   ├── destination_form.php
│   ├── destination_test.php
│   ├── backups.php
│   ├── backup_detail.php
│   ├── restore.php
│   ├── schedules.php
│   ├── schedule_form.php
│   ├── hooks.php
│   ├── hook_form.php
│   ├── notifications.php
│   ├── notification_form.php
│   ├── api_keys.php
│   ├── settings.php
│   ├── logs.php
│   └── partials/
│       ├── header.php
│       ├── footer.php
│       ├── pagination.php
│       └── progress_bar.php
│
├── cron/                          # CLI cron scripts
│   ├── rcloneCWP.php
│   ├── rclone_cleanup.php
│   └── rclone_restore.php
│
├── api/                           # REST API
│   ├── index.php
│   ├── auth.php
│   ├── v1/
│   │   ├── destinations.php
│   │   ├── jobs.php
│   │   ├── backups.php
│   │   ├── schedules.php
│   │   ├── hooks.php
│   │   └── logs.php
│   └── middleware/
│       ├── AuthMiddleware.php
│       ├── RateLimitMiddleware.php
│       └── CORSMiddleware.php
│
├── cli/                           # CLI entry points
│   ├── rcloneCWP
│   ├── rclone-restore
│   ├── rclone-destination
│   ├── rclone-schedule
│   └── rclone-log
│
├── language/                      # i18n
│   ├── en.php
│   └── bn.php
│
├── assets/
│   ├── css/rcloneCWP.css
│   └── js/rcloneCWP.js
│
├── templates/                     # Email/notification templates
│   ├── email/
│   │   ├── backup_success.php
│   │   ├── backup_failure.php
│   │   └── restore_complete.php
│   └── slack/
│       └── notification.php
│
├── sql/
│   ├── install.sql
│   ├── uninstall.sql
│   └── updates/
│       ├── 1.0.0.sql
│       └── 1.1.0.sql
│
├── tests/
│   ├── phpunit.xml
│   ├── bootstrap.php
│   ├── unit/
│   │   ├── DatabaseTest.php
│   │   ├── RcloneTest.php
│   │   ├── DestinationTest.php
│   │   ├── BackupEngineTest.php
│   │   ├── EncryptionTest.php
│   │   ├── ValidatorTest.php
│   │   └── CSRFTest.php
│   └── integration/
│       ├── BackupFlowTest.php
│       └── RestoreFlowTest.php
│
├── logs/                          # Runtime logs (0700 perms)
│   └── .htaccess
│
├── cache/                         # Runtime cache (0700 perms)
│   └── .htaccess
│
├── README.md
├── CHANGELOG.md
├── DEVELOPER_GUIDE.md
└── LICENSE
```

### Database Schema (8 Tables)

```sql
-- All tables in root_cwp database
-- InnoDB, utf8mb4_unicode_ci

rclone_destinations (id, name, type, config, bandwidth_limit, enabled, test_status, ...)
rclone_jobs (id, name, destinations, users, components, backup_type, compression, encryption, retention_*)
rclone_schedules (id, job_id, type, time, days, dates, cron_expression, next_run, ...)
rcloneCWPs (id, job_id, destination_id, user, backup_type, remote_path, size_bytes, status, progress, checksum, ...)
rclone_hooks (id, name, hook_point, type, content, timeout, run_order, enabled)
rclone_logs (id, level, category, job_id, backup_id, message, context, created_at)
rclone_api_keys (id, name, api_key, permissions, ip_whitelist, rate_limit, enabled)
rclone_notifications (id, type, name, config, events, enabled)
```

### Security Architecture

**Threat Model & Mitigations:**

| Threat | Mitigation |
|--------|------------|
| SQL Injection | PDO prepared statements throughout |
| XSS | `htmlspecialchars()` on all output, CSP headers |
| CSRF | Token on all forms, validated on POST |
| Command Injection | `escapeshellarg()`, command whitelist |
| Credential Theft | AES-256-GCM encryption, key outside web root |
| Path Traversal | `str_replace('..', '', $path)`, path validation |
| Brute Force | API rate limiting, IP whitelist |
| MITM | TLS for all API/destination connections |

**Key Storage:**
- Encryption key: `/usr/local/cwp/.conf/rclone_module_key.conf` (0600)
- rclone config: `/etc/rclone/rclone.conf` (0600)
- DB credentials: read from CWP's existing mysql_db.cnf

### Request Lifecycle

```
1. User navigates to: /index.php?module=rcloneCWP&action=dashboard
2. CWP includes: modules/rcloneCWP/rcloneCWP.php
3. rcloneCWP.php:
   a. Loads config.php (defines, DB config)
   b. Starts session (for CSRF)
   c. Verifies CWP admin auth ($_SESSION['lkey'])
   d. Routes based on ?action= parameter
   e. Loads view file from views/ directory
   f. View uses lib/ classes for logic
   g. HTML output to browser
```

### Hook System

**Hook Points:**
- `pre_backup` — before backup starts (stop services, flush caches)
- `post_backup` — after backup completes (start services, verify)
- `pre_restore` — before restore starts
- `post_restore` — after restore completes
- `on_failure` — on any error
- `on_success` — on successful operation

**Hook Types:**
- Shell scripts (`/bin/bash`)
- PHP scripts (CLI)
- Python 3 scripts
- URL callbacks (HTTP POST)

### REST API Design

**Base URL**: `/index.php?module=rcloneCWP&action=api&endpoint=...`

**Endpoints:**
```
GET    /api/v1/destinations
POST   /api/v1/destinations
GET    /api/v1/destinations/{id}
PUT    /api/v1/destinations/{id}
DELETE /api/v1/destinations/{id}
POST   /api/v1/destinations/{id}/test
GET    /api/v1/jobs
POST   /api/v1/jobs
GET    /api/v1/jobs/{id}
PUT    /api/v1/jobs/{id}
DELETE /api/v1/jobs/{id}
POST   /api/v1/jobs/{id}/run
GET    /api/v1/backups
GET    /api/v1/backups/{id}
DELETE /api/v1/backups/{id}
POST   /api/v1/backups/{id}/restore
GET    /api/v1/schedules
POST   /api/v1/schedules
GET    /api/v1/logs
GET    /api/v1/status
```

**Auth**: Bearer token in `Authorization` header

### CLI Tools

```bash
# Backup management
rcloneCWP job list
rcloneCWP job run <id>
rcloneCWP job show <id>

# Restore
rclone-restore <backup_id> --user=<user>

# Destinations
rclone-destination list
rclone-destination test <id>

# Logs
rclone-log view --lines=50
rclone-log clear

# Status
rcloneCWP status
```

---

## Implementation Phases (45 Days)

| Phase | Days | Deliverable |
|-------|------|-------------|
| 1: Foundation | 1-5 | DB schema, rclone wrapper, Logger, Encryption, Validator |
| 2: Destinations | 6-10 | All 12 destination types with test connections |
| 3: Backup Engine | 11-16 | Full/incremental backups, compression, encryption |
| 4: Restore Engine | 17-22 | Full/partial restore, safety backup, verification |
| 5: Scheduling | 23-25 | Cron scripts, retention policies |
| 6: Hooks & Notifications | 26-29 | Hook system, email/Slack/Telegram |
| 7: API & CLI | 30-34 | REST API, CLI tools, API key management |
| 8: UI Polish | 35-38 | Dashboard, all views, progress tracking |
| 9: Testing | 39-42 | Unit tests, integration tests, security audit |
| 10: Release | 43-45 | Documentation, packaging, GitHub release |

---

## Key Technical Decisions

1. **Plain PHP** — no ionCube, fully open-source and editable
2. **PDO with prepared statements** — SQL injection prevention
3. **AES-256-GCM** — authenticated encryption for credentials
4. **rclone binary wrapper** — whitelisted commands, sanitized args
5. **Root execution** — module runs as root (CWP standard), so rclone has full access
6. **CWP DB integration** — uses `root_cwp` database alongside existing tables
7. **Service-based cron** — system crontab entries for reliability

---

## Reference Documents

The complete research, blueprints, and code templates are saved at:
- `/root/rcloneCWP/RESEARCH-AND-BLUEPRINT.md` (68 KB)
- `/root/rcloneCWP/DEVELOPER-GUIDE.md` (78 KB)
- `/root/rcloneCWP/IMPLEMENTATION-PLAN.md` (14 KB)
- `/root/rcloneCWP/sql/install.sql` (10 KB)
- `/root/rcloneCWP/README.md` (4 KB)

---

## Open Questions for Development

1. **Database migration**: Should we migrate from CWP's existing `backups` table or keep them separate? (Recommendation: separate, for clean uninstall)
2. **User panel**: Should users have their own simplified panel? (Recommendation: Phase 2)
3. **Multi-server**: Should backups be restorable across servers? (Recommendation: Phase 2)
4. **Licensing**: MIT vs GPL? (Recommendation: MIT for maximum adoption)

---

**Status**: Research complete. Blueprint approved. Ready for Phase 1 development.
