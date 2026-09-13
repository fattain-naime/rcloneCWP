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

// Define RCLONE_PATH – prefer bundled binary if present, else fallback to system binary, else empty string
if (defined('RCLONE_PATH') && RCLONE_PATH) {
    // already defined – keep
} elseif (defined('RCLONE_BINARY') && RCLONE_BINARY) {
    define('RCLONE_PATH', RCLONE_BINARY);
} else {
    define('RCLONE_PATH', '');
}

spl_autoload_register(function ($class) {
    $prefix = 'CWP\\RcloneCWP\\';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // Class doesn't use prefix
    }

    $relativeClass = substr($class, $len);
    $relativeFile = str_replace('\\', '/', $relativeClass) . '.php';

    // Primary: check directory relative to this bootstrap.php file
    $localFile = __DIR__ . '/lib/' . $relativeFile;
    if (file_exists($localFile)) {
        require $localFile;
        return;
    }

    // Secondary: check RCLONE_LIB_DIR if defined and different
    if (defined('RCLONE_LIB_DIR')) {
        $configuredFile = RCLONE_LIB_DIR . '/' . $relativeFile;
        if (file_exists($configuredFile)) {
            require $configuredFile;
            return;
        }
    }
});
