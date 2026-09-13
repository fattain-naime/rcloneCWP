<?php
/**
 * Test bootstrap for rcloneCWP
 *
 * Sets up the test environment, autoloading, and constants.
 */

// Define test mode
define('RCLONE_TEST_MODE', true);

// Define required constants for the module
define('RCLONE_VERSION', '1.0.0-test');
define('RCLONE_HOME', '/root/rcloneCWP');
define('RCLONE_MODULE_FILE', '/root/rcloneCWP/module.php');
define('RCLONE_LOG_DIR', '/root/rcloneCWP/tests/tmp/logs');
define('RCLONE_CACHE_DIR', '/root/rcloneCWP/tests/tmp/cache');
define('RCLONE_SQL_DIR', '/root/rcloneCWP/sql');
define('RCLONE_LIB_DIR', '/root/rcloneCWP/lib');

// Create temp directories
@mkdir(RCLONE_LOG_DIR, 0755, true);
@mkdir(RCLONE_CACHE_DIR, 0755, true);

// Mock CWP database connection for testing
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'root_cwp_test');

// Define mock classes BEFORE autoloader to prevent loading real classes
// Mock Database class for testing
if (!class_exists('CWP\RcloneCWP\Database')) {
    class CWP_RcloneCWP_Database {
        private static $instance = null;
        private $pdo = null;

        private function __construct() {
            // Use in-memory SQLite for tests
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->initSchema();
        }

        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function initSchema() {
            // Create minimal schema for testing
            $this->pdo->exec("
                CREATE TABLE rclone_destinations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    destination_id INTEGER NOT NULL,
                    source_type TEXT NOT NULL,
                    source_config TEXT,
                    schedule_id INTEGER,
                    retention_count INTEGER DEFAULT 7,
                    retention_days INTEGER DEFAULT 30,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_schedules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    cron_expression TEXT NOT NULL,
                    timezone TEXT DEFAULT 'UTC',
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_backups (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id INTEGER NOT NULL,
                    destination_id INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    snapshot_path TEXT,
                    size_bytes INTEGER DEFAULT 0,
                    duration_seconds INTEGER DEFAULT 0,
                    error_message TEXT,
                    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    completed_at DATETIME
                );
                CREATE TABLE rclone_hooks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    event TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    level TEXT NOT NULL,
                    message TEXT NOT NULL,
                    context TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_api_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    key_hash TEXT NOT NULL,
                    scopes TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME
                );
                CREATE TABLE rclone_notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        }

        public function getPdo() {
            return $this->pdo;
        }

        public function query($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }

        public function fetchAll($sql, $params = []) {
            return $this->query($sql, $params)->fetchAll();
        }

        public function fetchOne($sql, $params = []) {
            return $this->query($sql, $params)->fetch();
        }

        public function insert($table, $data) {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
            $this->query($sql, $data);
            return $this->pdo->lastInsertId();
        }

        public function update($table, $data, $where, $whereParams = []) {
            $set = implode(', ', array_map(function($k) { return "{$k} = :{$k}"; }, array_keys($data)));
            $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
            $params = array_merge($data, $whereParams);
            $this->query($sql, $params);
        }

        public function delete($table, $where, $params = []) {
            $sql = "DELETE FROM {$table} WHERE {$where}";
            $this->query($sql, $params);
        }

        public function lastInsertId() {
            return $this->pdo->lastInsertId();
        }

        public function beginTransaction() {
            $this->pdo->beginTransaction();
        }

        public function commit() {
            $this->pdo->commit();
        }

        public function rollBack() {
            $this->pdo->rollBack();
        }
    }
    class_alias('CWP_RcloneCWP_Database', 'CWP\RcloneCWP\Database');
}

// Mock Logger class for testing
if (!class_exists('CWP\RcloneCWP\Logger')) {
    class CWP_RcloneCWP_Logger {
        public function info($message, array $context = []) {}
        public function warning($message, array $context = []) {}
        public function error($message, array $context = []) {}
        public function debug($message, array $context = []) {}
        public function critical($message, array $context = []) {}
    }
    class_alias('CWP_RcloneCWP_Logger', 'CWP\RcloneCWP\Logger');
}

// Mock Encryption class for testing
if (!class_exists('CWP\RcloneCWP\Encryption')) {
    class CWP_RcloneCWP_Encryption {
        public static function hasKeyFile() {
            return false;
        }
        public static function encrypt($data) {
            return base64_encode($data);
        }
        public static function decrypt($data) {
            return base64_decode($data);
        }
    }
    class_alias('CWP_RcloneCWP_Encryption', 'CWP\RcloneCWP\Encryption');
}

