<?php
/**
 * rcloneCWP Configuration
 *
 * Contains runtime configuration, path probe chains, and constant definitions.
 * This is loaded BEFORE any other code.
 */

// Define version
define('RCLONE_VERSION', '1.0.0-alpha');

// ============================================================================
// RUNTIME PATH PROBES
// ============================================================================

/**
 * Probe chain for rclone binary location
 * Follows OS conventions: bundled binary first, then /usr/local/bin, /usr/bin, then PATH
 */
function rcloneFindBinary(): ?string
{
    // First check bundled binary location if RCLONE_HOME is defined
    if (defined('RCLONE_HOME')) {
        $bundled = rtrim(RCLONE_HOME, '/') . '/bin/rclone';
        if (is_file($bundled) && is_executable($bundled) && is_readable($bundled)) {
            return $bundled;
        }
    }

    // Then check system locations
    $binaries = ['/usr/local/bin/rclone', '/usr/bin/rclone', '/bin/rclone'];
    foreach ($binaries as $binary) {
        if (is_file($binary) && is_executable($binary) && is_readable($binary)) {
            return $binary;
        }
    }

    // Finally check PATH
    $pathDirs = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
    foreach ($pathDirs as $dir) {
        $candidate = rtrim($dir, '/') . '/rclone';
        if (is_file($candidate) && is_executable($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null; // Not found
}

// Define RCLONE_HOME first
if (!defined('RCLONE_HOME')) {
    define('RCLONE_HOME', rcloneGetHome());
}

// Bundle version constant – must be defined before binary probe for guard
if (!defined('RCLONE_BUNDLED_VERSION')) {
    define('RCLONE_BUNDLED_VERSION', 'v1.75.1');
}

// Binary probe – now sees RCLONE_HOME and version constant
if (!defined('RCLONE_BINARY')) {
    define('RCLONE_BINARY', rcloneFindBinary());
}

/**
 * Probe chain for off-htdocs home directory
 * Returns the first existing directory, or the primary path (to be created by installer)
 */
function rcloneGetHome(): string
{
    $paths = [
        '/usr/local/cwp/rcloneCWP',
        '/opt/cwp/rcloneCWP',
        $_SERVER['DOCUMENT_ROOT'] . '/../../rcloneCWP'  // Fallback within module
    ];

    foreach ($paths as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }
    // No existing directory found - return primary path (installer will create it)
    return $paths[0];
}

// Remove duplicate RCLONE_HOME definition
// define('RCLONE_HOME', rcloneGetHome());

/**
 * Probe chain for credentials
 * Real server: db_conn.php -> mysql_db.cnf -> /root/.my.cnf
 */
function rcloneGetCredentials(): array
{
    $db = [
        'host' => 'localhost',
        'name' => 'root_cwp',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASS') ?: '',
    ];

    // Try db_conn.php first (CWP standard)
    $dbConn = '/usr/local/cwpsrv/htdocs/resources/admin/include/db_conn.php';
    if (is_file($dbConn) && is_readable($dbConn)) {
        // Extract variables using eval-safe approach
        $content = file_get_contents($dbConn);
        if (preg_match('/\$db_host\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
            $db['host'] = $matches[1];
        }
        if (preg_match('/\$db_name\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
            $db['name'] = $matches[1];
        }
        if (preg_match('/\$db_user\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
            $db['user'] = $matches[1];
        }
        if (preg_match('/\$db_pass\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
            $db['pass'] = $matches[1];
        }
        return $db;
    }

    // Try mysql_db.cnf
    $cnf = '/usr/local/cwp/.conf/mysql_db.cnf';
    if (is_file($cnf) && is_readable($cnf)) {
        $lines = file($cnf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^db_host\s*=\s*(.+)$/', $line, $m)) {
                $db['host'] = trim($m[1], '"\'');
            }
            if (preg_match('/^db_name\s*=\s*(.+)$/', $line, $m)) {
                $db['name'] = trim($m[1], '"\'');
            }
            if (preg_match('/^db_user\s*=\s*(.+)$/', $line, $m)) {
                $db['user'] = trim($m[1], '"\'');
            }
            if (preg_match('/^db_pass\s*=\s*(.+)$/', $line, $m)) {
                $db['pass'] = trim($m[1], '"\'');
            }
        }
        return $db;
    }

    // Try /root/.my.cnf (last resort)
    $myCnf = '/root/.my.cnf';
    if (is_file($myCnf) && is_readable($myCnf)) {
        $lines = file($myCnf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^host\s*=\s*(.+)$/', $line, $m)) {
                $db['host'] = trim($m[1], '"\'');
            }
            if (preg_match('/^database\s*=\s*(.+)$/', $line, $m)) {
                $db['name'] = trim($m[1], '"\'');
            }
            if (preg_match('/^user\s*=\s*(.+)$/', $line, $m)) {
                $db['user'] = trim($m[1], '"\'');
            }
            if (preg_match('/^password\s*=\s*(.+)$/', $line, $m)) {
                $db['pass'] = trim($m[1], '"\'');
            }
        }
    }

    return $db;
}

define('RCLONE_DB', rcloneGetCredentials());

/**
 * Probe chain for CWP modules directory
 */
function rcloneGetModulesDir(): string
{
    $paths = [
        '/usr/local/cwpsrv/htdocs/resources/admin/modules',
        '/usr/local/cwpsrv/htdocs/modules',
        dirname(dirname($_SERVER['DOCUMENT_ROOT'])) . '/resources/admin/modules'
    ];

    foreach ($paths as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }
    return $paths[0];
}

define('RCLONE_MODULES_DIR', rcloneGetModulesDir());

// ============================================================================
// DIRECTORIES
// ============================================================================

// CWP dispatcher includes modules as flat files: modules/<name>.php
define('RCLONE_MODULE_FILE', RCLONE_MODULES_DIR . '/rcloneCWP.php');
define('RCLONE_LIB_DIR', RCLONE_HOME . '/lib');
define('RCLONE_SQL_DIR', RCLONE_HOME . '/sql');
define('RCLONE_VIEWS_DIR', RCLONE_HOME . '/views');
define('RCLONE_LOG_DIR', '/var/log/rcloneCWP');
define('RCLONE_CACHE_DIR', '/var/cache/rcloneCWP');

// ============================================================================
// ENCRYPTION KEYS
// ============================================================================

define('RCLONE_KEYFILE', RCLONE_HOME . '/key.bin');

/**
 * Get encryption key from file
 */
function rcloneGetEncryptionKey(): string
{
    $keyfile = RCLONE_KEYFILE;
    if (!is_file($keyfile) || !is_readable($keyfile)) {
        return '';
    }
    return file_get_contents($keyfile);
}

// ============================================================================
// DEBUG MODE
// ============================================================================

define('RCLONE_DEBUG', false);  // Set to true to enable debug logging

// ============================================================================
// UPGRADE NOTICES
// ============================================================================

define('RCLONE_UPGRADE_CHECK', true);  // Enable periodic upgrade checks

// ============================================================================
// END OF CONFIG
// ============================================================================
