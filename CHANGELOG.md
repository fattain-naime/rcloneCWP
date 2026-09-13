# Changelog

All notable changes to rcloneCWP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-13

### Added

#### Core Engine
- Full backup engine with full, incremental, and selective backup modes
- Restore engine supporting full, partial, and cross-server restores
- rclone wrapper (`lib/Rclone.php`) for transport-layer abstraction

#### Destinations (12 backends)
- Local, S3, Google Cloud Storage, Azure Blob Storage
- Backblaze B2, OneDrive, Dropbox, WebDAV
- SFTP, Swift (OpenStack), Tencent Cloud

#### Scheduling & Retention
- Cron-based scheduling with timezone support
- Automated retention policy enforcement

#### Hooks & Notifications
- Pre/post hooks for backup and restore events (shell, PHP, Python, URL)
- Notification channels: email, Telegram, webhook

#### API & CLI
- RESTful API with key-based authentication (`api/v1/`)
- CLI toolsuite for backup, restore, and management operations

#### Dashboard UI
- CWP admin panel integration with tabbed layout
- Overview dashboard, backup job management, restore browser
- Destination, schedule, hook, and notification management views
- Settings tab with crontab management and module info

#### Test Suite
- PHPUnit test suite covering core libraries
- Unit tests for Validator, CSRF, Encryption, CronParser
- Integration tests for API, CLI, Database, BackupJobManager

### Security
- AES-256-GCM encryption for credentials and sensitive config
- CSRF token protection on all forms
- Input validation and whitelisting at API/CLI boundaries
- SSRF defense on URL-type hooks
- Prepared statements for all SQL queries
- Output encoding via `htmlspecialchars()` to prevent XSS
- `escapeshellarg()` on all command execution
- Secure file permissions (0600 for configs, 0700 for directories)

## [Unreleased]

- [ ] Persistent file locking for concurrent backups
- [ ] Cross-server restore optimization
- [ ] Backup encryption at rest (rclone `crypt` backend)
- [ ] API rate limiting
- [ ] Webhook signature verification
