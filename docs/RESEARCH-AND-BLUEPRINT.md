# rcloneCWP — Master Research & Blueprint
**Project**: rcloneCWP — Native CWP module for backup/restore using rclone  
**Author**: Research & Development Team  
**License**: Open Source (MIT)  
**Target**: CWP Pro / CentOS Web Panel  
**Last Updated**: 2026-09-06

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Findings](#2-research-findings)
3. [Market Analysis — JetBackup5 Feature Parity](#3-market-analysis--jetbackup5-feature-parity)
4. [Architecture Blueprint](#4-architecture-blueprint)
5. [Feature Specification](#5-feature-specification)
6. [Database Schema](#6-database-schema)
7. [File Structure & Module Layout](#7-file-structure--module-layout)
8. [Implementation Plan](#8-implementation-plan)
9. [Security Architecture](#9-security-architecture)
10. [Performance & Stability](#10-performance--stability)
11. [Developer Guide](#11-developer-guide)
12. [API Reference](#12-api-reference)
13. [Cron & Scheduling](#13-cron--scheduling)
14. [Testing & QA](#14-testing--qa)
15. [Deployment Guide](#15-deployment-guide)
16. [Roadmap & Milestones](#16-roadmap--milestones)

---

## 1. Executive Summary

### Vision
Create a **free, open-source, native CWP module** that provides enterprise-grade backup and restore functionality using rclone as the transport layer. The module will match or exceed JetBackup5's feature set while remaining completely free.

### Why This Exists
- **JetBackup5 costs money** — this module is free for everyone
- **rclone supports 70+ cloud providers** — more than JetBackup5
- **CWP has no native cloud backup module** — only FTP/SFTP/local
- **Existing CWP backup_manager2 is ionCube-encoded** — cannot be extended

### Core Principles
1. **Native CWP integration** — appears as a first-class module in the CWP sidebar
2. **rclone-powered** — leverages battle-tested cloud sync engine
3. **Free & open-source** — MIT license, community-driven
4. **JetBackup5 feature parity** — incremental backups, multiple destinations, hooks, REST API
5. **Secure by design** — encrypted configs, no credential leakage, input sanitization
6. **Performant** — async operations, progress tracking, low resource usage

---

## 2. Research Findings

### 2.1 CWP Module System

**How CWP Modules Work:**
- Modules are PHP files in `/usr/local/cwpsrv/htdocs/resources/admin/modules/`
- Menu items added via `/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php`
- Access URL: `http://server:2030/index.php?module=filename` (without .php)
- CWP runs as **root** — modules have full system access
- Modules can use CWP's built-in database (MySQL/MariaDB)
- ionCube encoding optional (for distribution)

**Module Registration (3rdparty.php):**
```php
<?php
// /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php
// Format: <menu_label>|<url>|<icon_class>|<target>
echo "Rclone Backup|/index.php?module=rcloneCWP|fa fa-cloud|main";
?>
```

**CWP Database:**
- Database: `root_cwp`
- Credentials: `/usr/local/cwp/.conf/mysql_db.cnf`
- Existing tables: `backups`, `users`, `packages`, `domains`, etc.

### 2.2 CWP Backup System Analysis

**Existing Backup Files:**
| File | Purpose | Encoded? |
|------|---------|----------|
| `cron_newbackup.php` | Main backup cron | No (PHP) |
| `cron_backup.php` | Legacy backup system | No (PHP) |
| `cron_restore_account.php` | Account restore | No (PHP) |
| `backup_manager2.php` | Backup UI module | Yes (ionCube) |
| `backups.php` | Legacy backup UI | Yes (ionCube) |
| `cron_restore_acc_cpanel.php` | cPanel migration restore | No (PHP) |

**Key Insight:** The cron scripts are NOT ionCube-encoded, meaning we can study and extend them. Only the UI modules are encoded.

**CWP Backup Process Flow:**
1. Cron triggers `cron_newbackup.php`
2. Reads backup config from `root_cwp.backups` table
3. Creates temp directory `/backup/.backup_temp/`
4. Iterates through users, collects:
   - Home directory files
   - Databases (MySQL dumps)
   - DNS zones
   - Email accounts
   - SSL certificates
   - Cron jobs
   - FTP accounts
5. Compresses into tar.gz
6. Transfers to destination (local/FTP/SFTP)
7. Cleans up temp files

### 2.3 JetBackup5 Feature Analysis

**JetBackup5 Pricing (as of 2026):**
- Server license: ~$XX/month (varies by plan)
- Additional cost for some destinations

**JetBackup5 Features:**
| Feature | Status | Notes |
|---------|--------|-------|
| Multiple destinations | ✅ | FTP, SFTP, S3, GDrive, Dropbox, B2, Wasabi |
| Incremental backups | ✅ | Binary/delta for some destinations |
| Full backups | ✅ | Complete account snapshots |
| Restore (full/partial) | ✅ | Per-file, per-db, per-email restore |
| Custom hooks | ✅ | Pre/post backup scripts |
| REST API | ✅ | Full API for automation |
| Scheduling | ✅ | Cron-based with retention |
| Encryption | ✅ | AES-256 for backups |
| Compression | ✅ | gzip, bzip2, zstd |
| Email notifications | ✅ | On success/failure |
| CLI tools | ✅ | `jetbackup` command |
| cPanel integration | ✅ | Native WHM/cPanel module |
| CWP integration | ✅ | Native CWP module |

### 2.4 rclone Capabilities

**Supported Backends (70+):**
- Google Drive, Google Cloud Storage
- Amazon S3, Compatible (Wasabi, MinIO, etc.)
- Backblaze B2
- Dropbox, OneDrive, Box
- SFTP, FTP, WebDAV
- Azure Blob, Azure Files
- Swift (OpenStack)
- Yandex, Hubic, PCloud, Mega, and more

**Key Features:**
- Incremental sync (`--checksum`, `--update`)
- Encryption (`crypt` remote)
- Compression (via pipe to gzip/zstd)
- Bandwidth limiting (`--bwlimit`)
- Parallel transfers (`--transfers`)
- Checksum verification
- Atomic moves
- VFS caching for performance

---

## 3. Market Analysis — JetBackup5 Feature Parity

### Feature Comparison Matrix

| Feature | JetBackup5 | rclone Module (Planned) |
|---------|------------|-------------------------|
| **Destinations** | | |
| Local | ✅ | ✅ |
| FTP | ✅ | ✅ |
| SFTP | ✅ | ✅ |
| Amazon S3 | ✅ | ✅ |
| Google Drive | ✅ | ✅ |
| Google Cloud Storage | ✅ | ✅ |
| Backblaze B2 | ✅ | ✅ |
| Wasabi | ✅ | ✅ |
| Dropbox | ✅ | ✅ |
| OneDrive | ✅ | ✅ |
| Azure Blob | ❌ | ✅ |
| WebDAV | ❌ | ✅ |
| **Backup Types** | | |
| Full Account | ✅ | ✅ |
| Incremental | ✅ | ✅ |
| Per-user | ✅ | ✅ |
| Per-database | ✅ | ✅ |
| Per-email | ✅ | ✅ |
| Per-DNS zone | ✅ | ✅ |
| **Restore** | | |
| Full restore | ✅ | ✅ |
| Partial restore | ✅ | ✅ |
| Cross-server restore | ✅ | ✅ |
| **Scheduling** | | |
| Cron-based | ✅ | ✅ |
| Retention policies | ✅ | ✅ |
| Multiple schedules | ✅ | ✅ |
| **Security** | | |
| Encryption at rest | ✅ | ✅ |
| Encrypted configs | ✅ | ✅ |
| **API** | | |
| REST API | ✅ | ✅ |
| CLI tools | ✅ | ✅ |
| **UI** | | |
| Admin panel | ✅ | ✅ |
| User panel | ✅ | ✅ |
| Progress tracking | ✅ | ✅ |
| **Notifications** | | |
| Email | ✅ | ✅ |
| Slack | ❌ | ✅ (hooks) |
| **Hooks** | | |
| Pre-backup | ✅ | ✅ |
| Post-backup | ✅ | ✅ |
| Pre-restore | ✅ | ✅ |
| Post-restore | ✅ | ✅ |

---

## 4. Architecture Blueprint

### 4.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    CWP Admin Panel                          │
│  ┌──────────────────────────────────────────────────────┐  │
│  │              rcloneCWP Module (PHP)               │  │
│  │  ┌─────────┐ ┌──────────┐ ┌───────────┐ ┌────────┐  │  │
│  │  │Dashboard│ │Backup    │ │Restore    │ │Destin. │  │  │
│  │  │         │ │Jobs      │ │Manager    │ │Config  │  │  │
│  │  └─────────┘ └──────────┘ └───────────┘ └────────┘  │  │
│  │  ┌─────────┐ ┌──────────┐ ┌───────────┐ ┌────────┐  │  │
│  │  │Schedules│ │Hooks     │ │Logs       │ │API     │  │  │
│  │  │         │ │          │ │           │ │        │  │  │
│  │  └─────────┘ └──────────┘ └───────────┘ └────────┘  │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    Core Engine (PHP)                         │
│  ┌─────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │ Backup      │ │ Restore      │ │ Scheduler            │ │
│  │ Engine      │ │ Engine       │ │ (Cron Manager)       │ │
│  └─────────────┘ └──────────────┘ └──────────────────────┘ │
│  ┌─────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │ Hook        │ │ Notification │ │ Encryption           │ │
│  │ System      │ │ System       │ │ Manager              │ │
│  └─────────────┘ └──────────────┘ └──────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    rclone Binary                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  rclone copy / move / sync / mount                   │  │
│  │  Config: /etc/rclone/rclone.conf                     │  │
│  │  VFS Cache: /var/cache/rclone/                       │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    Cloud Destinations                        │
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐   │
│  │S3    │ │GDrive│ │B2    │ │SFTP  │ │Dropbx│ │Azure │   │
│  └──────┘ └──────┘ └──────┘ └──────┘ └──────┘ └──────┘   │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Component Breakdown

**1. UI Layer (PHP Module Files)**
- `rcloneCWP.php` — Main module entry point
- `views/dashboard.php` — Overview with stats
- `views/backup_jobs.php` — Create/manage backup jobs
- `views/restore.php` — Restore interface
- `views/destinations.php` — Destination configuration
- `views/schedules.php` — Schedule management
- `views/hooks.php` — Hook management
- `views/logs.php` — Log viewer
- `views/api.php` — API management

**2. Core Engine (PHP Classes)**
- `lib/BackupEngine.php` — Orchestrates backup process
- `lib/RestoreEngine.php` — Orchestrates restore process
- `lib/Rclone.php` — rclone wrapper
- `lib/Destination.php` — Destination abstraction
- `lib/Schedule.php` — Schedule management
- `lib/Hook.php` — Hook execution
- `lib/Notification.php` — Email/Slack notifications
- `lib/Encryption.php` — Encryption/decryption
- `lib/Database.php` — Database abstraction
- `lib/Logger.php` — Logging system

**3. Database Layer**
- `rclone_destinations` — Destination configs
- `rclone_jobs` — Backup job definitions
- `rclone_schedules` — Schedule definitions
- `rcloneCWPs` — Backup records
- `rclone_hooks` — Hook definitions
- `rclone_logs` — Log entries

**4. Cron Scripts**
- `cron/rcloneCWP.php` — Main backup cron
- `cron/rclone_restore.php` — Restore cron
- `cron/rclone_cleanup.php` — Retention cleanup

**5. CLI Tools**
- `bin/rcloneCWP` — CLI for backup
- `bin/rclone-restore` — CLI for restore
- `bin/rclone-destination` — CLI for destination management

**6. API Layer**
- `api/rest.php` — REST API endpoint
- `api/index.php` — API router

---

## 5. Feature Specification

### 5.1 Backup Features

#### 5.1.1 Backup Types

**Full Backup**
- Complete account snapshot
- All files, databases, emails, DNS, SSL, cron, FTP
- Compressed with configurable algorithm (gzip/bzip2/zstd)
- Optional encryption

**Incremental Backup**
- Only changed files since last backup
- Uses rclone's `--checksum` or file modification time
- Binary log for databases (if available)
- Reduces storage and bandwidth

**Selective Backup**
- Choose specific components:
  - [ ] Home directory
  - [ ] Databases
  - [ ] Email accounts
  - [ ] DNS zones
  - [ ] SSL certificates
  - [ ] Cron jobs
  - [ ] FTP accounts
  - [ ] Specific directories only

#### 5.1.2 Backup Process

```
1. Pre-backup hooks execute
2. Lock file created (prevent concurrent runs)
3. Temp directory created: /backup/.backup_temp/{job_id}/
4. For each user in job:
   a. Collect file list (rsync-style)
   b. Dump databases to SQL
   c. Export DNS zones
   d. Export email accounts
   e. Export SSL certificates
   f. Export cron jobs
   g. Export FTP accounts
5. Compress collected data
6. Encrypt (if configured)
7. Transfer to destination via rclone
8. Verify transfer (checksum)
9. Update backup record in DB
10. Cleanup temp files
11. Remove old backups (retention policy)
12. Post-backup hooks execute
13. Send notification
14. Remove lock file
```

### 5.2 Restore Features

#### 5.2.1 Restore Types

**Full Restore**
- Restore entire account from backup
- Overwrites existing data
- Creates safety backup before restore

**Partial Restore**
- Select specific components:
  - [ ] Files only
  - [ ] Specific database
  - [ ] Specific email account
  - [ ] DNS zones
  - [ ] SSL certificates
  - [ ] Cron jobs

**Cross-Server Restore**
- Restore to different server
- Handles different paths/users
- Migration-friendly

#### 5.2.2 Restore Process

```
1. Pre-restore hooks execute
2. Safety backup of current state (if requested)
3. Download backup from destination
4. Decrypt (if encrypted)
5. Decompress
6. For each component:
   a. Restore files to temp
   b. Import databases
   c. Import DNS zones
   d. Import email accounts
   e. Import SSL certificates
   f. Import cron jobs
   g. Import FTP accounts
7. Move temp files to final location
8. Fix permissions
9. Post-restore hooks execute
10. Send notification
```

### 5.3 Destination Management

#### 5.3.1 Supported Destinations

| Type | Backend | Config Required |
|------|---------|-----------------|
| Local | local | Path |
| FTP | ftp | Host, user, pass, path |
| SFTP | sftp | Host, user, pass/key, path |
| Amazon S3 | s3 | Key, secret, region, bucket |
| Google Drive | gdrive | OAuth token |
| Google Cloud Storage | gcs | Key, bucket |
| Backblaze B2 | b2 | Key, ID, bucket |
| Wasabi | s3 (compatible) | Key, secret, endpoint |
| Dropbox | dropbox | Token |
| OneDrive | onedrive | Token |
| Azure Blob | azure | Account, key, container |
| WebDAV | webdav | URL, user, pass |

#### 5.3.2 Destination Features

- **Multiple destinations per job** — backup to multiple places simultaneously
- **Failover** — if primary destination fails, try secondary
- **Bandwidth limiting** — per-destination throttle
- **Encryption** — per-destination encryption settings
- **Path templates** — customizable backup path structure
- **Connection testing** — test destination before saving

### 5.4 Scheduling

#### 5.4.1 Schedule Types

- **Daily** — run every day at specified time
- **Weekly** — run on specific days
- **Monthly** — run on specific dates
- **Custom** — custom cron expression
- **Interval** — every N hours/minutes

#### 5.4.2 Retention Policies

- **Count-based** — keep last N backups
- **Time-based** — keep backups from last N days/weeks/months
- **Grandfather-father-son** — daily/weekly/monthly retention
- **Custom** — user-defined rules

### 5.5 Hook System

#### 5.5.1 Hook Points

| Hook | When | Use Case |
|------|------|----------|
| `pre_backup` | Before backup starts | Stop services, flush caches |
| `post_backup` | After backup completes | Start services, verify |
| `pre_restore` | Before restore starts | Stop services, create safety backup |
| `post_restore` | After restore completes | Start services, verify |
| `on_failure` | On any failure | Alert, cleanup |
| `on_success` | On success | Verify, notify |

#### 5.5.2 Hook Types

- **Shell scripts** — bash, sh, etc.
- **PHP scripts** — PHP CLI scripts
- **Python scripts** — Python 3 scripts
- **URL callbacks** — HTTP POST to URL

### 5.6 Notification System

- **Email** — SMTP-based email notifications
- **Slack** — Webhook-based Slack notifications
- **Telegram** — Bot-based Telegram notifications
- **Custom** — Webhook to any URL

### 5.7 REST API

#### 5.7.1 API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/destinations` | List destinations |
| POST | `/api/v1/destinations` | Create destination |
| GET | `/api/v1/destinations/{id}` | Get destination |
| PUT | `/api/v1/destinations/{id}` | Update destination |
| DELETE | `/api/v1/destinations/{id}` | Delete destination |
| POST | `/api/v1/destinations/{id}/test` | Test destination |
| GET | `/api/v1/jobs` | List backup jobs |
| POST | `/api/v1/jobs` | Create backup job |
| GET | `/api/v1/jobs/{id}` | Get backup job |
| PUT | `/api/v1/jobs/{id}` | Update backup job |
| DELETE | `/api/v1/jobs/{id}` | Delete backup job |
| POST | `/api/v1/jobs/{id}/run` | Run backup job now |
| GET | `/api/v1/backups` | List backups |
| GET | `/api/v1/backups/{id}` | Get backup details |
| DELETE | `/api/v1/backups/{id}` | Delete backup |
| POST | `/api/v1/backups/{id}/restore` | Restore from backup |
| GET | `/api/v1/schedules` | List schedules |
| POST | `/api/v1/schedules` | Create schedule |
| GET | `/api/v1/logs` | List logs |
| GET | `/api/v1/status` | System status |

#### 5.7.2 API Authentication

- **API Key** — Bearer token in header
- **IP Whitelist** — Restrict by IP
- **Rate Limiting** — Prevent abuse

---

## 6. Database Schema

### 6.1 Table: `rclone_destinations`

```sql
CREATE TABLE IF NOT EXISTS `rclone_destinations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('local','ftp','sftp','s3','gdrive','gcs','b2','wasabi','dropbox','onedrive','azure','webdav') NOT NULL,
    `config` TEXT NOT NULL COMMENT 'JSON encrypted config',
    `bandwidth_limit` VARCHAR(20) DEFAULT NULL COMMENT 'e.g., 10M, 1G',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `test_status` TINYINT(1) DEFAULT NULL COMMENT '0=fail, 1=pass, NULL=untested',
    `test_message` TEXT DEFAULT NULL,
    `last_test` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.2 Table: `rclone_jobs`

```sql
CREATE TABLE IF NOT EXISTS `rclone_jobs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `destinations` TEXT NOT NULL COMMENT 'JSON array of destination IDs',
    `users` TEXT DEFAULT NULL COMMENT 'JSON array of users, NULL=all',
    `components` TEXT NOT NULL COMMENT 'JSON: files,databases,emails,dns,ssl,cron,ftp',
    `backup_type` ENUM('full','incremental','selective') NOT NULL DEFAULT 'full',
    `compression` ENUM('gzip','bzip2','zstd','none') NOT NULL DEFAULT 'gzip',
    `compression_level` TINYINT NOT NULL DEFAULT 6 COMMENT '1-9 for gzip/bzip2, 1-22 for zstd',
    `encryption` TINYINT(1) NOT NULL DEFAULT 0,
    `encryption_password` VARCHAR(255) DEFAULT NULL COMMENT 'Encrypted',
    `exclude_paths` TEXT DEFAULT NULL COMMENT 'JSON array of paths to exclude',
    `retention_count` INT DEFAULT NULL COMMENT 'Keep N backups',
    `retention_days` INT DEFAULT NULL COMMENT 'Keep N days',
    `retention_months` INT DEFAULT NULL COMMENT 'Keep N months',
    `retention_years` INT DEFAULT NULL COMMENT 'Keep N years',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run` DATETIME DEFAULT NULL,
    `last_status` ENUM('success','failure','running','never') DEFAULT NULL,
    `last_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_last_run` (`last_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.3 Table: `rclone_schedules`

```sql
CREATE TABLE IF NOT EXISTS `rclone_schedules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `job_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('daily','weekly','monthly','custom','interval') NOT NULL,
    `time` TIME NOT NULL DEFAULT '00:00:00',
    `days` VARCHAR(50) DEFAULT NULL COMMENT 'JSON array for weekly: [0,1,2,3,4,5,6]',
    `dates` VARCHAR(50) DEFAULT NULL COMMENT 'JSON array for monthly: [1,15,30]',
    `cron_expression` VARCHAR(100) DEFAULT NULL COMMENT 'For custom type',
    `interval_value` INT DEFAULT NULL COMMENT 'For interval type',
    `interval_unit` ENUM('minutes','hours') DEFAULT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run` DATETIME DEFAULT NULL,
    `next_run` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_next_run` (`next_run`),
    CONSTRAINT `fk_schedule_job` FOREIGN KEY (`job_id`) REFERENCES `rclone_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.4 Table: `rcloneCWPs`

```sql
CREATE TABLE IF NOT EXISTS `rcloneCWPs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `job_id` INT(11) NOT NULL,
    `destination_id` INT(11) NOT NULL,
    `user` VARCHAR(255) NOT NULL,
    `backup_type` ENUM('full','incremental') NOT NULL,
    `components` TEXT NOT NULL COMMENT 'JSON array',
    `remote_path` VARCHAR(500) NOT NULL COMMENT 'Path on destination',
    `local_path` VARCHAR(500) DEFAULT NULL COMMENT 'Local temp path',
    `size_bytes` BIGINT DEFAULT NULL,
    `file_count` INT DEFAULT NULL,
    `status` ENUM('running','completed','failed','cancelled') NOT NULL DEFAULT 'running',
    `progress` TINYINT NOT NULL DEFAULT 0 COMMENT '0-100',
    `message` TEXT DEFAULT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    `duration_seconds` INT DEFAULT NULL,
    `checksum` VARCHAR(128) DEFAULT NULL COMMENT 'SHA256 of backup',
    `encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `compressed` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_destination_id` (`destination_id`),
    KEY `idx_user` (`user`),
    KEY `idx_status` (`status`),
    KEY `idx_started_at` (`started_at`),
    CONSTRAINT `fk_backup_job` FOREIGN KEY (`job_id`) REFERENCES `rclone_jobs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_backup_destination` FOREIGN KEY (`destination_id`) REFERENCES `rclone_destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.5 Table: `rclone_hooks`

```sql
CREATE TABLE IF NOT EXISTS `rclone_hooks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `hook_point` ENUM('pre_backup','post_backup','pre_restore','post_restore','on_failure','on_success') NOT NULL,
    `type` ENUM('shell','php','python','url') NOT NULL,
    `content` TEXT NOT NULL COMMENT 'Script content or URL',
    `timeout` INT NOT NULL DEFAULT 300 COMMENT 'Seconds',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `run_order` INT NOT NULL DEFAULT 100,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hook_point` (`hook_point`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.6 Table: `rclone_logs`

```sql
CREATE TABLE IF NOT EXISTS `rclone_logs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `level` ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `job_id` INT DEFAULT NULL,
    `backup_id` INT DEFAULT NULL,
    `message` TEXT NOT NULL,
    `context` TEXT DEFAULT NULL COMMENT 'JSON context data',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_level` (`level`),
    KEY `idx_category` (`category`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_backup_id` (`backup_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.7 Table: `rclone_api_keys`

```sql
CREATE TABLE IF NOT EXISTS `rclone_api_keys` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `api_key` VARCHAR(255) NOT NULL,
    `permissions` TEXT NOT NULL COMMENT 'JSON array of permissions',
    `ip_whitelist` TEXT DEFAULT NULL COMMENT 'JSON array of IPs',
    `rate_limit` INT NOT NULL DEFAULT 100 COMMENT 'Requests per minute',
    `last_used` DATETIME DEFAULT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_api_key` (`api_key`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.8 Table: `rclone_notifications`

```sql
CREATE TABLE IF NOT EXISTS `rclone_notifications` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `type` ENUM('email','slack','telegram','webhook') NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `config` TEXT NOT NULL COMMENT 'JSON config (SMTP, webhook URL, etc.)',
    `events` TEXT NOT NULL COMMENT 'JSON array: backup_success, backup_failure, etc.',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_sent` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 7. File Structure & Module Layout

```
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
├── rcloneCWP.php              # Main module entry point
├── config.php                     # Configuration constants
├── install.php                    # Installation script
├── uninstall.php                  # Uninstall script
│
├── assets/
│   ├── css/
│   │   └── rcloneCWP.css      # Module styles
│   ├── js/
│   │   └── rcloneCWP.js       # Module JavaScript
│   └── img/
│       └── icons/                 # Destination type icons
│
├── lib/
│   ├── BackupEngine.php           # Backup orchestration
│   ├── RestoreEngine.php          # Restore orchestration
│   ├── Rclone.php                 # rclone wrapper
│   ├── Destination.php            # Destination abstraction
│   ├── DestinationFactory.php     # Destination factory
│   ├── Schedule.php               # Schedule management
│   ├── Hook.php                   # Hook execution
│   ├── Notification.php           # Notification system
│   ├── Encryption.php             # Encryption/decryption
│   ├── Database.php               # Database abstraction
│   ├── Logger.php                 # Logging system
│   ├── Validator.php              # Input validation
│   ├── API.php                    # REST API handler
│   └── CLI.php                    # CLI handler
│
├── destinations/
│   ├── Local.php                  # Local destination
│   ├── FTP.php                    # FTP destination
│   ├── SFTP.php                   # SFTP destination
│   ├── S3.php                     # S3 destination
│   ├── GoogleDrive.php            # Google Drive destination
│   ├── GCS.php                    # Google Cloud Storage
│   ├── B2.php                     # Backblaze B2
│   ├── Dropbox.php                # Dropbox destination
│   ├── OneDrive.php               # OneDrive destination
│   ├── Azure.php                  # Azure Blob
│   └── WebDAV.php                 # WebDAV destination
│
├── views/
│   ├── dashboard.php              # Dashboard/overview
│   ├── backup_jobs.php            # Backup job list
│   ├── backup_job_form.php        # Backup job create/edit
│   ├── destinations.php           # Destination list
│   ├── destination_form.php       # Destination create/edit
│   ├── destination_test.php       # Test destination
│   ├── restore.php                # Restore interface
│   ├── restore_progress.php       # Restore progress
│   ├── backups.php                # Backup list
│   ├── backup_detail.php          # Backup details
│   ├── schedules.php              # Schedule list
│   ├── schedule_form.php          # Schedule create/edit
│   ├── hooks.php                  # Hook list
│   ├── hook_form.php              # Hook create/edit
│   ├── logs.php                   # Log viewer
│   ├── notifications.php          # Notification list
│   ├── notification_form.php      # Notification create/edit
│   ├── api_keys.php               # API key management
│   ├── api_key_form.php           # API key create/edit
│   ├── settings.php               # Module settings
│   └── partials/
│       ├── header.php             # Common header
│       ├── footer.php             # Common footer
│       ├── pagination.php         # Pagination helper
│       └── progress_bar.php       # Progress bar
│
├── cron/
│   ├── rcloneCWP.php          # Main backup cron
│   ├── rclone_restore.php         # Restore cron
│   ├── rclone_cleanup.php         # Retention cleanup
│   └── rclone_test.php            # Test cron
│
├── api/
│   ├── index.php                  # API router
│   ├── auth.php                   # API authentication
│   ├── v1/
│   │   ├── destinations.php       # Destination endpoints
│   │   ├── jobs.php               # Job endpoints
│   │   ├── backups.php            # Backup endpoints
│   │   ├── schedules.php          # Schedule endpoints
│   │   ├── hooks.php              # Hook endpoints
│   │   └── logs.php               # Log endpoints
│   └── middleware/
│       ├── AuthMiddleware.php     # Auth middleware
│       ├── RateLimitMiddleware.php # Rate limiting
│       └── CORSMiddleware.php     # CORS handling
│
├── cli/
│   ├── rcloneCWP              # Backup CLI
│   ├── rclone-restore             # Restore CLI
│   ├── rclone-destination         # Destination CLI
│   ├── rclone-schedule            # Schedule CLI
│   └── rclone-log                 # Log CLI
│
├── language/
│   ├── en.php                     # English
│   └── bn.php                     # Bengali
│
├── templates/
│   ├── email/
│   │   ├── backup_success.php     # Backup success email
│   │   ├── backup_failure.php     # Backup failure email
│   │   └── restore_complete.php   # Restore complete email
│   └── slack/
│       └── notification.php       # Slack notification
│
├── sql/
│   ├── install.sql                # Installation SQL
│   ├── uninstall.sql              # Uninstall SQL
│   └── updates/
│       ├── 1.0.0.sql              # Version 1.0.0
│       └── 1.1.0.sql              # Version 1.1.0
│
├── tests/
│   ├── phpunit.xml                # PHPUnit config
│   ├── bootstrap.php              # Test bootstrap
│   ├── unit/
│   │   ├── RcloneTest.php         # Rclone wrapper tests
│   │   ├── DestinationTest.php    # Destination tests
│   │   ├── BackupEngineTest.php   # Backup engine tests
│   │   └── EncryptionTest.php     # Encryption tests
│   └── integration/
│       ├── BackupFlowTest.php     # Full backup flow
│       └── RestoreFlowTest.php    # Full restore flow
│
├── logs/
│   └── .htaccess                  # Deny access to logs
│
├── cache/
│   └── .htaccess                  # Deny access to cache
│
├── README.md                      # Module documentation
├── CHANGELOG.md                   # Version history
└── LICENSE                        # MIT License
```

---

## 8. Implementation Plan

### Phase 1: Foundation (Week 1-2)

**Goal:** Basic module structure, database, and rclone wrapper

| Task | Description | Priority |
|------|-------------|----------|
| 1.1 | Create module directory structure | High |
| 1.2 | Create database schema | High |
| 1.3 | Implement Database.php class | High |
| 1.4 | Implement Logger.php class | High |
| 1.5 | Implement Rclone.php wrapper | High |
| 1.6 | Implement Validator.php class | High |
| 1.7 | Create 3rdparty.php menu entry | High |
| 1.8 | Create main module entry point | High |
| 1.9 | Create installation script | High |
| 1.10 | Create uninstallation script | Medium |

### Phase 2: Destinations (Week 3-4)

**Goal:** All destination types working

| Task | Description | Priority |
|------|-------------|----------|
| 2.1 | Implement Destination base class | High |
| 2.2 | Implement DestinationFactory | High |
| 2.3 | Implement Local destination | High |
| 2.4 | Implement FTP destination | High |
| 2.5 | Implement SFTP destination | High |
| 2.6 | Implement S3 destination | High |
| 2.7 | Implement Google Drive destination | High |
| 2.8 | Implement GCS destination | Medium |
| 2.9 | Implement B2 destination | Medium |
| 2.10 | Implement Dropbox destination | Medium |
| 2.11 | Implement OneDrive destination | Medium |
| 2.12 | Implement Azure destination | Low |
| 2.13 | Implement WebDAV destination | Low |
| 2.14 | Create destination UI (list, form, test) | High |

### Phase 3: Backup Engine (Week 5-6)

**Goal:** Full and incremental backups working

| Task | Description | Priority |
|------|-------------|----------|
| 3.1 | Implement BackupEngine class | High |
| 3.2 | Implement file collection | High |
| 3.3 | Implement database dump | High |
| 3.4 | Implement DNS zone export | High |
| 3.5 | Implement email account export | High |
| 3.6 | Implement SSL certificate export | High |
| 3.7 | Implement cron job export | High |
| 3.8 | Implement FTP account export | High |
| 3.9 | Implement compression (gzip/bzip2/zstd) | High |
| 3.10 | Implement encryption | High |
| 3.11 | Implement rclone transfer | High |
| 3.12 | Implement incremental backup logic | High |
| 3.13 | Implement backup verification | High |
| 3.14 | Create backup job UI | High |
| 3.15 | Create backup list UI | High |

### Phase 4: Restore Engine (Week 7-8)

**Goal:** Full and partial restores working

| Task | Description | Priority |
|------|-------------|----------|
| 4.1 | Implement RestoreEngine class | High |
| 4.2 | Implement file restore | High |
| 4.3 | Implement database import | High |
| 4.4 | Implement DNS zone import | High |
| 4.5 | Implement email account import | High |
| 4.6 | Implement SSL certificate import | High |
| 4.7 | Implement cron job import | High |
| 4.8 | Implement FTP account import | High |
| 4.9 | Implement decryption | High |
| 4.10 | Implement decompression | High |
| 4.11 | Implement safety backup before restore | High |
| 4.12 | Create restore UI | High |
| 4.13 | Create restore progress UI | Medium |

### Phase 5: Scheduling (Week 9)

**Goal:** Cron-based scheduling with retention

| Task | Description | Priority |
|------|-------------|----------|
| 5.1 | Implement Schedule class | High |
| 5.2 | Create cron scripts | High |
| 5.3 | Implement retention policies | High |
| 5.4 | Create schedule UI | High |
| 5.5 | Implement next-run calculation | Medium |

### Phase 6: Hooks & Notifications (Week 10)

**Goal:** Hook system and notifications

| Task | Description | Priority |
|------|-------------|----------|
| 6.1 | Implement Hook class | High |
| 6.2 | Implement hook execution | High |
| 6.3 | Create hook UI | Medium |
| 6.4 | Implement Notification class | High |
| 6.5 | Implement email notifications | High |
| 6.6 | Implement Slack notifications | Medium |
| 6.7 | Implement Telegram notifications | Medium |
| 6.8 | Create notification UI | Medium |

### Phase 7: API & CLI (Week 11)

**Goal:** REST API and CLI tools

| Task | Description | Priority |
|------|-------------|----------|
| 7.1 | Implement API router | High |
| 7.2 | Implement API authentication | High |
| 7.3 | Implement rate limiting | Medium |
| 7.4 | Create all API endpoints | High |
| 7.5 | Create CLI tools | High |
| 7.6 | Create API key management UI | Medium |

### Phase 8: Dashboard & Polish (Week 12)

**Goal:** Dashboard, settings, and final polish

| Task | Description | Priority |
|------|-------------|----------|
| 8.1 | Create dashboard UI | High |
| 8.2 | Create settings UI | High |
| 8.3 | Create log viewer UI | High |
| 8.4 | Implement progress tracking | High |
| 8.5 | Add Bengali language support | Medium |
| 8.6 | Performance optimization | Medium |
| 8.7 | Security audit | High |
| 8.8 | Documentation | High |

---

## 9. Security Architecture

### 9.1 Threat Model

| Threat | Impact | Mitigation |
|--------|--------|------------|
| SQL Injection | Database compromise | Prepared statements, input validation |
| XSS | Session hijacking | Output encoding, CSP headers |
| CSRF | Unauthorized actions | CSRF tokens on all forms |
| Command Injection | Server compromise | Input sanitization, escapeshellarg |
| Credential Theft | Cloud account compromise | Encrypted storage, access controls |
| Path Traversal | File system access | Path validation, chroot |
| Brute Force | API abuse | Rate limiting, lockout |
| Man-in-the-Middle | Data interception | TLS for all connections |

### 9.2 Security Measures

#### 9.2.1 Input Validation

```php
class Validator {
    // All user input validated before use
    public static function string($input, $maxLength = 255) {
        $input = trim($input);
        $input = strip_tags($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        return substr($input, 0, $maxLength);
    }
    
    public static function int($input, $min = null, $max = null) {
        $input = filter_var($input, FILTER_VALIDATE_INT);
        if ($input === false) return null;
        if ($min !== null && $input < $min) return $min;
        if ($max !== null && $input > $max) return $max;
        return $input;
    }
    
    public static function path($input) {
        // Prevent path traversal
        $input = str_replace('..', '', $input);
        $input = preg_replace('/\.+/', '.', $input);
        $input = trim($input, '/');
        return $input;
    }
    
    public static function cronExpression($input) {
        // Validate cron expression
        $pattern = '/^(\*|\d+|\d+-\d+|\d+\/\d+|\d+,\d+) (\*|\d+|\d+-\d+|\d+\/\d+|\d+,\d+) (\*|\d+|\d+-\d+|\d+\/\d+|\d+,\d+) (\*|\d+|\d+-\d+|\d+\/\d+|\d+,\d+) (\*|\d+|\d+-\d+|\d+\/\d+|\d+,\d+)$/';
        return preg_match($pattern, $input) ? $input : null;
    }
}
```

#### 9.2.2 Credential Encryption

```php
class Encryption {
    private $cipher = 'aes-256-gcm';
    private $key;
    
    public function __construct() {
        // Key stored in separate file outside web root
        $keyFile = '/usr/local/cwp/.conf/rclone_module_key.conf';
        if (!file_exists($keyFile)) {
            $this->key = random_bytes(32);
            file_put_contents($keyFile, base64_encode($this->key));
            chmod($keyFile, 0600);
        } else {
            $this->key = base64_decode(file_get_contents($keyFile));
        }
    }
    
    public function encrypt($data) {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($data, $this->cipher, $this->key, 0, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }
    
    public function decrypt($data) {
        $data = base64_decode($data);
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);
        return openssl_decrypt($encrypted, $this->cipher, $this->key, 0, $iv, $tag);
    }
}
```

#### 9.2.3 Command Execution Security

```php
class Rclone {
    public function execute($command, $args = []) {
        // Whitelist allowed commands
        $allowedCommands = ['copy', 'move', 'sync', 'ls', 'lsd', 'size', 'delete', 'mkdir', 'rmdir', 'purge', 'cat', 'checksum'];
        
        if (!in_array($command, $allowedCommands)) {
            throw new Exception("Command not allowed: $command");
        }
        
        // Sanitize all arguments
        $safeArgs = array_map(function($arg) {
            return escapeshellarg($arg);
        }, $args);
        
        $cmd = sprintf(
            '/usr/bin/rclone %s %s --config %s 2>&1',
            escapeshellcmd($command),
            implode(' ', $safeArgs),
            escapeshellarg('/etc/rclone/rclone.conf')
        );
        
        exec($cmd, $output, $returnCode);
        
        return [
            'output' => $output,
            'returnCode' => $returnCode
        ];
    }
}
```

#### 9.2.4 CSRF Protection

```php
class CSRF {
    public static function generateToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validateToken($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function getInputField() {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}
```

#### 9.2.5 API Security

```php
class AuthMiddleware {
    public function handle($request) {
        // Check API key
        $apiKey = $request->getHeader('Authorization');
        if (!$apiKey || !preg_match('/^Bearer\s+(.+)$/i', $apiKey, $matches)) {
            return $this->error(401, 'Missing or invalid API key');
        }
        
        $key = $matches[1];
        
        // Validate key against database
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM rclone_api_keys WHERE api_key = ? AND enabled = 1");
        $stmt->execute([$key]);
        $keyData = $stmt->fetch();
        
        if (!$keyData) {
            return $this->error(401, 'Invalid API key');
        }
        
        // Check IP whitelist
        if ($keyData['ip_whitelist']) {
            $allowedIPs = json_decode($keyData['ip_whitelist'], true);
            if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
                return $this->error(403, 'IP not whitelisted');
            }
        }
        
        // Check rate limit
        if (!$this->checkRateLimit($keyData)) {
            return $this->error(429, 'Rate limit exceeded');
        }
        
        return true;
    }
}
```

### 9.3 File Permissions

| Path | Owner | Permissions | Notes |
|------|-------|-------------|-------|
| Module files | root:root | 0644 | Read-only for web |
| Config files | root:root | 0600 | Only root can read |
| Log files | root:root | 0600 | Only root can read |
| Cache dir | root:root | 0700 | Only root can access |
| Temp files | root:root | 0600 | Only root can read |
| rclone config | root:root | 0600 | Only root can read |
| Encryption key | root:root | 0600 | Only root can read |

---

## 10. Performance & Stability

### 10.1 Performance Optimization

#### 10.1.1 rclone Performance Tuning

```bash
# rclone global options for performance
--transfers 4              # Number of parallel transfers
--checkers 8               # Number of checkers
--buffer-size 32M          # Buffer size for each transfer
--drive-chunk-size 64M     # Google Drive chunk size
--fast-list                # Use recursive listing (faster for some backends)
--tpslimit 10              # API calls per second
--tpslimit-burst 20        # Burst API calls
```

#### 10.1.2 PHP Performance

- **Memory limit**: 512M for backup operations
- **Execution time**: 3600+ seconds for large backups
- **Output buffering**: Disabled for progress tracking
- **Database**: Persistent connections, query caching

#### 10.1.3 Database Performance

- **Indexes**: Proper indexes on all query columns
- **Partitioning**: Log table partitioned by date
- **Cleanup**: Automatic old log cleanup
- **Connection pooling**: Persistent MySQL connections

### 10.2 Stability Measures

#### 10.2.1 Lock System

```php
class Lock {
    private $lockFile;
    
    public function __construct($name) {
        $this->lockFile = "/tmp/rcloneCWP_{$name}.lock";
    }
    
    public function acquire() {
        if (file_exists($this->lockFile)) {
            $pid = file_get_contents($this->lockFile);
            if (posix_kill($pid, 0)) {
                return false; // Process still running
            }
            // Stale lock, remove it
            unlink($this->lockFile);
        }
        
        file_put_contents($this->lockFile, getmypid());
        return true;
    }
    
    public function release() {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }
}
```

#### 10.2.2 Error Handling

```php
class ErrorHandler {
    public static function handleException($e) {
        // Log the error
        Logger::error($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Send notification
        Notification::send('error', 'Backup Error', $e->getMessage());
        
        // Update backup status
        if (isset($GLOBALS['currentBackupId'])) {
            Database::getInstance()->update('rcloneCWPs', [
                'status' => 'failed',
                'message' => $e->getMessage()
            ], ['id' => $GLOBALS['currentBackupId']]);
        }
    }
}
```

#### 10.2.3 Progress Tracking

```php
class Progress {
    private $backupId;
    
    public function update($percent, $message = '') {
        Database::getInstance()->update('rcloneCWPs', [
            'progress' => $percent,
            'message' => $message
        ], ['id' => $this->backupId]);
        
        // Also write to temp file for real-time monitoring
        $progressFile = "/tmp/rcloneCWP_{$this->backupId}.progress";
        file_put_contents($progressFile, json_encode([
            'percent' => $percent,
            'message' => $message,
            'timestamp' => time()
        ]));
    }
    
    public function get() {
        $progressFile = "/tmp/rcloneCWP_{$this->backupId}.progress";
        if (file_exists($progressFile)) {
            return json_decode(file_get_contents($progressFile), true);
        }
        return null;
    }
}
```

### 10.3 Resource Limits

| Resource | Limit | Notes |
|----------|-------|-------|
| Memory | 512M | Per backup process |
| CPU | 50% | Nice level 10 |
| I/O | Idle | ionice class 3 |
| Network | Configurable | Per-destination bandwidth limit |
| Disk | 2x backup size | Temp space required |
| Time | 3600s default | Configurable per job |

---

## 11. Developer Guide

### 11.1 Setting Up Development Environment

```bash
# Clone the repository
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/
git clone https://github.com/yourusername/rcloneCWP.git rcloneCWP

# Set permissions
chown -R root:root rcloneCWP/
find rcloneCWP/ -type f -exec chmod 644 {} \;
find rcloneCWP/ -type d -exec chmod 755 {} \;
chmod 600 rcloneCWP/config.php

# Create required directories
mkdir -p rcloneCWP/logs rcloneCWP/cache
chmod 700 rcloneCWP/logs rcloneCWP/cache

# Run installation
php rcloneCWP/install.php
```

### 11.2 Adding a New Destination Type

**Step 1: Create destination class**

```php
<?php
// destinations/NewDestination.php

class NewDestination extends Destination {
    public $type = 'newdestination';
    public $name = 'New Destination';
    public $icon = 'fa-cloud';
    
    public function validateConfig($config) {
        $required = ['host', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        return true;
    }
    
    public function testConnection($config) {
        try {
            $this->validateConfig($config);
            
            // Test connection logic here
            $result = $this->rclone->execute('ls', [
                "{$this->type}://{$config['host']}/"
            ]);
            
            return $result['returnCode'] === 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function buildRcloneConfig($config) {
        return [
            'type' => $this->type,
            'host' => $config['host'],
            'user' => $config['username'],
            'pass' => $this->rclone->obscure($config['password']),
        ];
    }
    
    public function getStoragePath($config, $user) {
        return "{$this->type}://{$config['host']}/backups/{$user}/";
    }
}
```

**Step 2: Register in DestinationFactory**

```php
// lib/DestinationFactory.php

class DestinationFactory {
    public static $destinations = [
        'local' => 'Local',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
        's3' => 'S3',
        'gdrive' => 'GoogleDrive',
        'newdestination' => 'NewDestination', // Add this line
    ];
}
```

**Step 3: Add language strings**

```php
// language/en.php

$lang['destination_newdestination'] = 'New Destination';
$lang['newdestination_host'] = 'Host';
$lang['newdestination_username'] = 'Username';
$lang['newdestination_password'] = 'Password';
```

### 11.3 Adding a New Hook

**Step 1: Create hook script**

```bash
#!/bin/bash
# hooks/pre_backup_example.sh

# This script runs before backup starts
echo "Pre-backup hook executed at $(date)"

# Example: Flush caches
# systemctl restart memcached

# Example: Create safety snapshot
# lvcreate --size 1G --snapshot --name backup_snap /dev/vg0/lv_root

exit 0
```

**Step 2: Register via UI**

1. Go to CWP Admin → Rclone Backup → Hooks
2. Click "Add Hook"
3. Fill in:
   - Name: Pre-backup Cache Flush
   - Hook Point: pre_backup
   - Type: shell
   - Content: Paste script content
   - Timeout: 300

### 11.4 Using the API

**Authentication:**
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
     https://server:2030/index.php?module=rcloneCWP&action=api
```

**List destinations:**
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
     https://server:2030/index.php?module=rcloneCWP&action=api&endpoint=destinations
```

**Create backup job:**
```bash
curl -X POST \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -H "Content-Type: application/json" \
     -d '{"name":"Daily Backup","destinations":[1],"components":["files","databases"],"backup_type":"full"}' \
     https://server:2030/index.php?module=rcloneCWP&action=api&endpoint=jobs
```

**Run backup job:**
```bash
curl -X POST \
     -H "Authorization: Bearer YOUR_API_KEY" \
     https://server:2030/index.php?module=rcloneCWP&action=api&endpoint=jobs/1/run
```

### 11.5 Using CLI Tools

```bash
# List all backup jobs
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP job list

# Run a backup job
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP job run 1

# List backups
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP backup list

# Restore from backup
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP restore 123 --user=builderh

# List destinations
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP destination list

# Test destination
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP destination test 1

# View logs
/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP log view --lines=50
```

---

## 12. API Reference

### 12.1 Authentication

All API requests require a Bearer token in the Authorization header:

```
Authorization: Bearer YOUR_API_KEY
```

### 12.2 Endpoints

#### Destinations

**GET /api/v1/destinations**
- Returns list of all destinations
- Response: `[{id, name, type, enabled, last_test, ...}]`

**POST /api/v1/destinations**
- Creates a new destination
- Body: `{name, type, config, bandwidth_limit, enabled}`
- Response: `{id, message: "Created successfully"}`

**GET /api/v1/destinations/{id}**
- Returns destination details
- Response: `{id, name, type, config, ...}`

**PUT /api/v1/destinations/{id}**
- Updates a destination
- Body: `{name, config, bandwidth_limit, enabled}`
- Response: `{message: "Updated successfully"}`

**DELETE /api/v1/destinations/{id}**
- Deletes a destination
- Response: `{message: "Deleted successfully"}`

**POST /api/v1/destinations/{id}/test**
- Tests destination connection
- Response: `{success: true/false, message: "..."}`

#### Jobs

**GET /api/v1/jobs**
- Returns list of all backup jobs
- Response: `[{id, name, backup_type, enabled, last_run, last_status, ...}]`

**POST /api/v1/jobs**
- Creates a new backup job
- Body: `{name, description, destinations, users, components, backup_type, compression, encryption, ...}`
- Response: `{id, message: "Created successfully"}`

**GET /api/v1/jobs/{id}**
- Returns job details
- Response: `{id, name, destinations, components, ...}`

**PUT /api/v1/jobs/{id}**
- Updates a job
- Body: `{name, destinations, components, ...}`
- Response: `{message: "Updated successfully"}`

**DELETE /api/v1/jobs/{id}**
- Deletes a job
- Response: `{message: "Deleted successfully"}`

**POST /api/v1/jobs/{id}/run**
- Runs a backup job immediately
- Response: `{backup_id, message: "Backup started"}`

#### Backups

**GET /api/v1/backups**
- Returns list of backups
- Query params: `?job_id={id}&user={user}&status={status}&limit={n}&offset={n}`
- Response: `[{id, job_id, user, status, progress, size_bytes, ...}]`

**GET /api/v1/backups/{id}**
- Returns backup details
- Response: `{id, job_id, user, status, progress, remote_path, size_bytes, ...}`

**DELETE /api/v1/backups/{id}**
- Deletes a backup
- Response: `{message: "Deleted successfully"}`

**POST /api/v1/backups/{id}/restore**
- Restores from backup
- Body: `{user: "target_user", components: ["files", "databases"], safety_backup: true}`
- Response: `{restore_id, message: "Restore started"}`

#### Schedules

**GET /api/v1/schedules**
- Returns list of schedules
- Response: `[{id, job_id, name, type, time, next_run, ...}]`

**POST /api/v1/schedules**
- Creates a new schedule
- Body: `{job_id, name, type, time, days, dates, cron_expression, interval_value, interval_unit}`
- Response: `{id, message: "Created successfully"}`

#### Logs

**GET /api/v1/logs**
- Returns log entries
- Query params: `?level={level}&category={cat}&limit={n}&offset={n}`
- Response: `[{id, level, category, message, created_at, ...}]`

#### Status

**GET /api/v1/status**
- Returns system status
- Response: `{rclone_version, total_backups, total_size, disk_free, ...}`

---

## 13. Cron & Scheduling

### 13.1 Cron Scripts

**Main Backup Cron (`cron/rcloneCWP.php`):**
```php
#!/usr/local/cwp/php71/bin/php
<?php
// Runs every 5 minutes via system cron
// Checks for scheduled jobs and executes them

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Schedule.php';
require_once __DIR__ . '/../lib/BackupEngine.php';

$schedule = new Schedule();
$dueJobs = $schedule->getDueJobs();

foreach ($dueJobs as $job) {
    $engine = new BackupEngine($job);
    $engine->execute();
}
```

**System Crontab Entry:**
```bash
# /etc/crontab
*/5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1
```

**Cleanup Cron (`cron/rclone_cleanup.php`):**
```php
#!/usr/local/cwp/php71/bin/php
<?php
// Runs daily at 2 AM
// Cleans up old backups based on retention policies

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/BackupEngine.php';

$engine = new BackupEngine();
$engine->cleanup();
```

**System Crontab Entry:**
```bash
# /etc/crontab
0 2 * * * root /usr/local/cwp/php71/bin/php /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cron/rclone_cleanup.php >> /var/log/rcloneCWP_cleanup.log 2>&1
```

### 13.2 Schedule Types

**Daily:**
- Runs every day at specified time
- Config: `time = "02:00:00"`

**Weekly:**
- Runs on specified days
- Config: `days = [0, 2, 4]` (Sun, Tue, Thu)

**Monthly:**
- Runs on specified dates
- Config: `dates = [1, 15]` (1st and 15th)

**Custom:**
- Full cron expression
- Config: `cron_expression = "0 2 * * 1,3,5"`

**Interval:**
- Every N minutes/hours
- Config: `interval_value = 6`, `interval_unit = "hours"`

---

## 14. Testing & QA

### 14.1 Unit Tests

```bash
# Run all unit tests
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
./vendor/bin/phpunit tests/unit/

# Run specific test
./vendor/bin/phpunit tests/unit/RcloneTest.php
```

### 14.2 Integration Tests

```bash
# Run full backup flow test
./vendor/bin/phpunit tests/integration/BackupFlowTest.php

# Run full restore flow test
./vendor/bin/phpunit tests/integration/RestoreFlowTest.php
```

### 14.3 Manual Test Checklist

- [ ] Module appears in CWP sidebar
- [ ] Dashboard loads with correct stats
- [ ] Create local destination
- [ ] Test local destination connection
- [ ] Create FTP destination
- [ ] Test FTP destination connection
- [ ] Create Google Drive destination
- [ ] Test Google Drive destination connection
- [ ] Create backup job with local destination
- [ ] Run backup job manually
- [ ] Verify backup appears in list
- [ ] Download backup file
- [ ] Restore from backup
- [ ] Verify restored data
- [ ] Create schedule
- [ ] Verify schedule runs automatically
- [ ] Test retention policy
- [ ] Test pre-backup hook
- [ ] Test post-backup hook
- [ ] Test email notification
- [ ] Test API authentication
- [ ] Test API endpoints
- [ ] Test CLI tools
- [ ] Test with multiple users
- [ ] Test with large files (>1GB)
- [ ] Test incremental backup
- [ ] Test encryption
- [ ] Test concurrent backups

---

## 15. Deployment Guide

### 15.1 Prerequisites

- CWP Pro installed
- rclone installed (`rclone --version` >= 1.60)
- PHP 7.1+ with CLI
- MySQL/MariaDB
- Root access

### 15.2 Installation

```bash
# 1. Clone module
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/
git clone https://github.com/yourusername/rcloneCWP.git rcloneCWP

# 2. Set permissions
chown -R root:root rcloneCWP/
find rcloneCWP/ -type f -exec chmod 644 {} \;
find rcloneCWP/ -type d -exec chmod 755 {} \;
chmod 700 rcloneCWP/logs rcloneCWP/cache

# 3. Create required directories
mkdir -p /var/log/rcloneCWP
mkdir -p /var/cache/rclone

# 4. Run installer
php rcloneCWP/install.php

# 5. Add menu entry
echo 'Rclone Backup|/index.php?module=rcloneCWP|fa fa-cloud|main' >> /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php

# 6. Add cron entries
echo '*/5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1' >> /etc/crontab
echo '0 2 * * * root /usr/local/cwp/php71/bin/php /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cron/rclone_cleanup.php >> /var/log/rcloneCWP_cleanup.log 2>&1' >> /etc/crontab

# 7. Restart crond
systemctl restart crond

# 8. Verify installation
php rcloneCWP/cli/rcloneCWP status
```

### 15.3 Configuration

1. **Log in to CWP Admin** (`https://server:2031`)
2. **Click "Rclone Backup"** in the sidebar
3. **Go to Destinations** → Add your first destination
4. **Test the connection**
5. **Go to Backup Jobs** → Create a new job
6. **Select destination and components**
7. **Run manually to test**
8. **Set up schedule**

### 15.4 Upgrading

```bash
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
git pull origin master
php install.php --upgrade
```

### 15.5 Uninstallation

```bash
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
php uninstall.php

# Remove menu entry
sed -i '/Rclone Backup/d' /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php

# Remove cron entries
sed -i '/rcloneCWP/d' /etc/crontab
systemctl restart crond

# Remove module
rm -rf /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
```

---

## 16. Roadmap & Milestones

### Version 1.0.0 (MVP)
- [ ] Basic module structure
- [ ] Database schema
- [ ] Local, FTP, SFTP destinations
- [ ] Full backup and restore
- [ ] Basic scheduling
- [ ] Dashboard
- [ ] Log viewer

### Version 1.1.0
- [ ] S3 destination
- [ ] Google Drive destination
- [ ] Incremental backups
- [ ] Retention policies
- [ ] Email notifications
- [ ] Hook system

### Version 1.2.0
- [ ] REST API
- [ ] CLI tools
- [ ] API key management
- [ ] Rate limiting
- [ ] Slack notifications

### Version 1.3.0
- [ ] B2 destination
- [ ] Dropbox destination
- [ ] OneDrive destination
- [ ] GCS destination
- [ ] Encryption support
- [ ] Compression options

### Version 1.4.0
- [ ] Azure destination
- [ ] WebDAV destination
- [ ] Telegram notifications
- [ ] Advanced scheduling
- [ ] Cross-server restore

### Version 2.0.0
- [ ] User panel module
- [ ] Per-user backup management
- [ ] Mobile-responsive UI
- [ ] Advanced reporting
- [ ] Multi-server management

---

## Appendix A: rclone Configuration Reference

### Global Options
```ini
[global]
# Performance
transfers = 4
checkers = 8
buffer-size = 32M

# Rate limiting
tpslimit = 10
tpslimit-burst = 20

# Retry
retries = 3
retries-sleep = 1s

# Logging
log-file = /var/log/rclone/rclone.log
log-level = INFO

# Cache
vfs-cache-mode = writes
vfs-cache-max-size = 1G
```

### Google Drive
```ini
[gdrive]
type = drive
client_id = YOUR_CLIENT_ID
client_secret = YOUR_CLIENT_SECRET
token = {"access_token":"...","token_type":"Bearer","refresh_token":"...","expiry":"..."}
root_folder_id = OPTIONAL_ROOT_FOLDER
```

### S3 (AWS)
```ini
[s3]
type = s3
provider = AWS
env_auth = false
access_key_id = YOUR_KEY
secret_access_secret = YOUR_SECRET
region = us-east-1
storage_class = STANDARD
```

### SFTP
```ini
[sftp]
type = sftp
host = example.com
user = username
key_file = /root/.ssh/id_rsa
```

---

## Appendix B: Database Migration Guide

### From CWP backup_manager2
```sql
-- Migrate existing FTP destinations
INSERT INTO rclone_destinations (name, type, config, enabled)
SELECT 
    CONCAT('Migrated: ', backup_name),
    'ftp',
    JSON_OBJECT(
        'host', backup_host,
        'username', backup_user,
        'password', backup_pass,
        'path', backup_path
    ),
    backup_enable
FROM backups;
```

---

## Appendix C: Troubleshooting

### Common Issues

**1. "rclone not found"**
```bash
which rclone
# If not found:
curl https://rclone.org/install.sh | sudo bash
```

**2. "Permission denied"**
```bash
chown -R root:root /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/
chmod 600 /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/config.php
```

**3. "Database connection failed"**
```bash
# Check MySQL is running
systemctl status mysql

# Check credentials
cat /usr/local/cwp/.conf/mysql_db.cnf
```

**4. "Backup stuck at 0%"**
```bash
# Check rclone logs
tail -f /var/log/rcloneCWP_cron.log

# Check lock files
ls -la /tmp/rcloneCWP_*.lock

# Remove stale lock
rm /tmp/rcloneCWP_*.lock
```

**5. "Google Drive authentication failed"**
```bash
# Re-authenticate
rclone config reconnect gdrive:
```

---

## Appendix D: License

```
MIT License

Copyright (c) 2026 rcloneCWP Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this of the software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

**End of Document**
