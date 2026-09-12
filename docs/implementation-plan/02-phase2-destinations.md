# Phase 2: Destinations Engine — Implementation Plan

> **Status**: In Progress  
> **Target**: PHP 7.1+ syntax floor, CWP admin panel integration, rclone v1.75.0+, MariaDB 10.11+  
> **Placement**: Off-htdocs runtime at `/usr/local/cwp/rcloneCWP/`, web entry at `/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php`

---

## 0. Overview & Objectives

Phase 2 implements the **Destinations Engine** of rcloneCWP. It enables CWP system administrators to configure, validate, encrypt, test, and manage backup storage destinations across 12 distinct storage provider types.

### Key Goals:
1. **12 Storage Backend Providers**: Local, S3, SFTP, FTP, WebDAV, B2, Google Cloud Storage (`gs`), Azure Blob (`azure`/`azblob`), Dropbox, OneDrive, Swift, and Tencent COS.
2. **Zero Plaintext Credentials on Disk**:
   - Credentials encrypted at rest with AES-256-GCM in MariaDB `rclone_destinations.config` using `Encryption.php`.
   - Dynamic rclone execution: credentials decrypted in memory and injected via `RCLONE_CONFIG_<REMOTE>_<KEY>` process environment variables in `proc_open`. No `rclone.conf` credential storage on disk; no secrets visible in `ps aux`.
3. **Robust Connection Testing**: Real-time validation verifying credential validity, network connectivity, and path/bucket accessibility before jobs are scheduled.
4. **CWP Admin Module Integration**: Modern, responsive tabbed UI inside `rcloneCWP.php` allowing admins to list, create, edit, test, enable/disable, and delete destinations with CSRF protection and sanitized inputs.

---

## 1. Supported Storage Providers

| Key | Provider Name | rclone Type | Required Fields | Sensitive Fields (Encrypted) |
|-----|---------------|-------------|-----------------|-----------------------------|
| `local` | Local Directory / Mount | `local` | `path` | *(none)* |
| `s3` | Amazon S3 & Compatible (Wasabi, MinIO, R2, DO) | `s3` | `provider`, `access_key_id`, `secret_access_key`, `region`, `bucket` | `secret_access_key` |
| `sftp` | SFTP (SSH File Transfer) | `sftp` | `host`, `user`, `port`, `path` | `pass`, `key_pem` |
| `ftp` | FTP / FTPS | `ftp` | `host`, `user`, `port`, `path` | `pass` |
| `webdav` | WebDAV (Nextcloud, ownCloud) | `webdav` | `url`, `vendor`, `user`, `pass`, `path` | `pass` |
| `b2` | Backblaze B2 | `b2` | `account`, `key`, `bucket` | `key` |
| `gs` | Google Cloud Storage | `google cloud storage` | `bucket`, `service_account_credentials` | `service_account_credentials` |
| `azure` / `azblob` | Azure Blob Storage | `azureblob` | `account`, `key`, `container` | `key` |
| `dropbox` | Dropbox | `dropbox` | `token` | `token` |
| `onedrive` | Microsoft OneDrive | `onedrive` | `token`, `drive_id`, `drive_type` | `token` |
| `swift` | OpenStack Swift | `swift` | `user`, `key`, `auth`, `container` | `key` |
| `tencent` | Tencent Cloud COS | `cos` | `app_id`, `secret_id`, `secret_key`, `region`, `bucket` | `secret_key` |

---

## 2. Secure Credential Architecture

### At-Rest Storage
* Stored in MariaDB table `rclone_destinations`:
  ```sql
  id INT AUTO_INCREMENT PRIMARY KEY
  name VARCHAR(255) NOT NULL
  type ENUM('local', 's3', 'gs', 'azure', 'b2', 'onedrive', 'dropbox', 'webdav', 'sftp', 'azblob', 'swift', 'tencent') NOT NULL
  config TEXT NOT NULL -- JSON object
  enabled TINYINT(1) DEFAULT 1
  ```
* Before `INSERT` or `UPDATE`, `AbstractDestination::encryptConfig()` scans for designated sensitive keys and encrypts them using `Encryption::encrypt()`.
* Non-sensitive fields (bucket, region, host, port, path) remain readable JSON.

### In-Flight Execution Security
* Rclone natively supports dynamic remote configuration via environment variables:
  `RCLONE_CONFIG_<REMOTE>_<PARAM>=<value>`
* `DestinationManager::getRcloneEnv($destId)` produces an associative array of environment variables with decrypted credentials.
* `Rclone::execute()` is extended with an optional `$env` array passed to `proc_open($cmdline, $descriptors, $pipes, null, $env)`.
* **Zero secrets** appear in command-line arguments.
* **Zero secrets** written to `/etc/rclone/rclone.conf` or temporary files.

