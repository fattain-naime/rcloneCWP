<?php
/**
 * rcloneCWP Autoloader & Bootstrap
 *
 * Registers PSR-4 autoloader for CWP\RcloneCWP namespace.
 * Requires PHP 7.1+
 */

// Require config if not loaded
if (!defined('RCLONE_VERSION')) {
    $configPath = __DIR__ . '/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }
}

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'CWP\\RcloneCWP\\';
    $baseDir = defined('RCLONE_LIB_DIR') ? RCLONE_LIB_DIR . '/' : __DIR__ . '/lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // Class doesn't use prefix
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