// Mock Rclone class for testing
if (!class_exists('CWP\RcloneCWP\Rclone')) {
    class CWP_RcloneCWP_Rclone {
        public static function getBinaryPath() {
            return '/usr/bin/rclone';
        }
        public static function version() {
            return ['version' => '1.75.0'];
        }
    }
    class_alias('CWP_RcloneCWP_Rclone', 'CWP\RcloneCWP\Rclone');
}

// Mock Validator class for testing
if (!class_exists('CWP\RcloneCWP\Validator')) {
    class CWP_RcloneCWP_Validator {
        public static function validate($data, $rules) {
            return ['valid' => true, 'errors' => []];
        }
    }
    class_alias('CWP_RcloneCWP_Validator', 'CWP\RcloneCWP\Validator');
}

// Mock CSRF for testing
if (!class_exists('CWP\RcloneCWP\CSRF')) {
    class CWP_RcloneCWP_CSRF {
        public static function generateToken() {
            return 'test-csrf-token';
        }
        public static function verifyToken($token) {
            return true;
        }
    }
    class_alias('CWP_RcloneCWP_CSRF', 'CWP\RcloneCWP\CSRF');
}

// Mock CrontabService for testing
if (!class_exists('CWP\RcloneCWP\Scheduling\CrontabService')) {
    class CWP_RcloneCWP_Scheduling_CrontabService {
        public static function getStatus() {
            return ['installed' => false, 'entry' => ''];
        }
        public static function install() {
            return ['ok' => true];
        }
        public static function uninstall() {
            return ['ok' => true];
        }
    }
    class_alias('CWP_RcloneCWP_Scheduling_CrontabService', 'CWP\RcloneCWP\Scheduling\CrontabService');
}

// Mock ScheduleManager for testing
if (!class_exists('CWP\RcloneCWP\Scheduling\ScheduleManager')) {
    class CWP_RcloneCWP_Scheduling_ScheduleManager {
        private $db;
        public function __construct($db) {
            $this->db = $db;
        }
        public function listSchedules() {
            return [];
        }
    }
    class_alias('CWP_RcloneCWP_Scheduling_ScheduleManager', 'CWP\RcloneCWP\Scheduling\ScheduleManager');
}

// Mock DestinationManager for testing
if (!class_exists('CWP\RcloneCWP\Destinations\DestinationManager')) {
    class CWP_RcloneCWP_Destinations_DestinationManager {
        private $db;
        private $encryption;
        private $logger;
        public function __construct($db, $encryption = null, $logger = null) {
            $this->db = $db;
            $this->encryption = $encryption;
            $this->logger = $logger;
        }
        public function listDestinations() {
            return [];
        }
    }
    class_alias('CWP_RcloneCWP_Destinations_DestinationManager', 'CWP\RcloneCWP\Destinations\DestinationManager');
}

// Mock BackupJobManager for testing
if (!class_exists('CWP\RcloneCWP\Backup\BackupJobManager')) {
    class CWP_RcloneCWP_Backup_BackupJobManager {
        private $db;
        private $dm;
        private $logger;
        public function __construct($db, $dm, $logger) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
        }
        public function listJobs() {
            return [];
        }
    }
    class_alias('CWP_RcloneCWP_Backup_BackupJobManager', 'CWP\RcloneCWP\Backup\BackupJobManager');
}

// Mock BackupEngine for testing
if (!class_exists('CWP\RcloneCWP\Backup\BackupEngine')) {
    class CWP_RcloneCWP_Backup_BackupEngine {
        private $db;
        private $dm;
        private $logger;
        public function __construct($db, $dm, $logger) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
        }
        public function runJob($jobId, $options = []) {
            return ['ok' => true, 'backup_id' => 1];
        }
    }
    class_alias('CWP_RcloneCWP_Backup_BackupEngine', 'CWP\RcloneCWP\Backup\BackupEngine');
}

// Mock RestoreEngine for testing
if (!class_exists('CWP\RcloneCWP\Restore\RestoreEngine')) {
    class CWP_RcloneCWP_Restore_RestoreEngine {
        private $db;
        private $dm;
        private $logger;
        private $browser;
        public function __construct($db, $dm, $logger, $browser) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
            $this->browser = $browser;
        }
    }
    class_alias('CWP_RcloneCWP_Restore_RestoreEngine', 'CWP\RcloneCWP\Restore\RestoreEngine');
}

