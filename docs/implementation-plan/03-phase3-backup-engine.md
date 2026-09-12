# Phase 3: Backup Engine — Implementation Plan

> **Status**: In Progress  
> **Target**: PHP 7.1+ syntax floor (AlmaLinux 8.10 CWP PHP 7.2.30), MariaDB 10.11+, rclone v1.75.0+  
> **Placement**: Off-htdocs runtime at `/usr/local/cwp/rcloneCWP/`, web entry at `/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php`

---

## 0. Overview & Objectives

Phase 3 implements the **Backup Engine** of rcloneCWP. Building directly upon the foundation (Phase 1) and the 12-provider Destinations Engine (Phase 2), Phase 3 provides enterprise JetBackup5-grade backup capabilities natively within CentOS Web Panel (CWP) at zero licensing cost.

### Key Goals:
1. **Multi-Component CWP Account Discovery**:
   - Automated discovery of CWP accounts from `root_cwp.user`, `domains`, `subdomains`, and `packages`.
   - Extraction of all 7 account components:
     1. **Files**: `/home/<user>/` (including `public_html/`, subdomains, dotfiles, keeping ownership/permissions).
     2. **Databases**: All user MySQL/MariaDB databases (`<username>_*`) dumped with `mysqldump --single-transaction --quick --routines --triggers --events`.
     3. **DNS Zones**: BIND zone files from `/var/named/<domain>.db`.
     4. **Email Accounts & Spools**: Virtual accounts from MariaDB `postfix` (`mailbox`, `alias`), `/var/spool/mail/<username>`, and `/home/<username>/mail`.
     5. **SSL Certificates**: TLS certs and keys from `/etc/pki/tls/certs/<domain>.cert` and `/etc/pki/tls/private/<domain>.key`.
     6. **Cron Jobs**: User cron entries from `/var/spool/cron/<username>`.
     7. **FTP Accounts**: Virtual FTP accounts from `/etc/pure-ftpd/pureftpd.passwd` belonging to the user.
2. **Three Backup Modes**:
   - **Full Backup**: Complete snapshot of all components, tar/gzip compressed with cryptographic checksums.
   - **Incremental Backup**: Synchronizes only new and modified files directly to destination using rclone's checksum/modtime tracking (`rclone copy / sync --checksum`), drastically saving cloud egress and storage.
   - **Selective Backup**: Fine-grained component selection (e.g. databases only, files only, mail only, or specific paths).
3. **Zero Plaintext Secrets & Secure Transfer**:
   - Transfers executed via `Rclone::execute()` with in-memory dynamic environment variables (`RCLONE_CONFIG_<REMOTE>_<KEY>`). No unencrypted credentials touch disk.
4. **Retention Policy Management**:
   - Automatic pruning of expired backups according to `retention_days` configured on each backup job.
5. **CWP Admin UI & AJAX Integration**:
   - Full-featured Backup Jobs tab in `views/layout.php`, job creation/editing modal, component checkboxes, destination picker, account selector, instant "Run Now" trigger, live progress polling, and backup run history table.
6. **CLI & Cron Automation**:
   - Standalone CLI runner at `cli/backup.php` suitable for system crontab and automated task runners.

---

## 1. CWP Component Discovery Architecture

The Backup Engine inspects the live CWP server without executing risky shell scripts or modifying system files:

```
┌─────────────────────────────────────────────────────────────┐
│                   CWP Server Environment                    │
│                                                             │
│  MariaDB root_cwp:                                          │
│    user, domains, subdomains, packages                      │
│                                                             │
│  Filesystem:                                                │
│    /home/<username>/               -> User files & web      │
│    /var/named/<domain>.db          -> DNS zone files        │
│    /etc/pki/tls/certs/<domain>.cert-> SSL certs             │
│    /etc/pki/tls/private/<domain>.key -> SSL keys            │
│    /var/spool/cron/<username>      -> User cron tab         │
│    /var/spool/mail/<username>      -> System user mail spool│
│    /etc/pure-ftpd/pureftpd.passwd  -> Virtual FTP accounts  │
│                                                             │
│  MariaDB Databases:                                         │
│    <username>_*                    -> User application DBs  │
│    postfix (mailbox, alias)        -> Virtual email config  │
└─────────────────────────────────────────────────────────────┘
```

### Component Collector Interface (`ComponentCollectorInterface`)
Each component is discovered and extracted through a dedicated collector:
- `AccountMetadataCollector`: Packages, quotas, domains, account info.
- `FilesCollector`: Home directory data with standard exclusions (`.cache`, transient socket files).
- `DatabaseCollector`: Per-user database enumeration and `mysqldump` streaming.
- `DnsCollector`: BIND `.db` zone file exports.
- `MailCollector`: Postfix mailbox configuration and spool/maildir collection.
- `SslCollector`: Domain SSL certificates and private keys.
- `CronCollector`: User crontab backup.
- `FtpCollector`: Pure-FTPd virtual user entries.

---

## 2. Backup Archive Structure

Each backup snapshot generated by the Backup Engine adheres to a standardized, deterministic directory layout:

