<?php
/**
 * rcloneCWP REST API Router
 *
 * Entry point for all API requests.
 * Loads bootstrap, initializes API, and handles the request.
 */

// Load bootstrap (config + autoloader)
require_once __DIR__ . '/../bootstrap.php';

// Verify PHP version
if (version_compare(PHP_VERSION, '7.1.0', '<')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => 'PHP 7.1+ required. Current: ' . PHP_VERSION,
    ], JSON_PRETTY_PRINT);
    exit(1);
}

// Ensure required directories exist
$dirs = [
    defined('RCLONE_LOG_DIR') ? RCLONE_LOG_DIR : '/var/log/rcloneCWP',
    defined('RCLONE_CACHE_DIR') ? RCLONE_CACHE_DIR : '/var/cache/rcloneCWP',
    defined('RCLONE_HOME') ? RCLONE_HOME . '/key.bin' : '/usr/local/cwp/rcloneCWP/key.bin',
];

foreach ([$dirs[0], $dirs[1]] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

use CWP\RcloneCWP\API;

try {
    // Initialize and handle the request
    $api = new API();
    $api->handleRequest();
} catch (Throwable $e) {
    // Last-resort error handling
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    error_log("rcloneCWP API fatal error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error',
        'code' => 500,
        'meta' => [
            'timestamp' => time(),
            'datetime' => date('c'),
        ],
    ], JSON_PRETTY_PRINT);
}