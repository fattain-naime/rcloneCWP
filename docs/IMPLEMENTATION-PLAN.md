# rcloneCWP — Implementation Plan
**Version**: 1.0.0  
**Last Updated**: 2026-09-06

---

## Table of Contents

1. [Phase 1: Foundation (Days 1-5)](#phase-1-foundation-days-1-5)
2. [Phase 2: Destinations (Days 6-10)](#phase-2-destinations-days-6-10)
3. [Phase 3: Backup Engine (Days 11-16)](#phase-3-backup-engine-days-11-16)
4. [Phase 4: Restore Engine (Days 17-22)](#phase-4-restore-engine-days-17-22)
5. [Phase 5: Scheduling (Days 23-25)](#phase-5-scheduling-days-23-25)
6. [Phase 6: Hooks & Notifications (Days 26-29)](#phase-6-hooks--notifications-days-26-29)
7. [Phase 7: API & CLI (Days 30-34)](#phase-7-api--cli-days-30-34)
8. [Phase 8: Dashboard & Polish (Days 35-38)](#phase-8-dashboard--polish-days-35-38)
9. [Phase 9: Testing & QA (Days 39-42)](#phase-9-testing--qa-days-39-42)
10. [Phase 10: Documentation & Release (Days 43-45)](#phase-10-documentation--release-days-43-45)

---

## Phase 1: Foundation (Days 1-5)

### Day 1: Project Setup & Configuration

**Tasks:**
- [x] Create module directory structure
- [x] Create main entry point (`rcloneCWP.php`)
- [x] Create configuration file (`config.php`)
- [x] Create installation script (`install.php`)
- [x] Create uninstallation script (`uninstall.php`)
- [x] Add menu entry (`3rdparty.php`)

**Deliverables:**
```
rcloneCWP/
├── rcloneCWP.php
├── config.php
├── install.php
└── uninstall.php
```

**Verification:**
```bash
# Module should appear in CWP sidebar
curl -s "https://localhost:2030/index.php?module=rcloneCWP" | head -20
```

### Day 2: Database Layer

**Tasks:**
- [ ] Create `Database.php` class
- [ ] Implement PDO wrapper with prepared statements
- [ ] Implement CRUD helpers
- [ ] Create schema installation SQL
- [ ] Test database connectivity

**Deliverables:**
```
lib/
└── Database.php
sql/
└── install.sql
```

**Verification:**
```bash
php -r "
require_once 'lib/Database.php';
\$db = Database::getInstance();
echo 'Database connection successful';
"
```

### Day 3: Logger & Encryption

**Tasks:**
- [ ] Create `Logger.php` class
- [ ] Create `Encryption.php` class
- [ ] Implement AES-256-GCM encryption/decryption
- [ ] Implement encrypted credential storage

**Deliverables:**
```
lib/
├── Logger.php
└── Encryption.php
```

**Verification:**
```bash
php -r "
require_once 'lib/Encryption.php';
\$enc = new Encryption();
\$encrypted = \$enc->encrypt('test-password');
echo \$enc->decrypt(\$encrypted) === 'test-password' ? 'OK' : 'FAIL';
"
```

### Day 4: Rclone Wrapper & Validator

**Tasks:**
- [ ] Create `Rclone.php` wrapper class
- [ ] Implement command whitelisting
- [ ] Implement argument sanitization
- [ ] Create `Validator.php` class
- [ ] Create `CSRF.php` class

**Deliverables:**
```
lib/
├── Rclone.php
├── Validator.php
└── CSRF.php
```

**Verification:**
```bash
php -r "
require_once 'lib/Rclone.php';
\$rclone = new Rclone();
echo \$rclone->execute('lsd', '/tmp')['success'] ? 'OK' : 'FAIL';
"
```

### Day 5: Destination Factory

**Tasks:**
- [ ] Create `Destination.php` abstract base class
- [ ] Create `DestinationFactory.php`
- [ ] Create destination classes:
  - [ ] `Local.php`
  - [ ] `FTP.php`
  - [ ] `SFTP.php`
  - [ ] `S3.php`
  - [ ] `GoogleDrive.php`

**Deliverables:**
```
lib/
├── Destination.php
├── DestinationFactory.php
destinations/
├── Local.php
├── FTP.php
├── SFTP.php
├── S3.php
└── GoogleDrive.php
```

**Verification:**
```bash
php -r "
require_once 'lib/DestinationFactory.php';
\$dest = DestinationFactory::create(['type' => 'local', ...]);
echo \$dest->testConnection(...) ? 'OK' : 'FAIL';
"
```

---

## Phase 2: Destinations (Days 6-10)

### Day 6-7: Core Destinations

**Tasks:**
- [ ] Implement `Local` destination fully
- [ ] Implement `FTP` destination fully
- [ ] Implement `SFTP` destination fully
- [ ] Test each destination type

### Day 8-9: Cloud Destinations

**Tasks:**
- [ ] Implement `S3` destination
- [ ] Implement `GoogleDrive` destination
- [ ] Implement OAuth flow for Google Drive
- [ ] Test cloud destinations

### Day 10: Additional Destinations

**Tasks:**
- [ ] Implement `B2` destination
- [ ] Implement `Dropbox` destination
- [ ] Implement `OneDrive` destination
- [ ] Implement `GCS` destination

**Deliverables:**
```
destinations/
├── Local.php
├── FTP.php
├── SFTP.php
├── S3.php
├── GoogleDrive.php
├── GCS.php
├── B2.php
├── Dropbox.php
└── OneDrive.php
```

---

## Phase 3: Backup Engine (Days 11-16)

### Day 11-12: Core Backup Engine

**Tasks:**
- [ ] Create `BackupEngine.php` class
- [ ] Implement file collection
- [ ] Implement database dump
- [ ] Implement compression
- [ ] Implement encryption

### Day 13-14: Component Collection

**Tasks:**
- [ ] Implement DNS zone export
- [ ] Implement email account export
- [ ] Implement SSL certificate export
- [ ] Implement cron job export
- [ ] Implement FTP account export

### Day 15: Transfer & Verification

**Tasks:**
- [ ] Implement rclone transfer
- [ ] Implement checksum verification
- [ ] Implement progress tracking
- [ ] Implement error handling

### Day 16: Incremental Backups

**Tasks:**
- [ ] Implement incremental detection
- [ ] Implement file modification tracking
- [ ] Implement binary log support for databases
- [ ] Test incremental vs full backups

**Deliverables:**
```
lib/
├── BackupEngine.php
├── RestoreEngine.php (basic stub)
└── Progress.php
```

---

## Phase 4: Restore Engine (Days 17-22)

### Day 17-18: Core Restore Engine

**Tasks:**
- [ ] Create `RestoreEngine.php` class
- [ ] Implement file restore
- [ ] Implement database import
- [ ] Implement decompression/decryption

### Day 19-20: Component Restore

**Tasks:**
- [ ] Implement DNS zone import
- [ ] Implement email account import
- [ ] Implement SSL certificate import
- [ ] Implement cron job import
- [ ] Implement FTP account import

### Day 21: Safety Features

**Tasks:**
- [ ] Implement safety backup before restore
- [ ] Implement restore verification
- [ ] Implement rollback on failure

### Day 22: Cross-Server Restore

**Tasks:**
- [ ] Implement different path handling
- [ ] Implement different user handling
- [ ] Test migration scenarios

---

## Phase 5: Scheduling (Days 23-25)

### Day 23: Schedule Class

**Tasks:**
- [ ] Create `Schedule.php` class
- [ ] Implement next-run calculation
- [ ] Implement due job detection
- [ ] Implement retention policies

### Day 24: Cron Scripts

**Tasks:**
- [ ] Create `cron/rcloneCWP.php`
- [ ] Create `cron/rclone_cleanup.php`
- [ ] Create `cron/rclone_restore.php`
- [ ] Test cron execution

### Day 25: Cron Integration

**Tasks:**
- [ ] Add system crontab entries
- [ ] Test scheduled execution
- [ ] Verify retention cleanup

**Deliverables:**
```
cron/
├── rcloneCWP.php
├── rclone_cleanup.php
└── rclone_restore.php
```

---

## Phase 6: Hooks & Notifications (Days 26-29)

### Day 26: Hook System

**Tasks:**
- [ ] Create `Hook.php` class
- [ ] Implement shell hook execution
- [ ] Implement PHP hook execution
- [ ] Implement Python hook execution
- [ ] Implement URL hook execution

### Day 27: Notification System

**Tasks:**
- [ ] Create `Notification.php` class
- [ ] Implement email notifications
- [ ] Implement SMTP configuration
- [ ] Test email delivery

### Day 28: Slack & Telegram

**Tasks:**
- [ ] Implement Slack webhook notifications
- [ ] Implement Telegram bot notifications
- [ ] Implement custom webhook notifications

### Day 29: Notification Templates

**Tasks:**
- [ ] Create email templates
- [ ] Create Slack templates
- [ ] Create Telegram templates
- [ ] Test all notification types

**Deliverables:**
```
lib/
├── Hook.php
├── Notification.php
templates/
├── email/
│   ├── backup_success.php
│   ├── backup_failure.php
│   └── restore_complete.php
└── slack/
    └── notification.php
```

---

## Phase 7: API & CLI (Days 30-34)

### Day 30-31: REST API

**Tasks:**
- [ ] Create `API.php` class
- [ ] Create API router (`api/index.php`)
- [ ] Implement destination endpoints
- [ ] Implement job endpoints
- [ ] Implement backup endpoints

### Day 32: API Security

**Tasks:**
- [ ] Implement API key authentication
- [ ] Implement rate limiting
- [ ] Implement CORS handling
- [ ] Implement permission checking

### Day 33: CLI Tools

**Tasks:**
- [ ] Create `CLI.php` class
- [ ] Create `cli/rcloneCWP`
- [ ] Create `cli/rclone-restore`
- [ ] Create `cli/rclone-destination`
- [ ] Create `cli/rclone-schedule`

### Day 34: API Testing

**Tasks:**
- [ ] Test all API endpoints
- [ ] Test CLI commands
- [ ] Write API documentation
- [ ] Write CLI help text

**Deliverables:**
```
api/
├── index.php
├── auth.php
└── v1/
    ├── destinations.php
    ├── jobs.php
    └── backups.php
cli/
├── rcloneCWP
├── rclone-restore
├── rclone-destination
└── rclone-schedule
```

---

## Phase 8: Dashboard & Polish (Days 35-38)

### Day 35: Dashboard UI

**Tasks:**
- [ ] Create `views/dashboard.php`
- [ ] Show system stats
- [ ] Show recent backups
- [ ] Show destination status
- [ ] Show schedule overview

### Day 36: Backup Job UI

**Tasks:**
- [ ] Create `views/backup_jobs.php`
- [ ] Create `views/backup_job_form.php`
- [ ] Implement AJAX form submission
- [ ] Implement form validation

### Day 37: Destination & Restore UI

**Tasks:**
- [ ] Create `views/destinations.php`
- [ ] Create `views/destination_form.php`
- [ ] Create `views/destination_test.php`
- [ ] Create `views/restore.php`

### Day 38: Hooks, Logs & Settings UI

**Tasks:**
- [ ] Create `views/hooks.php`
- [ ] Create `views/hook_form.php`
- [ ] Create `views/logs.php`
- [ ] Create `views/settings.php`

**Deliverables:**
```
views/
├── dashboard.php
├── backup_jobs.php
├── backup_job_form.php
├── destinations.php
├── destination_form.php
├── destination_test.php
├── restore.php
├── hooks.php
├── hook_form.php
├── logs.php
└── settings.php
```

---

## Phase 9: Testing & QA (Days 39-42)

### Day 39: Unit Tests

**Tasks:**
- [ ] Write `DatabaseTest.php`
- [ ] Write `RcloneTest.php`
- [ ] Write `EncryptionTest.php`
- [ ] Write `ValidatorTest.php`
- [ ] Write `CSRFTest.php`

### Day 40: Integration Tests

**Tasks:**
- [ ] Write `BackupFlowTest.php`
- [ ] Write `RestoreFlowTest.php`
- [ ] Test full backup to local
- [ ] Test full restore from local
- [ ] Test FTP destination

### Day 41: Security Testing

**Tasks:**
- [ ] Test SQL injection prevention
- [ ] Test XSS prevention
- [ ] Test CSRF protection
- [ ] Test command injection prevention
- [ ] Test path traversal prevention

### Day 42: Performance Testing

**Tasks:**
- [ ] Test backup speed
- [ ] Test restore speed
- [ ] Test concurrent backups
- [ ] Test large file handling
- [ ] Test memory usage

**Deliverables:**
```
tests/
├── phpunit.xml
├── bootstrap.php
├── unit/
│   ├── DatabaseTest.php
│   ├── RcloneTest.php
│   ├── EncryptionTest.php
│   ├── ValidatorTest.php
│   └── CSRFTest.php
└── integration/
    ├── BackupFlowTest.php
    └── RestoreFlowTest.php
```

---

## Phase 10: Documentation & Release (Days 43-45)

### Day 43: Documentation

**Tasks:**
- [ ] Write `README.md`
- [ ] Write `CHANGELOG.md`
- [ ] Write `CONTRIBUTING.md`
- [ ] Write inline code comments
- [ ] Generate PHPDoc

### Day 44: Packaging

**Tasks:**
- [ ] Create installation script
- [ ] Create update script
- [ ] Create uninstall script
- [ ] Create distribution package

### Day 45: Release

**Tasks:**
- [ ] Push to GitHub
- [ ] Create release tag
- [ ] Upload distribution package
- [ ] Publish announcement
- [ ] Share on CWP forums

**Final Deliverables:**
```
rcloneCWP/
├── README.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── DEVELOPER_GUIDE.md
├── LICENSE
├── RESEARCH-AND-BLUEPRINT.md
├── config.php
├── install.php
├── uninstall.php
├── assets/
├── cli/
├── cron/
├── destinations/
├── language/
├── lib/
├── sql/
├── templates/
├── tests/
└── views/
```

---

## Milestone Checkpoints

| Milestone | Day | Deliverable | Status |
|-----------|-----|-------------|--------|
| M1 | 5 | Module loads in CWP | ⬜ |
| M2 | 10 | All destinations working | ⬜ |
| M3 | 16 | Backup engine working | ⬜ |
| M4 | 22 | Restore engine working | ⬜ |
| M5 | 25 | Scheduling working | ⬜ |
| M6 | 29 | Hooks & notifications working | ⬜ |
| M7 | 34 | API & CLI working | ⬜ |
| M8 | 38 | All UI complete | ⬜ |
| M9 | 42 | Testing complete | ⬜ |
| M10 | 45 | v1.0.0 release | ⬜ |

---

## Resource Allocation

| Role | Responsibility | Hours/Week |
|------|---------------|------------|
| Lead Architect | Design, code review | 10 |
| PHP Developer (2) | Core development | 40 |
| Frontend Developer | UI/HTML/CSS/JS | 20 |
| QA Tester | Testing, bug reports | 15 |
| Technical Writer | Documentation | 10 |

---

## Risk Management

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| rclone API changes | Low | High | Pin rclone version, abstraction layer |
| CWP updates break module | Medium | Medium | Test with each CWP update |
| Performance issues | Medium | High | Profiling, optimization phase |
| Security vulnerabilities | Low | Critical | Security audit, responsible disclosure |
| Scope creep | Medium | Medium | Strict milestone definitions |

---

## Success Criteria

- [ ] All JetBackup5 features implemented
- [ ] 100% test coverage for critical paths
- [ ] Zero security vulnerabilities
- [ ] Documentation complete
- [ ] Installation under 5 minutes
- [ ] Successful backup/restore on all supported destinations

---

**End of Implementation Plan**