```
rcloneCWP-backups/
└── <job_slug>/
    └── <timestamp>_<username>_<type>/
        ├── manifest.json              # Detailed metadata, checksums, component list
        ├── meta/
        │   ├── account.json           # User record, domains, package, limits
        │   ├── cron.tab               # Crontab text dump
        │   ├── dns/
        │   │   └── <domain>.db        # Raw BIND zone files
        │   ├── mail/
        │   │   ├── mailboxes.json     # Postfix virtual mailbox configurations
        │   │   └── aliases.json       # Postfix aliases
        │   └── ssl/
        │       ├── <domain>.crt       # Public cert bundle
        │       └── <domain>.key       # Private key
        ├── databases/
        │   ├── <username>_db1.sql.gz  # Compressed mysqldump
        │   └── <username>_db2.sql.gz
        └── files/
            ├── public_html/           # Full home directory structure
            └── ... (user files)
```

### Manifest Schema (`manifest.json`)
```json
{
  "version": "1.0",
  "generator": "rcloneCWP v1.0.0-alpha",
  "job_id": 1,
  "job_name": "Daily Production Accounts",
  "username": "builderh",
  "type": "full",
  "started_at": "2026-09-12 02:00:00",
  "completed_at": "2026-09-12 02:04:12",
  "duration_seconds": 252,
  "components": {
    "files": {"status": "ok", "files_count": 1420, "bytes": 85938492},
    "databases": {"status": "ok", "databases": ["builderh_builder", "builderh_pay"], "bytes": 14920381},
    "dns": {"status": "ok", "zones": ["builderhall.com"]},
    "mail": {"status": "ok", "mailboxes_count": 2},
    "ssl": {"status": "ok", "domains": ["builderhall.com"]},
    "cron": {"status": "ok", "entries_count": 4}
  },
  "checksums": {
    "databases/builderh_builder.sql.gz": "sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  }
}
```

---

## 3. Database Schema Utilization

### 3.1 `rclone_jobs`
Defines what to back up, where to send it, and how often:
- `name`: Human-readable title (e.g. "Daily Cloud Offsite").
- `source_path`: JSON array of target users (e.g. `["builderh", "ownpay"]` or `["*"]` for all accounts) and component flags.
- `destination_id`: Foreign key pointing to `rclone_destinations.id`.
- `job_type`: `'full'`, `'incremental'`, or `'selective'`.
- `retention_days`: Number of days to keep backup runs before pruning (default 7).
- `compression`: 1 (enabled, gzip) or 0 (uncompressed).
- `notify_on_success`: 0 or 1.
- `notify_on_failure`: 1.

### 3.2 `rclone_backups`
Tracks the execution lifecycle of every single backup run:
- `job_id`: Reference to the initiating backup job.
- `destination_id`: Destination where backup was written.
- `backup_type`: `'full'`, `'incremental'`, or `'selective'`.
- `status`: `'pending'`, `'running'`, `'completed'`, `'failed'`, `'cancelled'`.
- `started_at`: Timestamp of initiation.
- `completed_at`: Timestamp of completion.
- `files_count`: Total files transferred or verified.
- `bytes_transferred`: Cumulative bytes sent over the wire.
- `duration_seconds`: Total wall-clock time in seconds.
- `error_message`: Stack trace or failure description if status is `'failed'`.
- `config_id`: Remote storage identifier / directory path of snapshot.

---

## 4. Execution Workflow

```
[Trigger (UI 'Run Now' or CLI/Cron)]
                 │
                 ▼
     [1. Acquire Process Lock]
                 │
                 ▼
     [2. Create DB Record (status='running')]
                 │
                 ▼
     [3. Prepare Staging Directory (/backup/.rcloneCWP_tmp/{run_id})]
                 │
                 ▼
     [4. Iterate Target CWP Users]
         ├── Dump Databases via mysqldump
         ├── Collect DNS, SSL, Mail, Cron, Metadata
         └── Prepare File Sync
                 │
                 ▼
     [5. Dynamic Transfer via rclone]
         ├── Inject RCLONE_CONFIG_* in-memory
         ├── rclone copy/sync to remote target
         └── Stream checksum verification
                 │
                 ▼
     [6. Prune Staging Directory]
                 │
                 ▼
     [7. Apply Retention Policy (Prune Old Runs)]
                 │
                 ▼
     [8. Update DB Record (status='completed')]
                 │
                 ▼
     [9. Release Process Lock]
```

---

## 5. CWP Admin UI Integration

1. **Activate "Backup Jobs" Tab** in `views/layout.php`.
2. **Job List Table** (`views/backup_jobs.php`):
   - Displays Job Name, Destination badge, Type, Target Accounts, Retention, Last Run status, and Action buttons (Run Now, Edit, Delete).
3. **Add/Edit Job Modal**:
   - Job Name input.
   - Destination selector dropdown (populated with active destinations from `rclone_destinations`).
   - Accounts selection (Multi-select or "All Accounts").
   - Backup Type (`Full`, `Incremental`, `Selective`).
   - Component checkboxes: Files, Databases, Mail, DNS, SSL, Cron.
   - Retention Days input.
4. **Backup History Modal / View** (`views/backup_history.php`):
   - Shows chronological list of past executions with duration, files transferred, status badges, and error diagnostics.

---

## 6. Implementation Steps

1. **Task 10**: Phase 3 Implementation Plan (This document).
2. **Task 11**: `lib/Backup/Collectors/` component collectors.
3. **Task 12**: `lib/Backup/BackupEngine.php` core orchestrator & transfer logic.
4. **Task 13**: `lib/Backup/BackupJobManager.php` & AJAX endpoints in `rcloneCWP.php`.
5. **Task 14**: `views/backup_jobs.php` UI, modals, and JS controller hooks in `views/layout.php`.
6. **Task 15**: Automated test suite `tests/backup_engine_test.php` & verification on live AlmaLinux server.
