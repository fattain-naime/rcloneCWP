# CLAUDE.md — rcloneCWP Workspace

## Project Overview

**rcloneCWP** is a free, open-source native CWP (CentOS Web Panel) module for enterprise-grade backup/restore using rclone as the transport layer. It provides JetBackup5-level features at zero cost.

## Module Location

- **GitHub**: `github.com/fattain_naime/rcloneclwp` (MIT, user GitHub: fattain_naive)
- **CWP Module Path**: `/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/`
- **Workspace**: `/root/rcloneCWP/`

## Current Development Phase

Research complete. Entering **Phase 1: Foundation (Days 1-5)**.

See `docs/IMPLEMENTATION-PLAN.md` for the full roadmap.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 7.1+ (CWP PHP binary) |
| Database | MySQL/MariaDB (`root_cwp` database) |
| Cloud Transport | rclone v1.75.0+ |
| Frontend | HTML/CSS/JS (CWP admin theme) |
| API | RESTful PHP |
| CLI | PHP CLI scripts |

## Key Files

| File | Purpose |
|------|---------|
| `docs/RESEARCH-AND-BLUEPRINT.md` | Complete architecture, DB schema, security model |
| `docs/DEVELOPER-GUIDE.md` | Coding standards, API reference, patterns |
| `docs/IMPLEMENTATION-PLAN.md` | 45-day phased development timeline |
| `sql/install.sql` | Database schema (8 tables) |

## Database Tables

Tables are prefixed with `rclone_` to avoid CWP conflicts:

1. `rclone_destinations` — backup destination configs
2. `rclone_jobs` — backup job definitions
3. `rclone_schedules` — schedule definitions
4. `rclone_backups` — backup history
5. `rclone_hooks` — hook definitions
6. `rclone_logs` — log entries
7. `rclone_api_keys` — API authentication
8. `rclone_notifications` — notification configs

## CWP Integration Points

- **Module Registration**: Add menu entry to `/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php`
- **Access URL**: `https://server:2030/index.php?module=rcloneCWP`
- **Database**: Uses CWP's existing `root_cwp` MySQL database
- **Cron**: System crontab entries for scheduled backups
- **rclone Config**: `/etc/rclone/rclone.conf` (secured with 600 permissions)

## Module Directory Structure (Planned)

```
rcloneCWP/
├── rcloneCWP.php          # Main entry point
├── config.php             # Configuration constants
├── install.php            # Database schema + setup
├── uninstall.php          # Cleanup
├── lib/                   # Core PHP classes
├── destinations/          # Backend implementations (12 types)
├── views/                 # UI templates
├── cron/                  # Cron scripts
├── api/                   # REST API
├── cli/                   # CLI tools
├── language/              # Translations (en, bn)
├── sql/                   # Database schemas
├── assets/                # CSS/JS/images
├── templates/             # Email templates
└── tests/                 # PHPUnit tests
```

## Security Requirements

1. **Input Validation**: All user input sanitized via `Validator.php`
2. **SQL Injection**: Prepared statements for all queries
3. **XSS**: Output encoding via `htmlspecialchars()`
4. **CSRF**: Tokens on all forms
5. **Command Injection**: `escapeshellarg()` for all exec calls
6. **Credential Storage**: AES-256-GCM encryption for sensitive config
7. **File Permissions**: 0600 for config files, 0700 for directories

## Coding Standards

- **PHP**: PSR-12 with project-specific additions
- **Naming**: PascalCase classes, camelCase methods, snake_case DB columns
- **Namespaces**: `CWP\RcloneCWP`
- **Documentation**: PHPDoc for all classes and methods

## rclone Configuration

- **Binary**: `/usr/bin/rclone` or `/usr/local/bin/rclone`
- **Config File**: `/etc/rclone/rclone.conf`
- **Cache**: `/var/cache/rclone/`
- **Logs**: `/var/log/rclone/`
- **Permissions**: 600 (root owned)

## Development Workflow

1. Phase 1: Foundation (Database, Logger, Encryption, Rclone wrapper)
2. Phase 2: Destinations (12 backend types)
3. Phase 3: Backup Engine (full/incremental/selective)
4. Phase 4: Restore Engine (full/partial/cross-server)
5. Phase 5: Scheduling (cron, retention policies)
6. Phase 6: Hooks & Notifications (shell/PHP/Python)
7. Phase 7: API & CLI (RESTful API, CLI tools)
8. Phase 8: Dashboard & Polish (UI, progress tracking)
9. Phase 9: Testing & QA (unit + integration tests)
10. Phase 10: Documentation & Release (v1.0.0)

## Current Research Documents

- `docs/RESEARCH-AND-BLUEPRINT.md` — 68 KB complete blueprint
- `docs/DEVELOPER-GUIDE.md` — 78 KB developer guide
- `docs/IMPLEMENTATION-PLAN.md` — 14 KB phased timeline
- `sql/install.sql` — 10 KB database schema

## Important Notes

- CWP modules run as **root** — full system access
- ionCube encoding optional (plain PHP works)
- Module accessible at `index.php?module=rcloneCWP`
- Database credentials from `/usr/local/cwp/.conf/mysql_db.cnf`
- All backup temp files in `/backup/.backup_temp/rclone/`
