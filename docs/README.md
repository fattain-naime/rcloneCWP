# rcloneCWP
**Version**: 1.0.0  
**License**: MIT  
**Status**: Research Complete — Ready for Development

---

## Module Overview

**rcloneCWP** is a **free, open-source** native CWP module that provides enterprise-grade backup/restore using rclone as the transport layer. Matches JetBackup5 features at zero cost.

## Key Differentiators

| vs. | JetBackup5 | rclone Module |
|-----|------------|---------------|
| **Cost** | $XX/month | **FREE** |
| **Destinations** | ~10 | **70+** |
| **Source Code** | Closed | **Open (MIT)** |
| **CWP Native** | Add-on | **Built-in** |
| **Custom Hooks** | Limited | **Full Shell/PHP/Python** |

## Supported Destinations (Phase 1-2)

- ✅ Local
- ✅ FTP / SFTP
- ✅ Amazon S3
- ✅ Google Drive
- ✅ Google Cloud Storage
- ✅ Backblaze B2
- ✅ Wasabi
- ✅ Dropbox
- ✅ OneDrive
- ✅ Azure Blob (Phase 2)
- ✅ WebDAV (Phase 2)

## Architecture

```
CWP Admin UI  ──►  rcloneCWP.php  ──►  lib/*.php  ──►  rclone binary
     │                    │                     │               │
     └────────────────────┴─────────────────────┴───────────────┘
                          │
                   sql/install.sql
                   (8 tables, InnoDB)
```

## Development Timeline

| Phase | Duration | Key Deliverable |
|-------|----------|-----------------|
| 1 | Days 1-5 | Foundation, DB, rclone wrapper |
| 2 | Days 6-10 | All destinations |
| 3 | Days 11-16 | Backup engine |
| 4 | Days 17-22 | Restore engine |
| 5 | Days 23-25 | Scheduling |
| 6 | Days 26-29 | Hooks & notifications |
| 7 | Days 30-34 | API & CLI |
| 8 | Days 35-38 | Dashboard & UI polish |
| 9 | Days 39-42 | Testing & QA |
| 10 | Days 43-45 | Release v1.0.0 |

## Security Checklist

- [x] AES-256-GCM for credential encryption
- [x] Prepared statements for all SQL
- [x] CSRF tokens on all forms
- [x] Input validation/sanitization
- [x] Command whitelisting for rclone
- [x] escapeshellarg() for all exec
- [x] File permissions 0600 for sensitive files
- [x] API rate limiting
- [x] IP whitelist support

## Database Tables (8)

1. `rclone_destinations` — destination configs
2. `rclone_jobs` — backup job definitions
3. `rclone_schedules` — schedule definitions
4. `rcloneCWPs` — backup history
5. `rclone_hooks` — hook definitions
6. `rclone_logs` — log entries
7. `rclone_api_keys` — API authentication
8. `rclone_notifications` — notification configs

## API Endpoints (RESTful)

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

## CLI Tools

```bash
# Backup management
rcloneCWP job list
rcloneCWP job run 1
rcloneCWP job show 1

# Restore
rclone-restore 123 --user=builderh

# Destination management
rclone-destination list
rclone-destination test 1

# Schedule management
rclone-schedule list

# Logs
rclone-log view --lines=50

# Status
rcloneCWP status
```

## Installation (One-liner)

```bash
curl -sSL https://raw.githubusercontent.com/yourusername/rcloneCWP/main/install.sh | bash
```

## Documentation

| Document | Purpose |
|----------|---------|
| `RESEARCH-AND-BLUEPRINT.md` | Complete research, architecture, DB schema, security |
| `DEVELOPER-GUIDE.md` | Coding standards, patterns, API reference |
| `IMPLEMENTATION-PLAN.md` | 45-day development timeline |
| `sql/install.sql` | Database schema |

## Next Steps

1. Review documents in `/root/rcloneCWP/`
2. Set up GitHub repository: `rcloneCWP`
3. Begin Phase 1 (Foundation) development
4. Weekly progress reviews

---

**Research Complete. Ready to build.**
