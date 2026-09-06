# rcloneCWP — Developer Guide
**Version**: 1.0.0  
**Last Updated**: 2026-09-06

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Development Environment Setup](#2-development-environment-setup)
3. [Module Architecture](#3-module-architecture)
4. [Coding Standards](#4-coding-standards)
5. [Working with CWP Core](#5-working-with-cwp-core)
6. [Database Operations](#6-database-operations)
7. [rclone Integration](#7-rclone-integration)
8. [Building a Destination](#8-building-a-destination)
9. [Building a Hook](#9-building-a-hook)
10. [API Development](#10-api-development)
11. [CLI Development](#11-cli-development)
12. [Testing](#12-testing)
13. [Debugging](#13-debugging)
14. [Common Patterns](#14-common-patterns)
15. [Publishing & Distribution](#15-publishing--distribution)

---

## 1. Introduction

This guide explains how to develop the rcloneCWP from scratch. It's designed for PHP developers who want to contribute to the project or understand how the module works internally.

### 1.1 Prerequisites

- **PHP**: 7.1+ knowledge required
- **MySQL/MariaDB**: Basic SQL knowledge
- **Linux**: Command line proficiency
- **rclone**: Basic understanding of cloud sync concepts
- **CWP**: Familiarity with CentOS Web Panel admin interface

### 1.2 Project Goals

1. Create a **native CWP module** that integrates seamlessly with the admin panel
2. Provide **JetBackup5-level features** using rclone
3. Maintain **enterprise-grade security** and stability
4. Keep everything **free and open-source** (MIT license)
5. Support **70+ cloud backends** through rclone

---

## 2. Development Environment Setup

### 2.1 Server Requirements

```bash
# CWP Pro (already installed on target server)
# PHP 7.1+ with CLI
# MySQL 5.5+ or MariaDB 10.0+
# rclone >= 1.60

# Verify requirements
php -v                    # PHP 7.1+
mysql --version           # MySQL 5.5+
rclone --version          # rclone 1.60+
```

### 2.2 Clone Repository

```bash
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/
git clone https://github.com/yourusername/rcloneCWP.git rcloneCWP
cd rcloneCWP
```

### 2.3 Set Permissions

```bash
# File ownership
chown -R root:root .

# File permissions
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;  # Actually 755 not 777 - 777 is dangerous

# Sensitive files
chmod 600 config.php
chmod 700 logs cache
```

### 2.4 Install Dependencies

```bash
# Install PHPUnit for testing
curl -LO https://phar.phpunit.de/phpunit-9.phar
chmod +x phpunit-9.phar
mv phpunit-9.phar /usr/local/bin/phpunit

# Install Composer (if needed)
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Install PHP dependencies
composer install --no-dev
```

### 2.5 IDE Configuration

**VS Code Extensions:**
- PHP Intelephense
- PHP Debug
- MySQL
- GitLens
- EditorConfig

**PHPStorm Setup:**
- Set PHP version to 7.1+
- Configure MySQL data source
- Set deployment path for CWP

---

## 3. Module Architecture

### 3.1 Directory Structure

```
rcloneCWP/
├── rcloneCWP.php          # Main entry point - router
├── config.php                 # Configuration constants
├── install.php                # Database schema + setup
├── uninstall.php              # Cleanup on uninstall
│
├── lib/                       # Core PHP classes
│   ├── Database.php           # PDO wrapper
│   ├── Logger.php             # Monolog-style logger
│   ├── Rclone.php             # rclone binary wrapper
│   ├── Destination.php        # Abstract base class
│   ├── DestinationFactory.php # Factory for destinations
│   ├── BackupEngine.php       # Main backup logic
│   ├── RestoreEngine.php      # Main restore logic
│   ├── Schedule.php           # Cron scheduling
│   ├── Hook.php               # Hook execution
│   ├── Notification.php       # Email/Slack/Telegram
│   ├── Encryption.php         # AES-256-GCM
│   ├── Validator.php          # Input sanitization
│   ├── CSRF.php               # CSRF protection
│   ├── API.php                # REST API router
│   ├── CLI.php                # CLI handler
│   └── Progress.php           # Progress tracking
│
├── destinations/              # Backend implementations
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
├── views/                     # UI templates
│   ├── dashboard.php
│   ├── backup_jobs.php
│   ├── backup_job_form.php
│   ├── destinations.php
│   ├── destination_form.php
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
│   ├── api_key_form.php
│   ├── settings.php
│   ├── logs.php
│   └── partials/
│       ├── header.php
│       ├── footer.php
│       ├── pagination.php
│       └── progress_bar.php
│
├── cron/                      # CLI cron scripts
│   ├── rcloneCWP.php
│   ├── rclone_cleanup.php
│   └── rclone_test.php
│
├── api/                       # REST API
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
├── cli/                       # CLI tools
│   ├── rcloneCWP
│   ├── rclone-restore
│   ├── rclone-destination
│   ├── rclone-schedule
│   └── rclone-log
│
├── language/                  # Translations
│   ├── en.php
│   └── bn.php
│
├── sql/                       # Database schemas
│   ├── install.sql
│   ├── uninstall.sql
│   └── updates/
│       ├── 1.0.0.sql
│       └── 1.1.0.sql
│
├── assets/                    # Static files
│   ├── css/
│   │   └── rcloneCWP.css
│   ├── js/
│   │   └── rcloneCWP.js
│   └── img/
│       └── icons/
│
├── templates/                 # Email/notification templates
│   ├── email/
│   │   ├── backup_success.php
│   │   ├── backup_failure.php
│   │   └── restore_complete.php
│   └── slack/
│       └── notification.php
│
├── tests/                     # PHPUnit tests
│   ├── phpunit.xml
│   ├── bootstrap.php
│   ├── unit/
│   │   ├── DatabaseTest.php
│   │   ├── RcloneTest.php
│   │   ├── DestinationTest.php
│   │   ├── BackupEngineTest.php
│   │   ├── EncryptionTest.php
│   │   ├── ValidatorTest.php
│   │   └── CSTFTest.php
│   └── integration/
│       ├── BackupFlowTest.php
│       └── RestoreFlowTest.php
│
├── logs/                      # Runtime logs
│   └── .htaccess
│
├── cache/                     # Runtime cache
│   └── .htaccess
│
├── README.md
├── CHANGELOG.md
├── DEVELOPER_GUIDE.md
└── LICENSE
```

### 3.2 Request Lifecycle

```
1. User navigates to module: /index.php?module=rcloneCWP
2. CWP includes: /modules/rcloneCWP/rcloneCWP.php
3. rcloneCWP.php:
   a. Loads config.php
   b. Starts session
   c. Checks admin authentication
   d. Routes to appropriate view based on ?action= parameter
   e. Loads view file from views/ directory
   f. View file uses lib/ classes for logic
   g. HTML output sent to browser
```

### 3.3 Main Entry Point

```php
<?php
// rcloneCWP.php - Main entry point

// Prevent direct access
if (!defined('CWP_MODULE')) {
    define('CWP_MODULE', true);
}

// Load configuration
require_once __DIR__ . '/config.php';

// Start session for CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify CWP admin authentication
if (!isset($_SESSION['lkey']) || !isset($_SESSION['cp'])) {
    die('Access denied: CWP authentication required');
}

// Load core classes
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Validator.php';
require_once __DIR__ . '/lib/CSRF.php';

// Route request
$action = isset($_GET['action']) ? Validator::string($_GET['action']) : 'dashboard';

// Map actions to view files
$routes = [
    'dashboard' => 'views/dashboard.php',
    'jobs' => 'views/backup_jobs.php',
    'job_form' => 'views/backup_job_form.php',
    'destinations' => 'views/destinations.php',
    'destination_form' => 'views/destination_form.php',
    'destination_test' => 'views/destination_test.php',
    'backups' => 'views/backups.php',
    'backup_detail' => 'views/backup_detail.php',
    'restore' => 'views/restore.php',
    'schedules' => 'views/schedules.php',
    'schedule_form' => 'views/schedule_form.php',
    'hooks' => 'views/hooks.php',
    'hook_form' => 'views/hook_form.php',
    'notifications' => 'views/notifications.php',
    'notification_form' => 'views/notification_form.php',
    'api_keys' => 'views/api_keys.php',
    'api_key_form' => 'views/api_key_form.php',
    'settings' => 'views/settings.php',
    'logs' => 'views/logs.php',
];

// Load view or show 404
if (isset($routes[$action]) && file_exists(__DIR__ . '/' . $routes[$action])) {
    require_once __DIR__ . '/' . $routes[$action];
} else {
    require_once __DIR__ . '/views/dashboard.php';
}
```

---

## 4. Coding Standards

### 4.1 PHP Standards

**Follow PSR-12 with these additions:**

```php
<?php
/**
 * File: lib/Rclone.php
 * Description: rclone binary wrapper class
 * Author: Your Name
 * Date: 2026-09-06
 */

namespace CWP\RcloneModule;

class Rclone {
    // Class code here
}
```

**Naming Conventions:**
- Classes: `PascalCase` (e.g., `BackupEngine`)
- Methods: `camelCase` (e.g., `execute()`)
- Variables: `camelCase` (e.g., `$backupId`)
- Constants: `UPPER_SNAKE_CASE` (e.g., `MAX_RETRIES`)
- Database columns: `snake_case` (e.g., `created_at`)

### 4.2 Security Standards

1. **All user input must be validated**
2. **All database queries must use prepared statements**
3. **All output must be escaped**
4. **All commands must use `escapeshellarg()`**
5. **CSRF tokens required for all forms**
6. **Credentials must be encrypted at rest**
7. **File permissions must be strict (0600 for config)**

### 4.3 Documentation Standards

**PHPDoc for all classes and methods:**

```php
/**
 * Executes a backup job
 *
 * @param array $job The backup job configuration
 * @param array $options Additional options
 * @return array Result with status, message, and backup_id
 * @throws Exception On critical failure
 */
public function executeBackup($job, $options = []) {
    // Implementation
}
```

### 4.4 Git Workflow

```bash
# Create feature branch
git checkout -b feature/add-azure-destination

# Make changes, commit often
git add .
git commit -m "feat: add Azure Blob destination"

# Push to remote
git push origin feature/add-azure-destination

# Create Pull Request on GitHub
```

---

## 5. Working with CWP Core

### 5.1 CWP Session Authentication

```php
<?php
// Verify admin session
function isAdminAuthenticated() {
    if (!isset($_SESSION['lkey']) || !isset($_SESSION['cp'])) {
        return false;
    }
    
    // Additional check: verify session against database
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM login WHERE lkey = ? AND cp = ?");
    $stmt->execute([$_SESSION['lkey'], $_SESSION['cp']]);
    $user = $stmt->fetch();
    
    return $user !== false;
}
```

### 5.2 CWP Database Connection

```php
<?php
// Read CWP MySQL credentials
function getCWPDatabaseConfig() {
    $configFile = '/usr/local/cwp/.conf/mysql_db.cnf';
    $config = parse_ini_file($configFile);
    
    return [
        'host' => $config['host'] ?? 'localhost',
        'database' => $config['db'] ?? 'root_cwp',
        'username' => $config['user'] ?? 'root',
        'password' => $config['pass'] ?? '',
        'charset' => 'utf8mb4'
    ];
}
```

### 5.3 CWP User/Account Functions

```php
<?php
// Get all CWP users
function getCWPUsers() {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT * FROM users ORDER BY user");
    return $stmt->fetchAll();
}

// Get user home directory
function getUserHomeDir($username) {
    return "/home/{$username}";
}

// Get user databases
function getUserDatabases($username) {
    $db = Database::getInstance();
    $stmt = $db->prepare("SHOW DATABASES LIKE '{$username}\\_%'");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get user domains
function getUserDomains($username) {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT domain FROM domains WHERE user = ?");
    $stmt->execute([$username]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get user DNS zones
function getUserDNSZones($username) {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT zone FROM dns WHERE user = ?");
    $stmt->execute([$username]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get user email accounts
function getUserEmails($username) {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM email WHERE user = ?");
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

// Get user FTP accounts
function getUserFTPAccounts($username) {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM ftp WHERE user = ?");
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

// Get user cron jobs
function getUserCronJobs($username) {
    $cronFile = "/var/spool/cron/{$username}";
    if (file_exists($cronFile)) {
        return file_get_contents($cronFile);
    }
    return '';
}
```

### 5.4 CWP CSS/JS Integration

```php
<?php
// Include CWP styles and scripts
function includeCWPPartials() {
    // CWP CSS
    echo '<link rel="stylesheet" href="/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="/css/cwp.css">';
    
    // Module CSS
    echo '<link rel="stylesheet" href="/index.php?module=rcloneCWP&action=css">';
    
    // jQuery (already included in CWP)
    echo '<script src="/js/jquery.min.js"></script>';
    
    // Module JS
    echo '<script src="/index.php?module=rcloneCWP&action=js">';
}
```

### 5.5 CWP Sidebar Menu

```php
<?php
// /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php

// Add Rclone Backup to sidebar
$rcloneMenuItem = "Rclone Backup|/index.php?module=rcloneCWP|fa fa-cloud|main";
echo $rcloneMenuItem;
```

**Menu Format:**
```
Label|URL|Icon CSS Class|Target
```

- **Label**: Display text
- **URL**: Link destination
- **Icon CSS Class**: Font Awesome icon class
- **Target**: `main` for main content area, `_blank` for new window

---

## 6. Database Operations

### 6.1 Database Class

```php
<?php
// lib/Database.php

namespace CWP\RcloneModule;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $config = $this->getConfig();
        
        try {
            $this->pdo = new PDO(
                "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']}"
                ]
            );
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function getConfig() {
        $configFile = '/usr/local/cwp/.conf/mysql_db.cnf';
        if (!file_exists($configFile)) {
            throw new \Exception("CWP database config not found");
        }
        
        $config = parse_ini_file($configFile);
        return [
            'host' => $config['host'] ?? 'localhost',
            'database' => $config['db'] ?? 'root_cwp',
            'username' => $config['user'] ?? 'root',
            'password' => $config['pass'] ?? '',
            'charset' => 'utf8mb4'
        ];
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        
        return $this->pdo->lastInsertId();
    }
    
    public function update($table, $data, $where) {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $whereClause = implode(' = ? AND ', array_keys($where)) . ' = ?';
        
        $sql = "UPDATE {$table} SET {$set} WHERE {$whereClause}";
        $params = array_merge(array_values($data), array_values($where));
        
        return $this->query($sql, $params)->rowCount();
    }
    
    public function delete($table, $where) {
        $whereClause = implode(' = ? AND ', array_keys($where)) . ' = ?';
        $sql = "DELETE FROM {$table} WHERE {$whereClause}";
        
        return $this->query($sql, array_values($where))->rowCount();
    }
}
```

### 6.2 Schema Installation

```php
<?php
// install.php

namespace CWP\RcloneModule;

require_once __DIR__ . '/lib/Database.php';

function install() {
    $db = Database::getInstance();
    $sql = file_get_contents(__DIR__ . '/sql/install.sql');
    
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $db->query($statement);
        }
    }
    
    // Create encryption key
    $keyFile = '/usr/local/cwp/.conf/rclone_module_key.conf';
    if (!file_exists($keyFile)) {
        $key = random_bytes(32);
        file_put_contents($keyFile, base64_encode($key));
        chmod($keyFile, 0600);
    }
    
    // Create required directories
    $dirs = [
        '/var/log/rcloneCWP',
        '/var/cache/rclone',
        __DIR__ . '/logs',
        __DIR__ . '/cache'
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }
    
    echo "Installation completed successfully!\n";
}

install();
```

### 6.3 Query Examples

```php
<?php
// Get all destinations
$destinations = $db->fetchAll("SELECT * FROM rclone_destinations WHERE enabled = 1 ORDER BY name");

// Get job with destination names
$job = $db->fetch(
    "SELECT j.*, GROUP_CONCAT(d.name) as destination_names 
     FROM rclone_jobs j 
     LEFT JOIN rclone_destinations d ON FIND_IN_SET(d.id, j.destinations) 
     WHERE j.id = ? 
     GROUP BY j.id",
    [$jobId]
);

// Get backups with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$backups = $db->fetchAll(
    "SELECT * FROM rcloneCWPs ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
);

// Get backup stats
$stats = $db->fetch(
    "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(size_bytes) as total_size
     FROM rcloneCWPs"
);
```

---

## 7. rclone Integration

### 7.1 Rclone Wrapper Class

```php
<?php
// lib/Rclone.php

namespace CWP\RcloneModule;

class Rclone {
    private $configPath;
    private $binaryPath;
    private $defaultOptions;
    
    public function __construct($configPath = '/etc/rclone/rclone.conf') {
        $this->configPath = $configPath;
        $this->binaryPath = $this->findBinary();
        $this->defaultOptions = [
            '--transfers' => '4',
            '--checkers' => '8',
            '--buffer-size' => '32M',
            '--tpslimit' => '10',
            '--tpslimit-burst' => '20',
            '--retries' => '3',
            '--retries-sleep' => '1s',
            '--fast-list' => '',
            '--config' => $this->configPath,
        ];
    }
    
    private function findBinary() {
        $paths = [
            '/usr/bin/rclone',
            '/usr/local/bin/rclone',
            '/usr/local/sbin/rclone',
            trim(shell_exec('which rclone 2>/dev/null'))
        ];
        
        foreach ($paths as $path) {
            if ($path && is_executable($path)) {
                return $path;
            }
        }
        
        throw new \Exception("rclone binary not found");
    }
    
    /**
     * Execute an rclone command
     *
     * @param string $command The rclone command (copy, move, sync, ls, etc.)
     * @param array $args Arguments for the command
     * @param array $options Override default options
     * @return array ['output' => [], 'returnCode' => int, 'success' => bool]
     */
    public function execute($command, $args = [], $options = []) {
        // Whitelist allowed commands
        $allowedCommands = [
            'copy', 'move', 'sync', 'ls', 'lsd', 'lsl', 'size',
            'delete', 'rmdir', 'rmdirs', 'purge', 'mkdir',
            'cat', 'checksum', 'version', 'config'
        ];
        
        if (!in_array($command, $allowedCommands)) {
            throw new \Exception("Command not allowed: {$command}");
        }
        
        // Build command parts
        $cmdParts = [$this->binaryPath, $command];
        
        // Add options
        $mergedOptions = array_merge($this->defaultOptions, $options);
        foreach ($mergedOptions as $key => $value) {
            if (is_numeric($key)) {
                $cmdParts[] = escapeshellarg($value);
            } elseif ($value === '' || $value === false) {
                // Boolean flag
                $cmdParts[] = $key;
            } else {
                $cmdParts[] = "{$key} " . escapeshellarg($value);
            }
        }
        
        // Add arguments
        foreach ($args as $arg) {
            $cmdParts[] = escapeshellarg($arg);
        }
        
        // Build final command
        $cmd = implode(' ', $cmdParts) . ' 2>&1';
        
        // Execute
        Logger::debug("Executing rclone command", ['command' => $cmd]);
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        return [
            'output' => $output,
            'returnCode' => $returnCode,
            'success' => $returnCode === 0
        ];
    }
    
    /**
     * Copy files to remote
     */
    public function copy($source, $destination, $options = []) {
        return $this->execute('copy', [$source, $destination], $options);
    }
    
    /**
     * Sync files to remote
     */
    public function sync($source, $destination, $options = []) {
        return $this->execute('sync', [$source, $destination], $options);
    }
    
    /**
     * List files on remote
     */
    public function ls($remote, $options = []) {
        return $this->execute('ls', [$remote], $options);
    }
    
    /**
     * List directories on remote
     */
    public function lsd($remote, $options = []) {
        return $this->execute('lsd', [$remote], $options);
    }
    
    /**
     * Get size of remote
     */
    public function size($remote, $options = []) {
        return $this->execute('size', [$remote], $options);
    }
    
    /**
     * Delete file on remote
     */
    public function delete($remote, $options = []) {
        return $this->execute('delete', [$remote], $options);
    }
    
    /**
     * Remove empty directory on remote
     */
    public function rmdir($remote, $options = []) {
        return $this->execute('rmdir', [$remote], $options);
    }
    
    /**
     * Remove directory tree on remote
     */
    public function purge($remote, $options = []) {
        return $this->execute('purge', [$remote], $options);
    }
    
    /**
     * Create directory on remote
     */
    public function mkdir($remote, $options = []) {
        return $this->execute('mkdir', [$remote], $options);
    }
    
    /**
     * Get file content from remote
     */
    public function cat($remote, $options = []) {
        return $this->execute('cat', [$remote], $options);
    }
    
    /**
     * Get checksum of remote file
     */
    public function checksum($remote, $hash = 'sha256', $options = []) {
        $options['--hash'] = $hash;
        return $this->execute('checksum', [$remote], $options);
    }
    
    /**
     * Obscure a password (for rclone config)
     */
    public function obscure($password) {
        $result = $this->execute('config', ['obscure', $password]);
        return trim(implode('', $result['output']));
    }
    
    /**
     * Get rclone version
     */
    public function version() {
        $result = $this->execute('version', []);
        return implode("\n", $result['output']);
    }
    
    /**
     * Test remote connection
     */
    public function testConnection($remote) {
        $result = $this->execute('lsd', [$remote]);
        return $result['success'];
    }
}
```

### 7.2 Backup Engine

```php
<?php
// lib/BackupEngine.php

namespace CWP\RcloneModule;

class BackupEngine {
    private $db;
    private $rclone;
    private $logger;
    private $encryption;
    
    public function __construct($job = null) {
        $this->db = Database::getInstance();
        $this->rclone = new Rclone();
        $this->logger = new Logger();
        $this->encryption = new Encryption();
        
        if ($job) {
            $this->job = $job;
        }
    }
    
    /**
     * Execute a backup job
     */
    public function execute($jobId = null) {
        if ($jobId) {
            $this->job = $this->db->fetch(
                "SELECT * FROM rclone_jobs WHERE id = ?",
                [$jobId]
            );
        }
        
        if (!$this->job) {
            throw new \Exception("No backup job specified");
        }
        
        $jobId = $this->job['id'];
        $lock = new Lock("job_{$jobId}");
        
        // Acquire lock
        if (!$lock->acquire()) {
            $this->logger->warning("Job {$jobId} is already running", ['job_id' => $jobId]);
            return ['status' => 'skipped', 'message' => 'Job already running'];
        }
        
        try {
            // Pre-backup hooks
            $this->executeHooks('pre_backup', $jobId);
            
            // Get users to backup
            $users = $this->getUsers();
            $destinations = $this->getDestinations();
            $components = json_decode($this->job['components'], true);
            
            $results = [];
            
            foreach ($users as $user) {
                foreach ($destinations as $destination) {
                    $result = $this->backupUser($user, $destination, $components);
                    $results[] = $result;
                }
            }
            
            // Post-backup hooks
            $this->executeHooks('post_backup', $jobId);
            
            // Update job status
            $this->db->update('rclone_jobs', [
                'last_run' => date('Y-m-d H:i:s'),
                'last_status' => 'success'
            ], ['id' => $jobId]);
            
            // Send success notification
            $this->sendNotification('backup_success', $jobId, $results);
            
            $lock->release();
            
            return [
                'status' => 'success',
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            $this->logger->error("Job {$jobId} failed: " . $e->getMessage(), [
                'job_id' => $jobId,
                'exception' => $e
            ]);
            
            // Update job status
            $this->db->update('rclone_jobs', [
                'last_run' => date('Y-m-d H:i:s'),
                'last_status' => 'failure',
                'last_message' => $e->getMessage()
            ], ['id' => $jobId]);
            
            // Execute failure hooks
            $this->executeHooks('on_failure', $jobId);
            
            // Send failure notification
            $this->sendNotification('backup_failure', $jobId, $e->getMessage());
            
            $lock->release();
            
            return [
                'status' => 'failure',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Backup a single user
     */
    private function backupUser($user, $destination, $components) {
        $backupId = $this->db->insert('rcloneCWPs', [
            'job_id' => $this->job['id'],
            'destination_id' => $destination['id'],
            'user' => $user,
            'backup_type' => $this->job['backup_type'],
            'components' => json_encode($components),
            'status' => 'running',
            'progress' => 0
        ]);
        
        try {
            // Create temp directory
            $tempDir = "/backup/.backup_temp/rclone/{$backupId}";
            $this->mkdir($tempDir);
            
            // Collect components
            $collected = [];
            
            if (in_array('files', $components)) {
                $collected['files'] = $this->collectFiles($user, $tempDir);
            }
            
            if (in_array('databases', $components)) {
                $collected['databases'] = $this->collectDatabases($user, $tempDir);
            }
            
            if (in_array('dns', $components)) {
                $collected['dns'] = $this->collectDNS($user, $tempDir);
            }
            
            if (in_array('emails', $components)) {
                $collected['emails'] = $this->collectEmails($user, $tempDir);
            }
            
            if (in_array('ssl', $components)) {
                $collected['ssl'] = $this->collectSSL($user, $tempDir);
            }
            
            if (in_array('cron', $components)) {
                $collected['cron'] = $this->collectCron($user, $tempDir);
            }
            
            if (in_array('ftp', $components)) {
                $collected['ftp'] = $this->collectFTP($user, $tempDir);
            }
            
            // Create metadata
            $this->createMetadata($user, $tempDir, $collected);
            
            // Compress
            $archive = $this->compress($tempDir, $backupId);
            
            // Encrypt (if enabled)
            if ($this->job['encryption']) {
                $archive = $this->encryptArchive($archive);
            }
            
            // Transfer to destination
            $remotePath = $this->getRemotePath($destination, $user, $backupId);
            $this->transferToDestination($archive, $remotePath, $destination);
            
            // Verify transfer
            $this->verifyTransfer($archive, $remotePath, $destination);
            
            // Cleanup temp files
            $this->cleanup($tempDir, $archive);
            
            // Update backup record
            $this->db->update('rcloneCWPs', [
                'status' => 'completed',
                'progress' => 100,
                'remote_path' => $remotePath,
                'size_bytes' => filesize($archive),
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => time() - strtotime(date('Y-m-d H:i:s')),
                'checksum' => hash_file('sha256', $archive)
            ], ['id' => $backupId]);
            
            return [
                'user' => $user,
                'destination' => $destination['name'],
                'status' => 'success',
                'backup_id' => $backupId
            ];
            
        } catch (\Exception $e) {
            $this->db->update('rcloneCWPs', [
                'status' => 'failed',
                'message' => $e->getMessage()
            ], ['id' => $backupId]);
            
            throw $e;
        }
    }
    
    // ... (additional methods for collectFiles, collectDatabases, etc.)
    
    private function collectFiles($user, $tempDir) {
        $homeDir = "/home/{$user}";
        $backupDir = "{$tempDir}/files";
        $this->mkdir($backupDir);
        
        // Use rsync for efficient file copying
        $excludePaths = json_decode($this->job['exclude_paths'] ?? '[]', true);
        $excludeArgs = '';
        foreach ($excludePaths as $path) {
            $excludeArgs .= " --exclude=" . escapeshellarg($path);
        }
        
        $cmd = sprintf(
            'rsync -a%s %s/ %s/ 2>&1',
            $excludeArgs,
            escapeshellarg($homeDir),
            escapeshellarg($backupDir)
        );
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception("rsync failed for user {$user}: " . implode("\n", $output));
        }
        
        return $backupDir;
    }
    
    private function collectDatabases($user, $tempDir) {
        $backupDir = "{$tempDir}/databases";
        $this->mkdir($backupDir);
        
        $databases = $this->getUserDatabases($user);
        
        foreach ($databases as $db) {
            $dumpFile = "{$backupDir}/{$db}.sql";
            $cmd = sprintf(
                'mysqldump --single-transaction --routines --triggers --quick %s > %s 2>&1',
                escapeshellarg($db),
                escapeshellarg($dumpFile)
            );
            
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \Exception("mysqldump failed for database {$db}");
            }
            
            // Compress the SQL dump
            $cmd = sprintf('gzip %s 2>&1', escapeshellarg($dumpFile));
            exec($cmd, $output, $returnCode);
        }
        
        return $backupDir;
    }
    
    private function compress($tempDir, $backupId) {
        $compression = $this->job['compression'] ?? 'gzip';
        $level = $this->job['compression_level'] ?? 6;
        
        $archive = "/backup/.backup_temp/rclone/backup_{$backupId}.tar";
        
        switch ($compression) {
            case 'gzip':
                $archive .= '.gz';
                $cmd = sprintf(
                    'tar -czf %s -C %s . 2>&1',
                    escapeshellarg($archive),
                    escapeshellarg($tempDir)
                );
                break;
            case 'bzip2':
                $archive .= '.bz2';
                $cmd = sprintf(
                    'tar -cjf %s -C %s . 2>&1',
                    escapeshellarg($archive),
                    escapeshellarg($tempDir)
                );
                break;
            case 'zstd':
                $archive .= '.zst';
                $cmd = sprintf(
                    'tar --zstd -cf %s -C %s . 2>&1',
                    escapeshellarg($archive),
                    escapeshellarg($tempDir)
                );
                break;
            default:
                $cmd = sprintf(
                    'tar -cf %s -C %s . 2>&1',
                    escapeshellarg($archive),
                    escapeshellarg($tempDir)
                );
        }
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception("Compression failed: " . implode("\n", $output));
        }
        
        return $archive;
    }
    
    private function transferToDestination($archive, $remotePath, $destination) {
        $options = [
            '--progress' => '',
            '--transfers' => '4',
        ];
        
        // Add bandwidth limit if set
        if (!empty($destination['bandwidth_limit'])) {
            $options['--bwlimit'] = $destination['bandwidth_limit'];
        }
        
        // Decrypt config for use
        $config = $this->decryptDestinationConfig($destination);
        
        $result = $this->rclone->copy($archive, $remotePath, $options);
        
        if (!$result['success']) {
            throw new \Exception("Transfer failed: " . implode("\n", $result['output']));
        }
    }
}
```

---

## 8. Building a Destination

### 8.1 Abstract Base Class

```php
<?php
// lib/Destination.php

namespace CWP\RcloneModule;

abstract class Destination {
    public $type;
    public $name;
    public $icon;
    public $configFields = [];
    
    protected $rclone;
    protected $encryption;
    
    public function __construct() {
        $this->rclone = new Rclone();
        $this->encryption = new Encryption();
    }
    
    /**
     * Validate configuration
     */
    abstract public function validateConfig($config);
    
    /**
     * Test connection with this config
     */
    abstract public function testConnection($config);
    
    /**
     * Build rclone config section
     */
    abstract public function buildRcloneConfig($config);
    
    /**
     * Get storage path for user backups
     */
    abstract public function getStoragePath($config, $user);
    
    /**
     * Get config fields for UI form
     */
    public function getConfigFields() {
        return $this->configFields;
    }
    
    /**
     * Encrypt sensitive config values before storage
     */
    public function encryptConfig($config) {
        $encrypted = $config;
        
        // Encrypt known sensitive fields
        $sensitiveFields = ['password', 'secret', 'token', 'key', 'pass'];
        
        foreach ($encrypted as $key => $value) {
            foreach ($sensitiveFields as $sensitive) {
                if (stripos($key, $sensitive) !== false && !empty($value)) {
                    $encrypted[$key] = $this->encryption->encrypt($value);
                    break;
                }
            }
        }
        
        return $encrypted;
    }
    
    /**
     * Decrypt sensitive config values for use
     */
    public function decryptConfig($config) {
        $decrypted = $config;
        
        $sensitiveFields = ['password', 'secret', 'token', 'key', 'pass'];
        
        foreach ($decrypted as $key => $value) {
            foreach ($sensitiveFields as $sensitive) {
                if (stripos($key, $sensitive) !== false && !empty($value)) {
                    $decrypted[$key] = $this->encryption->decrypt($value);
                    break;
                }
            }
        }
        
        return $decrypted;
    }
}
```

### 8.2 Example: FTP Destination

```php
<?php
// destinations/FTP.php

namespace CWP\RcloneModule;

class FTP extends Destination {
    public $type = 'ftp';
    public $name = 'FTP Server';
    public $icon = 'fa-server';
    
    public $configFields = [
        ['name' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true],
        ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'default' => 21],
        ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
        ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
        ['name' => 'path', 'label' => 'Path', 'type' => 'text', 'default' => '/backups'],
        ['name' => 'ssl', 'label' => 'Use SSL', 'type' => 'checkbox', 'default' => false],
    ];
    
    public function validateConfig($config) {
        $required = ['host', 'username', 'password'];
        
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }
        
        if (!empty($config['port']) && ($config['port'] < 1 || $config['port'] > 65535)) {
            throw new \Exception("Invalid port number");
        }
        
        return true;
    }
    
    public function testConnection($config) {
        try {
            $this->validateConfig($config);
            $decrypted = $this->decryptConfig($config);
            
            $remoteName = 'test_ftp_' . time();
            $this->addToRcloneConfig($remoteName, $decrypted);
            
            $result = $this->rclone->lsd("{$remoteName}:{$decrypted['path']}");
            
            $this->removeFromRcloneConfig($remoteName);
            
            return $result['success'];
            
        } catch (\Exception $e) {
            Logger::error("FTP test connection failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function buildRcloneConfig($config) {
        $decrypted = $this->decryptConfig($config);
        
        $rcloneConfig = [
            'type' => 'ftp',
            'host' => $decrypted['host'],
            'user' => $decrypted['username'],
            'port' => $decrypted['port'] ?? 21,
        ];
        
        // Obscure password for rclone config format
        if (!empty($decrypted['password'])) {
            $rcloneConfig['pass'] = $this->rclone->obscure($decrypted['password']);
        }
        
        // Add SSL option
        if (!empty($decrypted['ssl'])) {
            $rcloneConfig['explicit_tls'] = true;
        }
        
        return $rcloneConfig;
    }
    
    public function getStoragePath($config, $user) {
        $path = rtrim($config['path'], '/');
        return "ftp://{$config['host']}{$path}/{$user}/";
    }
}
```

### 8.3 Example: Google Drive Destination

```php
<?php
// destinations/GoogleDrive.php

namespace CWP\RcloneModule;

class GoogleDrive extends Destination {
    public $type = 'gdrive';
    public $name = 'Google Drive';
    public $icon = 'fa-google';
    
    public $configFields = [
        ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
        ['name' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ['name' => 'token', 'label' => 'OAuth Token (JSON)', 'type' => 'textarea', 'required' => true],
        ['name' => 'root_folder_id', 'label' => 'Root Folder ID', 'type' => 'text', 'required' => false],
    ];
    
    public function validateConfig($config) {
        $required = ['client_id', 'client_secret', 'token'];
        
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }
        
        // Validate token is valid JSON
        $token = json_decode($config['token'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid OAuth token JSON");
        }
        
        return true;
    }
    
    public function testConnection($config) {
        try {
            $this->validateConfig($config);
            
            $remoteName = 'test_gdrive_' . time();
            $this->addToRcloneConfig($remoteName, $config);
            
            $result = $this->rclone->lsd("{$remoteName}:");
            
            $this->removeFromRcloneConfig($remoteName);
            
            return $result['success'];
            
        } catch (\Exception $e) {
            Logger::error("Google Drive test connection failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function buildRcloneConfig($config) {
        return [
            'type' => 'drive',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'token' => $config['token'],
            'root_folder_id' => $config['root_folder_id'] ?? '',
        ];
    }
    
    public function getStoragePath($config, $user) {
        $folderId = $config['root_folder_id'] ?? '';
        if ($folderId) {
            return "gdrive://{$folderId}/backups/{$user}/";
        }
        return "gdrive://backups/{$user}/";
    }
    
    /**
     * Get OAuth URL for initial setup
     */
    public function getOAuthUrl($config) {
        $remoteName = 'temp_oauth_' . time();
        $this->addToRcloneConfig($remoteName, $config);
        
        $result = $this->rclone->execute('config', [
            'authorize',
            "--drive-scopes=full",
            "--state=rclone_module_auth",
            $remoteName
        ]);
        
        $this->removeFromRcloneConfig($remoteName);
        
        // Parse the URL from output
        foreach ($result['output'] as $line) {
            if (preg_match('/^https:\/\//', $line)) {
                return trim($line);
            }
        }
        
        throw new \Exception("Failed to get OAuth URL");
    }
}
```

---

## 9. Building a Hook

### 9.1 Hook Class

```php
<?php
// lib/Hook.php

namespace CWP\RcloneModule;

class Hook {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = new Logger();
    }
    
    /**
     * Execute all hooks for a given hook point
     *
     * @param string $hookPoint The hook point name
     * @param int $jobId The job ID
     * @return bool True if all hooks succeeded
     */
    public function execute($hookPoint, $jobId = null) {
        $hooks = $this->getHooks($hookPoint);
        
        if (empty($hooks)) {
            return true;
        }
        
        $success = true;
        
        foreach ($hooks as $hook) {
            try {
                $this->executeHook($hook, $jobId);
            } catch (\Exception $e) {
                $this->logger->error("Hook {$hook['name']} failed: " . $e->getMessage(), [
                    'hook_id' => $hook['id'],
                    'hook_point' => $hookPoint,
                    'job_id' => $jobId
                ]);
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Execute a single hook
     */
    private function executeHook($hook, $jobId) {
        $this->logger->info("Executing hook: {$hook['name']}", [
            'hook_id' => $hook['id'],
            'type' => $hook['type'],
            'timeout' => $hook['timeout']
        ]);
        
        $startTime = microtime(true);
        
        switch ($hook['type']) {
            case 'shell':
                $result = $this->executeShellHook($hook, $jobId);
                break;
            case 'php':
                $result = $this->executePHPHook($hook, $jobId);
                break;
            case 'python':
                $result = $this->executePythonHook($hook, $jobId);
                break;
            case 'url':
                $result = $this->executeURLHook($hook, $jobId);
                break;
            default:
                throw new \Exception("Unknown hook type: {$hook['type']}");
        }
        
        $duration = round(microtime(true) - $startTime, 3);
        
        $this->logger->info("Hook {$hook['name']} completed in {$duration}s", [
            'hook_id' => $hook['id'],
            'success' => $result['success'],
            'duration' => $duration
        ]);
        
        return $result['success'];
    }
    
    /**
     * Execute shell hook
     */
    private function executeShellHook($hook, $jobId) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rclone_hook_');
        file_put_contents($tmpFile, $hook['content']);
        chmod($tmpFile, 0700);
        
        // Set environment variables
        $env = [
            'RCLONE_JOB_ID' => $jobId,
            'RCLONE_HOOK_POINT' => $hook['hook_point'],
        ];
        
        $envString = '';
        foreach ($env as $key => $value) {
            $envString .= "{$key}=" . escapeshellarg($value) . " ";
        }
        
        $cmd = "{$envString} /usr/bin/timeout {$hook['timeout']} /bin/bash {$tmpFile} 2>&1";
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        unlink($tmpFile);
        
        return [
            'success' => $returnCode === 0,
            'output' => $output,
            'returnCode' => $returnCode
        ];
    }
    
    /**
     * Execute PHP hook
     */
    private function executePHPHook($hook, $jobId) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rclone_hook_') . '.php';
        file_put_contents($tmpFile, $hook['content']);
        
        $cmd = sprintf(
            '/usr/local/cwp/php71/bin/php -d max_execution_time=%d %s 2>&1',
            $hook['timeout'],
            escapeshellarg($tmpFile)
        );
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        unlink($tmpFile);
        
        return [
            'success' => $returnCode === 0,
            'output' => $output,
            'returnCode' => $returnCode
        ];
    }
    
    /**
     * Execute Python hook
     */
    private function executePythonHook($hook, $jobId) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rclone_hook_') . '.py';
        file_put_contents($tmpFile, $hook['content']);
        
        $cmd = sprintf(
            '/usr/bin/python3 %s 2>&1',
            escapeshellarg($tmpFile)
        );
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        unlink($tmpFile);
        
        return [
            'success' => $returnCode === 0,
            'output' => $output,
            'returnCode' => $returnCode
        ];
    }
    
    /**
     * Execute URL hook
     */
    private function executeURLHook($hook, $jobId) {
        $url = $hook['content'];
        
        $data = [
            'hook_point' => $hook['hook_point'],
            'job_id' => $jobId,
            'timestamp' => time()
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $hook['timeout'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $error
        ];
    }
    
    /**
     * Get all enabled hooks for a hook point
     */
    public function getHooks($hookPoint) {
        return $this->db->fetchAll(
            "SELECT * FROM rclone_hooks WHERE hook_point = ? AND enabled = 1 ORDER BY run_order ASC",
            [$hookPoint]
        );
    }
}
```

### 9.2 Example Hook Scripts

**Pre-backup: Flush caches**
```bash
#!/bin/bash
# Flush Memcached
echo "Flushing Memcached..."
echo "flush_all" | nc -q 1 127.0.0.1 11211

# Flush Redis
echo "Flushing Redis..."
redis-cli FLUSHALL

# Flush OPcache
echo "Flushing OPcache..."
curl -s "http://localhost/opcache_reset.php" || true

echo "Cache flush completed"
exit 0
```

**Post-backup: Verify backup**
```bash
#!/bin/bash
JOB_ID="${RCLONE_JOB_ID}"
BACKUP_DIR="/backup/.backup_temp/rclone/${JOB_ID}"

echo "Verifying backup for job ${JOB_ID}..."

# Check archive exists and is valid
ARCHIVE=$(ls -t ${BACKUP_DIR}/../backup_*.tar.* 2>/dev/null | head -1)

if [ -z "$ARCHIVE" ]; then
    echo "ERROR: No archive found!"
    exit 1
fi

# Verify archive integrity
if [[ "$ARCHIVE" == *.tar.gz ]]; then
    tar -tzf "$ARCHIVE" > /dev/null 2>&1
elif [[ "$ARCHIVE" == *.tar.bz2 ]]; then
    tar -tjf "$ARCHIVE" > /dev/null 2>&1
elif [[ "$ARCHIVE" == *.tar.zst ]]; then
    tar --zstd -tf "$ARCHIVE" > /dev/null 2>&1
fi

if [ $? -eq 0 ]; then
    echo "Archive integrity verified: $ARCHIVE"
    echo "Size: $(du -h "$ARCHIVE" | cut -f1)"
    exit 0
else
    echo "ERROR: Archive integrity check failed!"
    exit 1
fi
```

**On failure: Slack notification**
```bash
#!/bin/bash
WEBHOOK_URL="https://hooks.slack.com/services/YOUR/WEBHOOK/URL"
JOB_ID="${RCLONE_JOB_ID}"

MESSAGE=":x: Backup job ${JOB_ID} failed on $(hostname)"

curl -s -X POST -H 'Content-type: application/json' \
    "{\"text\":\"${MESSAGE}\"}" \
    "$WEBHOOK_URL"
```

---

## 10. API Development

### 10.1 API Router

```php
<?php
// api/index.php

namespace CWP\RcloneModule;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/API.php';
require_once __DIR__ . '/../lib/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../lib/middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/../lib/middleware/CORSMiddleware.php';

// Set JSON header
header('Content-Type: application/json');

// CORS
CORSMiddleware::handle();

// Only handle API requests
$requestUri = $_SERVER['REQUEST_URI'];
if (strpos($requestUri, 'module=rcloneCWP') === false || 
    strpos($requestUri, 'action=api') === false) {
    exit;
}

// Parse endpoint
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';
$method = $_SERVER['REQUEST_METHOD'];

// Auth
$auth = new AuthMiddleware();
if (!$auth->handle()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Rate limit
$rateLimit = new RateLimitMiddleware();
if (!$rateLimit->handle()) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

// Route
$api = new API();
$response = $api->route($method, $endpoint);

http_response_code($response['code'] ?? 200);
echo json_encode($response);
```

### 10.2 API Class

```php
<?php
// lib/API.php

namespace CWP\RcloneModule;

class API {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Route API request
     */
    public function route($method, $endpoint) {
        $parts = explode('/', trim($endpoint, '/'));
        $resource = $parts[0] ?? '';
        $id = $parts[1] ?? null;
        $action = $parts[2] ?? null;
        
        try {
            switch ($resource) {
                case 'destinations':
                    return $this->handleDestinations($method, $id, $action);
                case 'jobs':
                    return $this->handleJobs($method, $id, $action);
                case 'backups':
                    return $this->handleBackups($method, $id, $action);
                case 'schedules':
                    return $this->handleSchedules($method, $id);
                case 'hooks':
                    return $this->handleHooks($method, $id);
                case 'logs':
                    return $this->handleLogs($method);
                case 'status':
                    return $this->handleStatus($method);
                default:
                    return ['code' => 404, 'error' => 'Endpoint not found'];
            }
        } catch (\Exception $e) {
            return ['code' => 500, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Handle destinations endpoints
     */
    private function handleDestinations($method, $id, $action) {
        switch ($method) {
            case 'GET':
                if ($id) {
                    return $this->getDestination($id);
                }
                return $this->listDestinations();
                
            case 'POST':
                if ($action === 'test' && $id) {
                    return $this->testDestination($id);
                }
                return $this->createDestination();
                
            case 'PUT':
                if ($id) {
                    return $this->updateDestination($id);
                }
                throw new \Exception("ID required");
                
            case 'DELETE':
                if ($id) {
                    return $this->deleteDestination($id);
                }
                throw new \Exception("ID required");
                
            default:
                return ['code' => 405, 'error' => 'Method not allowed'];
        }
    }
    
    /**
     * List all destinations
     */
    private function listDestinations() {
        $destinations = $this->db->fetchAll(
            "SELECT id, name, type, enabled, bandwidth_limit, test_status, last_test, created_at FROM rclone_destinations ORDER BY name"
        );
        
        return ['data' => $destinations];
    }
    
    /**
     * Get single destination
     */
    private function getDestination($id) {
        $destination = $this->db->fetch(
            "SELECT * FROM rclone_destinations WHERE id = ?",
            [$id]
        );
        
        if (!$destination) {
            return ['code' => 404, 'error' => 'Destination not found'];
        }
        
        // Don't return config (contains credentials)
        unset($destination['config']);
        
        return ['data' => $destination];
    }
    
    /**
     * Create destination
     */
    private function createDestination() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate
        if (empty($input['name']) || empty($input['type']) || empty($input['config'])) {
            return ['code' => 400, 'error' => 'Missing required fields: name, type, config'];
        }
        
        // Insert
        $id = $this->db->insert('rclone_destinations', [
            'name' => Validator::string($input['name']),
            'type' => Validator::string($input['type']),
            'config' => json_encode($input['config']),
            'bandwidth_limit' => $input['bandwidth_limit'] ?? null,
            'enabled' => $input['enabled'] ?? 1,
        ]);
        
        return ['code' => 201, 'id' => $id, 'message' => 'Destination created'];
    }
    
    /**
     * Test destination connection
     */
    private function testDestination($id) {
        $destination = $this->db->fetch(
            "SELECT * FROM rclone_destinations WHERE id = ?",
            [$id]
        );
        
        if (!$destination) {
            return ['code' => 404, 'error' => 'Destination not found'];
        }
        
        // Load destination class
        $factory = new DestinationFactory();
        $dest = $factory->create($destination);
        
        if (!$dest) {
            return ['code' => 400, 'error' => 'Invalid destination type'];
        }
        
        $config = json_decode($destination['config'], true);
        $success = $dest->testConnection($config);
        
        // Update test status
        $this->db->update('rclone_destinations', [
            'test_status' => $success ? 1 : 0,
            'last_test' => date('Y-m-d H:i:s')
        ], ['id' => $id]);
        
        return [
            'success' => $success,
            'message' => $success ? 'Connection successful' : 'Connection failed'
        ];
    }
}
```

### 10.3 API Middleware

```php
<?php
// lib/middleware/AuthMiddleware.php

namespace CWP\RcloneModule;

class AuthMiddleware {
    public function handle() {
        // Check for Bearer token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return false;
        }
        
        $apiKey = $matches[1];
        
        // Validate against database
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM rclone_api_keys WHERE api_key = ? AND enabled = 1"
        );
        $stmt->execute([$apiKey]);
        $keyData = $stmt->fetch();
        
        if (!$keyData) {
            return false;
        }
        
        // Check IP whitelist
        if (!empty($keyData['ip_whitelist'])) {
            $allowedIPs = json_decode($keyData['ip_whitelist'], true);
            if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
                return false;
            }
        }
        
        // Update last used
        $db->update('rclone_api_keys', [
            'last_used' => date('Y-m-d H:i:s')
        ], ['id' => $keyData['id']]);
        
        return true;
    }
}
```

```php
<?php
// lib/middleware/RateLimitMiddleware.php

namespace CWP\RcloneModule;

class RateLimitMiddleware {
    public function handle() {
        $db = Database::getInstance();
        
        // Get API key
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return true; // No key, let AuthMiddleware handle
        }
        
        $apiKey = $matches[1];
        
        // Get key data
        $stmt = $db->prepare("SELECT * FROM rclone_api_keys WHERE api_key = ?");
        $stmt->execute([$apiKey]);
        $keyData = $stmt->fetch();
        
        if (!$keyData || empty($keyData['rate_limit'])) {
            return true;
        }
        
        // Check rate limit (simple implementation)
        $cacheKey = 'rate_limit_' . md5($apiKey);
        $cacheFile = "/tmp/{$cacheKey}";
        
        $requests = [];
        $now = time();
        $window = 60; // 1 minute
        
        if (file_exists($cacheFile)) {
            $requests = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        
        // Remove old entries
        $requests = array_filter($requests, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        if (count($requests) >= $keyData['rate_limit']) {
            return false;
        }
        
        $requests[] = $now;
        file_put_contents($cacheFile, json_encode($requests));
        
        return true;
    }
}
```

---

## 11. CLI Development

### 11.1 CLI Entry Point

```bash
#!/usr/local/cwp/php71/bin/php
<?php
// cli/rcloneCWP

namespace CWP\RcloneModule;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/CLI.php';

$cli = new CLI();
$cli->run($argv);
```

### 11.2 CLI Class

```php
<?php
// lib/CLI.php

namespace CWP\RcloneModule;

class CLI {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = new Logger();
    }
    
    public function run($argv) {
        array_shift($argv); // Remove script name
        
        if (empty($argv)) {
            $this->showHelp();
            return;
        }
        
        $command = array_shift($argv);
        
        switch ($command) {
            case 'job':
                $this->handleJob($argv);
                break;
            case 'backup':
                $this->handleBackup($argv);
                break;
            case 'destination':
                $this->handleDestination($argv);
                break;
            case 'schedule':
                $this->handleSchedule($argv);
                break;
            case 'log':
                $this->handleLog($argv);
                break;
            case 'status':
                $this->showStatus();
                break;
            default:
                echo "Unknown command: {$command}\n";
                $this->showHelp();
        }
    }
    
    private function handleJob($args) {
        $action = array_shift($args) ?? 'list';
        
        switch ($action) {
            case 'list':
                $this->listJobs();
                break;
            case 'run':
                $jobId = array_shift($args);
                if (!$jobId) {
                    echo "Usage: rcloneCWP job run <job_id>\n";
                    return;
                }
                $this->runJob($jobId);
                break;
            case 'show':
                $jobId = array_shift($args);
                if (!$jobId) {
                    echo "Usage: rcloneCWP job show <job_id>\n";
                    return;
                }
                $this->showJob($jobId);
                break;
            default:
                echo "Unknown job action: {$action}\n";
        }
    }
    
    private function listJobs() {
        $jobs = $this->db->fetchAll(
            "SELECT j.*, 
                    GROUP_CONCAT(d.name) as destinations
             FROM rclone_jobs j
             LEFT JOIN rclone_destinations d ON FIND_IN_SET(d.id, j.destinations)
             GROUP BY j.id
             ORDER BY j.name"
        );
        
        printf("%-5s %-30s %-15s %-10s %-10s\n", "ID", "Name", "Type", "Enabled", "Last Run");
        echo str_repeat("-", 75) . "\n";
        
        foreach ($jobs as $job) {
            printf(
                "%-5d %-30s %-15s %-10s %-10s\n",
                $job['id'],
                substr($job['name'], 0, 30),
                $job['backup_type'],
                $job['enabled'] ? 'Yes' : 'No',
                $job['last_run'] ?? 'Never'
            );
        }
    }
    
    private function runJob($jobId) {
        echo "Running backup job {$jobId}...\n";
        
        try {
            $engine = new BackupEngine();
            $result = $engine->execute($jobId);
            
            if ($result['status'] === 'success') {
                echo "Backup completed successfully!\n";
            } else {
                echo "Backup failed: " . ($result['message'] ?? 'Unknown error') . "\n";
            }
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function showStatus() {
        $stats = $this->db->fetch(
            "SELECT 
                (SELECT COUNT(*) FROM rclone_destinations WHERE enabled = 1) as destinations,
                (SELECT COUNT(*) FROM rclone_jobs WHERE enabled = 1) as jobs,
                (SELECT COUNT(*) FROM rcloneCWPs WHERE status = 'completed') as backups,
                (SELECT SUM(size_bytes) FROM rcloneCWPs WHERE status = 'completed') as total_size,
                (SELECT COUNT(*) FROM rcloneCWPs WHERE status = 'running') as running"
        );
        
        echo "=== rcloneCWP Status ===\n\n";
        echo "Active Destinations: {$stats['destinations']}\n";
        echo "Active Jobs: {$stats['jobs']}\n";
        echo "Completed Backups: {$stats['backups']}\n";
        echo "Running Backups: {$stats['running']}\n";
        echo "Total Size: " . $this->formatBytes($stats['total_size'] ?? 0) . "\n";
        
        echo "\n";
        echo "rclone version: " . (new Rclone())->version() . "\n";
    }
    
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
```

---

## 12. Testing

### 12.1 PHPUnit Configuration

```xml
<!-- tests/phpunit.xml -->
<phpunit bootstrap="bootstrap.php">
    <testsuites>
        <testsuite name="Unit">
            <directory>unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### 12.2 Unit Test Example

```php
<?php
// tests/unit/RcloneTest.php

namespace CWP\RcloneModule\Tests;

use PHPUnit\Framework\TestCase;
use CWP\RcloneModule\Rclone;

class RcloneTest extends TestCase {
    private $rclone;
    
    protected function setUp(): void {
        $this->rclone = new Rclone();
    }
    
    public function testVersion() {
        $version = $this->rclone->version();
        $this->assertStringContainsString('rclone', $version);
    }
    
    public function testExecuteAllowedCommand() {
        $result = $this->rclone->execute('lsd', ['/tmp']);
        $this->assertTrue($result['success']);
    }
    
    public function testExecuteDisallowedCommand() {
        $this->expectException(\Exception::class);
        $this->rclone->execute('format', ['/dev/sda']);
    }
    
    public function testCopyLocalToLocal() {
        // Create test files
        $source = sys_get_temp_dir() . '/rclone_test_source';
        $dest = sys_get_temp_dir() . '/rclone_test_dest';
        
        mkdir($source);
        file_put_contents("{$source}/test.txt", "Hello World");
        
        $result = $this->rclone->copy("{$source}/test.txt", $dest);
        
        $this->assertTrue($result['success']);
        $this->assertFileExists("{$dest}/test.txt");
        
        // Cleanup
        unlink("{$source}/test.txt");
        rmdir($source);
        unlink("{$dest}/test.txt");
        rmdir($dest);
    }
}
```

### 12.3 Integration Test Example

```php
<?php
// tests/integration/BackupFlowTest.php

namespace CWP\RcloneModule\Tests;

use PHPUnit\Framework\TestCase;
use CWP\RcloneModule\BackupEngine;
use CWP\RcloneModule\Database;

class BackupFlowTest extends TestCase {
    private $db;
    private $engine;
    
    protected function setUp(): void {
        $this->db = Database::getInstance();
        $this->engine = new BackupEngine();
        
        // Setup test data
        $this->setupTestData();
    }
    
    protected function tearDown(): void {
        // Cleanup test data
        $this->cleanupTestData();
    }
    
    private function setupTestData() {
        // Create test destination
        $this->destinationId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Local',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/rclone_test_backups']),
            'enabled' => 1
        ]);
        
        // Create test job
        $this->jobId = $this->db->insert('rclone_jobs', [
            'name' => 'Test Job',
            'destinations' => $this->destinationId,
            'users' => json_encode(['testuser']),
            'components' => json_encode(['files']),
            'backup_type' => 'full',
            'compression' => 'gzip',
            'enabled' => 1
        ]);
        
        // Create test user directory
        mkdir('/tmp/rclone_test_user', 0755, true);
        file_put_contents('/tmp/rclone_test_user/testfile.txt', 'test');
    }
    
    private function cleanupTestData() {
        // Remove test data
        $this->db->delete('rclone_destinations', ['id' => $this->destinationId]);
        $this->db->delete('rclone_jobs', ['id' => $this->jobId]);
        
        // Remove test files
        @unlink('/tmp/rclone_test_user/testfile.txt');
        @rmdir('/tmp/rclone_test_user');
        @rmdir('/tmp/rclone_test_backups');
    }
    
    public function testFullBackupFlow() {
        $result = $this->engine->execute($this->jobId);
        
        $this->assertEquals('success', $result['status']);
        $this->assertNotEmpty($result['results']);
        
        // Verify backup record exists
        $backup = $this->db->fetch(
            "SELECT * FROM rcloneCWPs WHERE job_id = ? ORDER BY id DESC LIMIT 1",
            [$this->jobId]
        );
        
        $this->assertNotEmpty($backup);
        $this->assertEquals('completed', $backup['status']);
    }
}
```

---

## 13. Debugging

### 13.1 Enable Debug Mode

```php
<?php
// config.php

define('DEBUG_MODE', true);
define('LOG_LEVEL', 'debug');
```

### 13.2 Log File Locations

| File | Description |
|------|-------------|
| `/var/log/rcloneCWP_cron.log` | Cron output |
| `/var/log/rcloneCWP/module.log` | Module debug log |
| `/var/log/rcloneCWP/error.log` | Error log |
| `/var/log/rclone/rclone.log` | rclone internal log |

### 13.3 Debugging Tips

```bash
# Watch cron logs in real-time
tail -f /var/log/rcloneCWP_cron.log

# Watch rclone logs
tail -f /var/log/rclone/rclone.log

# Check PHP errors
tail -f /var/log/php_errors.log

# Test rclone manually
rclone --config /etc/rclone/rclone.conf lsd gdrive:

# Test module CLI
php /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/cli/rcloneCWP status

# Check database
mysql -u root root_cwp -e "SELECT * FROM rcloneCWPs ORDER BY id DESC LIMIT 10;"
```

---

## 14. Common Patterns

### 14.1 Form Processing

```php
<?php
// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed');
    }
    
    // Validate input
    $name = Validator::string($_POST['name'] ?? '');
    $type = Validator::string($_POST['type'] ?? '');
    
    // Build config from form fields
    $config = [];
    $dest = DestinationFactory::create($type);
    $fields = $dest->getConfigFields();
    
    foreach ($fields as $field) {
        $fieldName = $field['name'];
        $config[$fieldName] = Validator::string($_POST[$fieldName] ?? '');
    }
    
    // Save to database
    $id = $db->insert('rclone_destinations', [
        'name' => $name,
        'type' => $type,
        'config' => json_encode($config),
        'enabled' => 1,
    ]);
    
    // Redirect with success message
    header("Location: /index.php?module=rcloneCWP&action=destinations&msg=created");
    exit;
}
```

### 14.2 AJAX Endpoint

```php
<?php
// AJAX handler for progress tracking
if (isset($_GET['action']) && $_GET['action'] === 'ajax_progress') {
    header('Content-Type: application/json');
    
    $backupId = intval($_GET['backup_id'] ?? 0);
    
    if (!$backupId) {
        echo json_encode(['error' => 'Invalid backup ID']);
        exit;
    }
    
    $backup = $db->fetch(
        "SELECT progress, status, message FROM rcloneCWPs WHERE id = ?",
        [$backupId]
    );
    
    if (!$backup) {
        echo json_encode(['error' => 'Backup not found']);
        exit;
    }
    
    echo json_encode([
        'progress' => intval($backup['progress']),
        'status' => $backup['status'],
        'message' => $backup['message']
    ]);
    exit;
}
```

### 14.3 Pagination

```php
<?php
// Pagination helper
function paginate($total, $perPage, $currentPage) {
    $totalPages = ceil($total / $perPage);
    $prevPage = max(1, $currentPage - 1);
    $nextPage = min($totalPages, $currentPage + 1);
    
    return [
        'total' => $total,
        'perPage' => $perPage,
        'currentPage' => $currentPage,
        'totalPages' => $totalPages,
        'prevPage' => $prevPage,
        'nextPage' => $nextPage,
    ];
}
```

---

## 15. Publishing & Distribution

### 15.1 GitHub Repository

1. Create repository: `rcloneCWP`
2. Add README.md
3. Add LICENSE (MIT)
4. Add CHANGELOG.md
5. Add CONTRIBUTING.md
6. Push code

### 15.2 Installation Package

```bash
# Create installation package
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/
tar -czf rcloneCWP-v1.0.0.tar.gz rcloneCWP/

# Create checksum
sha256sum rcloneCWP-v1.0.0.tar.gz > rcloneCWP-v1.0.0.tar.gz.sha256
```

### 15.3 Installation Script

```bash
#!/bin/bash
# install.sh - One-line installation

set -e

echo "=== rcloneCWP Installer ==="

# Check CWP
if [ ! -d "/usr/local/cwpsrv" ]; then
    echo "ERROR: CWP is not installed"
    exit 1
fi

# Check rclone
if ! command -v rclone &> /dev/null; then
    echo "Installing rclone..."
    curl https://rclone.org/install.sh | sudo bash
fi

# Download and extract
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/
curl -LO https://github.com/yourusername/rcloneCWP/releases/download/v1.0.0/rcloneCWP-v1.0.0.tar.gz
tar -xzf rcloneCWP-v1.0.0.tar.gz
rm rcloneCWP-v1.0.0.tar.gz

# Set permissions
chown -R root:root rcloneCWP/
find rcloneCWP/ -type f -exec chmod 644 {} \;
find rcloneCWP/ -type d -exec chmod 755 {} \;
chmod 700 rcloneCWP/logs rcloneCWP/cache

# Run installer
php rcloneCWP/install.php

# Add menu entry
if ! grep -q "Rclone Backup" /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php; then
    echo 'Rclone Backup|/index.php?module=rcloneCWP|fa fa-cloud|main' >> /usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php
fi

echo ""
echo "Installation complete!"
echo "Go to CWP Admin Panel → Rclone Backup to get started."
```

### 15.4 Update Script

```bash
#!/bin/bash
# update.sh

set -e

echo "=== Updating rcloneCWP ==="

cd /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/

# Pull latest
git pull origin master

# Run database updates
php install.php --upgrade

echo "Update complete!"
```

---

**End of Developer Guide**