// Mock SnapshotBrowser for testing
if (!class_exists('CWP\RcloneCWP\Restore\SnapshotBrowser')) {
    class CWP_RcloneCWP_Restore_SnapshotBrowser {
        private $db;
        private $dm;
        private $logger;
        public function __construct($db, $dm, $logger) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
        }
    }
    class_alias('CWP_RcloneCWP_Restore_SnapshotBrowser', 'CWP\RcloneCWP\Restore\SnapshotBrowser');
}

// Mock HookEngine for testing
if (!class_exists('CWP\RcloneCWP\Hooks\HookEngine')) {
    class CWP_RcloneCWP_Hooks_HookEngine {
        private $db;
        private $logger;
        public function __construct($db, $logger) {
            $this->db = $db;
            $this->logger = $logger;
        }
    }
    class_alias('CWP_RcloneCWP_Hooks_HookEngine', 'CWP\RcloneCWP\Hooks\HookEngine');
}

// Mock NotificationEngine for testing
if (!class_exists('CWP\RcloneCWP\Notifications\NotificationEngine')) {
    class CWP_RcloneCWP_Notifications_NotificationEngine {
        private $db;
        private $logger;
        public function __construct($db, $logger) {
            $this->db = $db;
            $this->logger = $logger;
        }
    }
    class_alias('CWP_RcloneCWP_Notifications_NotificationEngine', 'CWP\RcloneCWP\Notifications\NotificationEngine');
}

// Mock API class for testing
if (!class_exists('CWP\RcloneCWP\API')) {
    class CWP_RcloneCWP_API {
        private $db;
        private $logger;
        public function __construct() {
            $this->db = \CWP\RcloneCWP\Database::getInstance();
            $this->logger = new \CWP\RcloneCWP\Logger();
        }
        public function getDatabase() {
            return $this->db;
        }
        public function getLogger() {
            return $this->logger;
        }
        public function authenticate() {
            return ['ok' => true];
        }
        public function checkRateLimit() {
            return ['ok' => true];
        }
        public function requirePermission($perm) {}
    }
    class_alias('CWP_RcloneCWP_API', 'CWP\RcloneCWP\API');
}

