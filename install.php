<?php
/**
 * rcloneCWP Installer (CLI-only front-end)
 *
 * Deployed to /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/install.php
 * Refuses web execution (PHP_SAPI !== 'cli').
 * @package CWP\RcloneCWP
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Run from the shell.';
    exit(1);
}

echo "=== rcloneCWP Installer ===\n\n";

// Deployed layout: this script lives in the off-htdocs home alongside
// bootstrap.php. In a dev checkout, the repo root has the same files.
$homeDir = __DIR__;

if (!is_file($homeDir . '/bootstrap.php')) {
    fwrite(STDERR, "ERROR: bootstrap.php not found in $homeDir\n");
    fwrite(STDERR, "Deploy the runtime tree (config.php, bootstrap.php, lib/, sql/) first.\n");
    exit(1);
}

require_once $homeDir . '/bootstrap.php';

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Installer;

try {
    $db     = Database::getInstance();
    $logger = new Logger(RCLONE_LOG_DIR, $db);
    $inst   = new Installer($db, $logger);

    $result = $inst->install();

    echo "--- Steps ---\n";
    foreach ($result['steps'] as $step) {
        $status = '';
        if (is_array($step['result']) && isset($step['result']['ok'])) {
            $status = $step['result']['ok'] ? 'OK' : 'FAIL';
        } else {
            $status = is_array($step['result']) ? 'DONE' : 'DONE';
        }
        echo sprintf("  [%s] %s\n", $status, $step['step']);

        // Print table names on schema step
        if ($step['step'] === 'Apply database schema' && isset($step['result']['tables'])) {
            echo sprintf("        Tables: %d (expected %d)\n", $step['result']['tables'], $step['result']['expected'] ?? 8);
        }

        // Print API key on seed step
        if ($step['step'] === 'Seed initial data' && isset($step['result']['seeded']['api_key'])) {
            echo sprintf("        API key: %s\n", $step['result']['seeded']['api_key']);
        }
    }

    echo "\n--- Result ---\n";
    echo ($result['success'] ? 'SUCCESS' : 'FAILURE') . ": {$result['message']}\n";

    if ($result['success']) {
        echo "\nAdmin URL: {$inst->getAdminUrl()}\n";
    }

    exit($result['success'] ? 0 : 1);
} catch (\Exception $e) {
    fwrite(STDERR, "\nFATAL: " . $e->getMessage() . "\n");
    exit(1);
}
