# Phase 5: Scheduling & Retention Engine — Implementation Plan

> **Status**: In Progress  
> **Target**: PHP 7.1+ syntax floor (AlmaLinux 8.10 CWP PHP 7.2.30), MariaDB 10.11+, rclone v1.75.0+  
> **Placement**: Off-htdocs runtime at `/usr/local/cwp/rcloneCWP/`, web entry at `/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php`

---

## 0. Overview & Objectives

Phase 5 delivers the automated **Scheduling & Retention Engine** for rcloneCWP. Building upon the Backup Engine (Phase 3) and Restore Engine (Phase 4), this phase provides enterprise-grade, set-and-forget automated backup schedules and intelligent retention lifecycle management (JetBackup5 parity).

### Key Goals:
1. **Cron Parsing & Scheduling Math (`CronParser`)**:
   - Parse and evaluate standard 5-field cron expressions: `minute hour day-of-month month day-of-week` (`*`, `*/step`, `range`, `list`).
   - Calculate precise `next_run` datetime factoring in configured timezones (UTC default, user-selected timezones).
   - Compute whether a schedule is currently due or overdue.
   - Built-in presets: Hourly (`0 * * * *`), Daily at 2AM (`0 2 * * *`), Twice Daily (`0 2,14 * * *`), Weekly (`0 2 * * 0`), Monthly (`0 2 1 * *`), and Custom.

2. **Schedule Lifecycle Management (`ScheduleManager` & `BackupJobManager`)**:
   - Manage schedules in table `rclone_schedules` tied to jobs (`rclone_jobs`).
   - Allow multiple schedules per backup job or single primary schedule.
   - Support active/inactive toggle, timezone override, and next/last run tracking.
   - Detect due schedules across all active jobs.

3. **Intelligent Retention Policies (`RetentionManager`)**:
   - **Time-based**: Prune backups older than `retention_days` (from `rclone_jobs.retention_days`).
   - **Count-based**: Keep the most recent `N` snapshots, prune older ones.
   - **Grandfather-Father-Son (GFS)** rotation:
     - Daily backups: keep for 7 days
     - Weekly backups: keep for 4 weeks
     - Monthly backups: keep for 12 months
   - **Dual cleanup**: Safely remove database records from `rclone_backups` AND physically purge the remote snapshots on cloud storage via `rclone purge <remote>:<bucket>/rcloneCWP-backups/<job>/<snapshot>`.

4. **CLI Cron Runners (`cron/rcloneCWP.php`, `cron/rclone_cleanup.php`)**:
   - `cron/rcloneCWP.php`: Runs every 5 minutes (or user schedule). Evaluates due jobs, executes backup engine with flock-based concurrency locking.
   - `cron/rclone_cleanup.php`: Runs daily (e.g. 2:00 AM). Enforces retention policies, purging expired cloud snapshots and database records.
   - CLI execution guards, detailed logging to `RCLONE_LOG_DIR`, and error tracking.

5. **Safe System Crontab Integration (`CrontabService`)**:
   - Create and manage `/etc/cron.d/rclonecwp` without modifying CWP core files or `/etc/crontab`.
   - Provide status check, install, update, and uninstall methods.

6. **CWP Admin UI Integration (`views/schedules.php`)**:
   - Activate the "Schedules" tab in `views/layout.php`.
   - Full schedule table with Next Run countdown, Last Run status badge, Active toggle, and Run Now button.
   - Schedule Builder Modal with preset selector, cron expression helper, timezone picker, and interactive Next 5 Runs preview.
   - Retention policy status and manual "Run Retention Pruning" action.

---

## 1. Architecture & Component Blueprint

```
rcloneCWP/
├── lib/
│   └── Scheduling/
│       ├── CronParser.php          # 5-field cron parsing, next_run calculation, timezone math
│       ├── ScheduleManager.php     # CRUD on rclone_schedules, due detection, execution logging
│       ├── RetentionManager.php    # Time/count/GFS retention, rclone remote snapshot purging
│       └── CrontabService.php      # /etc/cron.d/rclonecwp management
├── cron/
│   ├── rcloneCWP.php               # Main cron runner (scheduled backups)
│   └── rclone_cleanup.php          # Daily retention cleanup runner
├── views/
│   └── schedules.php               # CWP Admin UI tab for schedule and retention management
└── tests/
    └── Scheduling/
        ├── CronParserTest.php      # Cron calculation tests
        └── RetentionManagerTest.php # Retention policy & pruning tests
```

---

## 2. Verification Plan
- Unit tests: 100% pass on PHP 7.2.30 CLI.
- Verify crontab syntax and dry-run execution.
- End-to-end test: Add schedule, trigger runner, verify `last_run` and `next_run` update, test retention pruning.
