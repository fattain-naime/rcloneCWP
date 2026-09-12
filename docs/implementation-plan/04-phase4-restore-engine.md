# Phase 4: Restore Engine — Implementation Plan

> **Status**: In Progress  
> **Target**: PHP 7.1+ syntax floor (AlmaLinux 8.10 CWP PHP 7.2.30), MariaDB 10.11+, rclone v1.75.0+  
> **Placement**: Off-htdocs runtime at `/usr/local/cwp/rcloneCWP/`, web entry at `/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php`

---

## 0. Overview & Objectives

Phase 4 implements the **Granular & Full Restore Engine** of rcloneCWP. Operating directly upon the snapshots created in Phase 3, Phase 4 delivers JetBackup5-level recovery capabilities with full multi-component isolation, checksum verification, ownership preservation, and cross-server CWP migration support.

### Key Goals:
1. **Remote Snapshot Discovery (`SnapshotBrowser`)**:
   - Query remote destinations using `rclone lsjson` to enumerate all available backup archives under `rcloneCWP-backups/<job_slug>/`.
   - Read remote `manifest.json` on-demand into memory without downloading the entire snapshot.
   - Present a rich component manifest: available databases, files, DNS zones, SSL certs, mailboxes, and crontabs.

2. **Isolated Component Restorers (`ComponentRestorerInterface`)**:
   - `DatabaseRestorer`: Restores specific databases or all databases from `.sql.gz` using MySQL client with secure temporary credentials configuration (mode 0600, preventing exposure in `ps aux`). Handles database creation if not already present.
   - `FilesRestorer`: Restores `/home/<username>/` files or single directories (like `public_html/`) from `home.tar.gz` or direct sync.
     - **CRITICAL**: Preserves and restores `<username>:<username>` file and directory ownership. Strictly adheres to CLAUDE.md guidelines.
   - `DnsRestorer`: Reinstalls BIND zone files to `/var/named/<domain>.db` and signals named reload.
   - `SslRestorer`: Restores certificates to `/etc/pki/tls/certs/<domain>.cert` and private keys to `/etc/pki/tls/private/<domain>.key` (mode 0600).
   - `MailRestorer`: Restores virtual mailboxes and aliases in MariaDB `postfix` database and unpacks user Maildir spools.
   - `CronRestorer`: Restores user crontabs to `/var/spool/cron/<username>` (root owned, mode 0600).
   - `AccountMetadataRestorer`: Restores CWP account record in `root_cwp.user`, packages, and domain configurations if restoring onto a fresh system (Disaster Recovery & Migration mode).

3. **Integrity & Checksum Verification**:
   - All downloaded archives are validated against the SHA-256 hashes recorded in `manifest.json` before any extraction or database execution occurs.

4. **Safety Defenses**:
   - Concurrency locking to prevent concurrent restore/backup collisions.
   - Pre-restore safety verification.
   - Atomic component staging in isolated temporary folders.

5. **CWP Admin UI Integration**:
   - Activate the "Restore" tab in `views/layout.php`.
   - Implement `views/restore.php`: Destination selector, snapshot browser with date/user filters, component breakdown, item selection modal (choose specific databases, files, or full account), live execution progress modal, and restore activity history.

---

## 1. Restore Architecture & Workflow

```
[Admin UI: Select Destination -> Browse Snapshots -> Select Items]
                                │
                                ▼
                   [1. Acquire Restore Lock]
                                │
                                ▼
           [2. Download Snapshot Components to Temp Staging]
             - In-memory rclone env (RCLONE_CONFIG_*)
             - Download manifest.json + requested component files
                                │
                                ▼
                [3. Validate SHA-256 Checksums]
             - Match staged file hashes with manifest.json
                                │
                                ▼
              [4. Execute Component Restorers]
        ┌───────────────────────┼────────────────────────┐
        ▼                       ▼                        ▼
 [DatabaseRestorer]       [FilesRestorer]          [Ssl & Dns]
 - MariaDB restore via   - Extract to /home/user  - /var/named/*.db
   temp cnf (mode 0600)  - chown user:user        - /etc/pki/tls (0600)
                                │
                                ▼
                   [5. Clean Up Temp Staging]
                                │
                                ▼
                   [6. Release Restore Lock]
                                │
                                ▼
           [7. Log Event to rclone_logs & Return Result]
```

---

## 2. Directory & Class Structure

```
lib/Restore/
├── ComponentRestorerInterface.php      # Contract for all component restorers
├── SnapshotBrowser.php                 # Discovers remote snapshots & inspects manifests
├── RestoreEngine.php                   # Core orchestrator, locks, verification, execution
└── Restorers/
    ├── DatabaseRestorer.php            # MySQL/MariaDB database restoration
    ├── FilesRestorer.php               # User home files & public_html with chown enforcement
    ├── DnsRestorer.php                 # BIND zone files
    ├── SslRestorer.php                 # TLS certificates and private keys
    ├── MailRestorer.php                # Postfix mailboxes, aliases, Maildir
    ├── CronRestorer.php                # User crontabs
    └── AccountMetadataRestorer.php     # CWP account configuration and packages
```

---

## 3. Component Restorer Contract

```php
namespace CWP\RcloneCWP\Restore;

interface ComponentRestorerInterface
{
    public function getName(): string;
    public function getLabel(): string;
    public function restore(array $account, string $stagedComponentDir, array $options = []): array;
}
```

---

## 4. UI & Endpoints Specifications

### AJAX Endpoints (`rcloneCWP.php`):
- `action=list_snapshots&destination_id={id}`: Lists available snapshots across jobs.
- `action=inspect_snapshot&destination_id={id}&path={path}`: Fetches and parses `manifest.json`.
- `action=run_restore`: Triggers restore with selected components, username, and options.

### UI Components (`views/restore.php`):
- Destination picker dropdown.
- Snapshots table with User, Job, Timestamp, Components, and Action ("Restore").
- Restore Modal with granular checkboxes:
  - Restore All vs Granular selection
  - Database selection checkboxes (multi-select)
  - Files restore option with path selector
  - DNS, SSL, Email, Cron toggles
- Real-time execution log and status modal.

---

## 5. Verification & Testing

- Unit & integration tests in `tests/restore_engine_test.php`.
- Test remote snapshot listing on local and cloud providers.
- Test manifest parsing and SHA-256 verification.
- Test isolated database restore and verify table contents.
- Test file extraction and verify `<user>:<user>` file ownership preservation.