// Autoloader for the library (only loads classes not already defined)
spl_autoload_register(function ($class) {
    $prefix = 'CWP\\RcloneCWP\\';
    $baseDir = RCLONE_LIB_DIR . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Mock functions that CWP provides
if (!function_exists('cwp_get_user')) {
    function cwp_get_user() {
        return 'testuser';
    }
}

if (!function_exists('cwp_get_domain')) {
    function cwp_get_domain() {
        return 'test.example.com';
    }
}

// Mock CSRF for testing
if (!class_exists('CWP\RcloneCWP\CSRF')) {
    class CWP_RcloneCWP_CSRF {
        public static function generateToken() {
            return 'test-csrf-token';
        }
        public static function verifyToken($token) {
            return true;
        }
    }
    class_alias('CWP_RcloneCWP_CSRF', 'CWP\RcloneCWP\CSRF');
}

// Mock Database class for testing if not loaded
if (!class_exists('CWP\RcloneCWP\Database')) {
    class CWP_RcloneCWP_Database {
        private static $instance = null;
        private $pdo = null;

        private function __construct() {
            // Use in-memory SQLite for tests
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->initSchema();
        }

        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function initSchema() {
            // Create minimal schema for testing
            $this->pdo->exec("
                CREATE TABLE rclone_destinations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    destination_id INTEGER NOT NULL,
                    source_type TEXT NOT NULL,
                    source_config TEXT,
                    schedule_id INTEGER,
                    retention_count INTEGER DEFAULT 7,
                    retention_days INTEGER DEFAULT 30,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_schedules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    cron_expression TEXT NOT NULL,
                    timezone TEXT DEFAULT 'UTC',
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_backups (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id INTEGER NOT NULL,
                    destination_id INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    snapshot_path TEXT,
                    size_bytes INTEGER DEFAULT 0,
                    duration_seconds INTEGER DEFAULT 0,
                    error_message TEXT,
                    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    completed_at DATETIME
                );
                CREATE TABLE rclone_hooks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    event TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    level TEXT NOT NULL,
                    message TEXT NOT NULL,
                    context TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE rclone_api_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    key_hash TEXT NOT NULL,
                    scopes TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME
                );
                CREATE TABLE rclone_notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT,
                    active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        }

        public function getPdo() {
            return $this->pdo;
        }

        public function query($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }

        public function fetchAll($sql, $params = []) {
            return $this->query($sql, $params)->fetchAll();
        }

        public function fetchOne($sql, $params = []) {
            return $this->query($sql, $params)->fetch();
        }

        public function insert($table, $data) {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
            $this->query($sql, $data);
            return $this->pdo->lastInsertId();
        }

        public function update($table, $data, $where, $whereParams = []) {
            $set = implode(', ', array_map(function($k) { return "{$k} = :{$k}"; }, array_keys($data)));
            $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
            $params = array_merge($data, $whereParams);
            $this->query($sql, $params);
        }

        public function delete($table, $where, $params = []) {
            $sql = "DELETE FROM {$table} WHERE {$where}";
            $this->query($sql, $params);
        }

        public function lastInsertId() {
            return $this->pdo->lastInsertId();
        }

        public function beginTransaction() {
            $this->pdo->beginTransaction();
        }

        public function commit() {
            $this->pdo->commit();
        }

        public function rollBack() {
            $this->pdo->rollBack();
        }
    }
    class_alias('CWP_RcloneCWP_Database', 'CWP\RcloneCWP\Database');
}

// Mock Logger class for testing
if (!class_exists('CWP\RcloneCWP\Logger')) {
    class CWP_RcloneCWP_Logger {
        public function info($message, array $context = []) {}
        public function warning($message, array $context = []) {}
        public function error($message, array $context = []) {}
        public function debug($message, array $context = []) {}
        public function critical($message, array $context = []) {}
    }
    class_alias('CWP_RcloneCWP_Logger', 'CWP\RcloneCWP\Logger');
}

// Mock Encryption class for testing
if (!class_exists('CWP\RcloneCWP\Encryption')) {
    class CWP_RcloneCWP_Encryption {
        public static function hasKeyFile() {
            return false;
        }
        public static function encrypt($data) {
            return base64_encode($data);
        }
        public static function decrypt($data) {
            return base64_decode($data);
        }
    }
    class_alias('CWP_RcloneCWP_Encryption', 'CWP\RcloneCWP\Encryption');
}

// Mock Rclone class for testing
if (!class_exists('CWP\RcloneCWP\Rclone')) {
    class CWP_RcloneCWP_Rclone {
        public static function getBinaryPath() {
            return '/usr/bin/rclone';
        }
        public static function version() {
            return ['version' => '1.75.0'];
        }
    }
    class_alias('CWP_RcloneCWP_Rclone', 'CWP\RcloneCWP\Rclone');
}

// Mock Validator class for testing
if (!class_exists('CWP\RcloneCWP\Validator')) {
    class CWP_RcloneCWP_Validator {
        public static function validate($data, $rules) {
            return ['valid' => true, 'errors' => []];
        }
    }
    class_alias('CWP_RcloneCWP_Validator', 'CWP\RcloneCWP\Validator');
}

// Mock CrontabService for testing
if (!class_exists('CWP\RcloneCWP\Scheduling\CrontabService')) {
    class CWP_RcloneCWP_Scheduling_CrontabService {
        public static function getStatus() {
            return ['installed' => false, 'entry' => ''];
        }
        public static function install() {
            return ['ok' => true];
        }
        public static function uninstall() {
            return ['ok' => true];
        }
    }
    class_alias('CWP_RcloneCWP_Scheduling_CrontabService', 'CWP\RcloneCWP\Scheduling\CrontabService');
}

// Mock ScheduleManager for testing
if (!class_exists('CWP\RcloneCWP\Scheduling\ScheduleManager')) {
    class CWP_RcloneCWP_Scheduling_ScheduleManager {
        private $db;
        public function __construct($db) {
            $this->db = $db;
        }
        public function listSchedules() {
            return [];
        }
    }
    class_alias('CWP_RcloneCWP_Scheduling_ScheduleManager', 'CWP\RcloneCWP\Scheduling\ScheduleManager');
}

// Mock DestinationManager for testing
if (!class_exists('CWP\RcloneCWP\Destinations\DestinationManager')) {
    class CWP_RcloneCWP_Destinations_DestinationManager {
        private $db;
        private $encryption;
        private $logger;
        public function __construct($db, $encryption = null, $logger = null) {
            $this->db = $db;
            $this->encryption = $encryption;
            $this->logger = $logger;
        }
        public function listDestinations() {
            return [];
        }
    }
    class_alias('CWP_RcloneCWP_Destinations_DestinationManager', 'CWP\RcloneCWP\Destinations\DestinationManager');
}

// Mock BackupJobManager for testing
if (!class_exists('CWP\RcloneCWP\Backup\BackupJobManager')) {
    class CWP_RcloneCWP_Backup_BackupJobManager {
        private $db;
        private $dm;
        private $logger;
        public function __construct($db, $dm, $logger) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
        }
        public function listJobs() {
            return [];
        }
    }
    class_alias('CWP_RcloneCWP_Backup_BackupJobManager', 'CWP\RcloneCWP\Backup\BackupJobManager');
}

// Mock BackupEngine for testing
if (!class_exists('CWP\RcloneCWP\Backup\BackupEngine')) {
    class CWP_RcloneCWP_Backup_BackupEngine {
        private $db;
        private $dm;
        private $logger;
        public function __construct($db, $dm, $logger) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
        }
        public function runJob($jobId, $options = []) {
            return ['ok' => true, 'backup_id' => 1];
        }
    }
    class_alias('CWP_RcloneCWP_Backup_BackupEngine', 'CWP\RcloneCWP\Backup\BackupEngine');
}