---

## 3. Class Hierarchy & Architecture

```
lib/
├── Destinations/
│   ├── DestinationInterface.php   # Contract for all destination types
│   ├── AbstractDestination.php    # Base class with validation, encryption, env generation
│   ├── DestinationManager.php     # Registry, DB persistence, connection testing coordinator
│   └── Providers/
│       ├── LocalDestination.php
│       ├── S3Destination.php
│       ├── SftpDestination.php
│       ├── FtpDestination.php
│       ├── WebdavDestination.php
│       ├── B2Destination.php
│       ├── GcsDestination.php
│       ├── AzureDestination.php
│       ├── DropboxDestination.php
│       ├── OnedriveDestination.php
│       ├── SwiftDestination.php
│       └── TencentDestination.php
```

### DestinationInterface Contract
```php
namespace CWP\RcloneCWP\Destinations;

interface DestinationInterface
{
    public function getType();
    public function getName();
    public function getRcloneType();
    public function getConfigFields();
    public function validateConfig(array $config);
    public function encryptConfig(array $config);
    public function decryptConfig(array $config);
    public function getRcloneEnv(array $decryptedConfig, $remoteName);
    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '');
    public function testConnection(array $decryptedConfig);
}
```

---

## 4. Connection Testing Flow

1. Admin clicks **"Test Connection"** in CWP UI (or runs via CLI test script).
2. Controller receives POST request with CSRF token and destination config.
3. Provider validates config structure.
4. Provider generates transient `remoteName` (e.g. `test_dest_<uniqid>`).
5. Decrypted config converted to `RCLONE_CONFIG_*` environment variables.
6. Execution:
   - For `local`: check directory existence / write permission.
   - For cloud/network remotes: invoke `Rclone::execute('lsjson', [$remoteTarget, '--max-depth', '1'], [], [], 15, $env)`.
   - If bucket/container doesn't exist, probe `about` or `mkdir`.
7. Return standardized JSON response: `{"ok": true, "message": "Connection successful", "details": {...}}`.

---

## 5. UI Integration

Extend `rcloneCWP.php` module entry point:
* Tabbed interface: **Dashboard** (Phase 1) | **Destinations** (Phase 2).
* Destinations Table:
  - Name, Type, Target/Path, Status (Enabled/Disabled), Last Tested, Actions (Test, Edit, Delete).
* Modal/Form:
  - Dynamic JavaScript switching of configuration fields based on selected destination type.
  - Test Connection button with real-time spinner and status indicator.
  - Safe editing: password/secret fields remain placeholder (`••••••••`) unless changed.

---

## 6. Ordered Build Steps

1. **Extend Rclone execution**: Update `lib/Rclone.php` to accept process environment variables in `execute()`.
2. **Create Destination contracts**: Implement `DestinationInterface.php` and `AbstractDestination.php`.
3. **Implement 12 Providers**: Implement concrete provider classes under `lib/Destinations/Providers/`.
4. **Implement DestinationManager**: Implement registry, DB CRUD, encryption glue, and testing coordinator.
5. **Autoloader Integration**: Update `bootstrap.php` to autoload `CWP\RcloneCWP\Destinations` and providers.
6. **UI Integration**: Add Destinations view and AJAX actions into `rcloneCWP.php` with CSRF defense.
7. **Test Suite**: Create `tests/destinations_test.php` covering validation, encryption, rclone env generation, and live local testing.
8. **Live Verification**: Run full test cycle on live CWP server and verify in browser.

---

## 7. Acceptance Criteria

- [ ] `lib/Rclone.php` accepts dynamic environment variables for `proc_open`.
- [ ] `DestinationInterface` and `AbstractDestination` implemented and PSR-12 compliant.
- [ ] All 12 storage provider classes implemented with exact required and sensitive field mappings.
- [ ] `DestinationManager` correctly performs CRUD operations on `rclone_destinations`.
- [ ] Sensitive credentials encrypted with AES-256-GCM before DB write; decrypted only in memory.
- [ ] `getRcloneEnv()` produces correct `RCLONE_CONFIG_*` mappings for every provider.
- [ ] Connection test succeeds on `local` provider (live directory test).
- [ ] CWP Admin Panel renders Destinations tab, forms, and test connection buttons.
- [ ] `tests/destinations_test.php` runs and passes all unit and integration assertions.
- [ ] Code passes syntax checks on PHP 7.1 floor (`/usr/local/cwp/php71/bin/php -l`).