// Mock RestoreEngine for testing
if (!class_exists('CWP\RcloneCWP\Restore\RestoreEngine')) {
    class CWP_RcloneCWP_Restore_RestoreEngine {
        private $db;
        private $dm;
        private $logger;
        private $browser;
        public function __construct($db, $dm, $logger, $browser) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
            $this->browser = $browser;
        }
    }
    class_alias('CWP_RcloneCWP_Restore_RestoreEngine', 'CWP\RcloneCWP\Restore\RestoreEngine');
}

// Mock SnapshotBrowser for testing
if (!class_exists('CWP\RcloneCWP\Restore\SnapshotBrowser')) {
    class CWP_RcloneCWP_Restore_SnapshotBrowser {
        private $db;
        private $dm;
        private $logger;
        public function __construct($db, $dm, $logger) {
            $this->db = $db;
            $this->dm = $dm;
            $this->logger = $logger;
        }
    }
    class_alias('CWP_RcloneCWP_Restore_SnapshotBrowser', 'CWP\RcloneCWP\Restore\SnapshotBrowser');
}

// Mock HookEngine for testing
if (!class_exists('CWP\RcloneCWP\Hooks\HookEngine')) {
    class CWP_RcloneCWP_Hooks_HookEngine {
        private $db;
        private $logger;
        public function __construct($db, $logger) {
            $this->db = $db;
            $this->logger = $logger;
        }
    }
    class_alias('CWP_RcloneCWP_Hooks_HookEngine', 'CWP\RcloneCWP\Hooks\HookEngine');
}

// Mock NotificationEngine for testing
if (!class_exists('CWP\RcloneCWP\Notifications\NotificationEngine')) {
    class CWP_RcloneCWP_Notifications_NotificationEngine {
        private $db;
        private $logger;
        public function __construct($db, $logger) {
            $this->db = $db;
            $this->logger = $logger;
        }
    }
    class_alias('CWP_RcloneCWP_Notifications_NotificationEngine', 'CWP\RcloneCWP\Notifications\NotificationEngine');
}

// Mock API class for testing
if (!class_exists('CWP\RcloneCWP\API')) {
    class CWP_RcloneCWP_API {
        private $db;
        private $logger;
        public function __construct() {
            $this->db = \CWP\RcloneCWP\Database::getInstance();
            $this->logger = new \CWP\RcloneCWP\Logger();
        }
        public function getDatabase() {
            return $this->db;
        }
        public function getLogger() {
            return $this->logger;
        }
        public function authenticate() {
            return ['ok' => true];
        }
        public function checkRateLimit() {
            return ['ok' => true];
        }
        public function requirePermission($perm) {}
    }
    class_alias('CWP_RcloneCWP_API', 'CWP\RcloneCWP\API');
}

// Helper functions for tests
function createTestDestination($name = 'Test Destination', $type = 'local') {
    $db = \CWP\RcloneCWP\Database::getInstance();
    return $db->insert('rclone_destinations', [
        'name' => $name,
        'type' => $type,
        'config' => json_encode(['path' => '/tmp/test']),
        'active' => 1,
    ]);
}

function createTestJob($name = 'Test Job', $destinationId = 1) {
    $db = \CWP\RcloneCWP\Database::getInstance();
    return $db->insert('rclone_jobs', [
        'name' => $name,
        'destination_id' => $destinationId,
        'source_type' => 'files',
        'source_config' => json_encode(['paths' => ['/home/test']]),
        'retention_count' => 7,
        'retention_days' => 30,
        'active' => 1,
    ]);
}

function createTestSchedule($name = 'Test Schedule', $cron = '0 2 * * *') {
    $db = \CWP\RcloneCWP\Database::getInstance();
    return $db->insert('rclone_schedules', [
        'name' => $name,
        'cron_expression' => $cron,
        'timezone' => 'UTC',
        'active' => 1,
    ]);
}