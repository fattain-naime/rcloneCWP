<?php
/**
 * rcloneCWP Uninstaller (CLI-only front-end)
 *
 * Deployed to /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP/uninstall.php
 * Refuses web execution (PHP_SAPI !== 'cli').
 * @package CWP\RcloneCWP
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Run from the shell.';
    exit(1);
}

echo "=== rcloneCWP Uninstaller ===\n\n";

// Deployed layout: this script lives in the off-htdocs home alongside
// bootstrap.php. In a dev checkout, the repo root has the same files.
$homeDir = __DIR__;

if (!is_file($homeDir . '/bootstrap.php')) {
    fwrite(STDERR, "ERROR: bootstrap.php not found in $homeDir\n");
    exit(1);
}

require_once $homeDir . '/bootstrap.php';

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Installer;

// --- Interactive prompts ---
function ask($question, $default = 'n')
{
    echo $question . " [" . $default . "]: ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    return $line === '' ? $default : strtolower($line);
}

$keepBackups = null;
$keepLogs = null;

$response = ask("Keep backup records? (y/n)", 'n');
$keepBackups = ($response === 'y');

$response = ask("Keep log entries? (y/n)", 'n');
$keepLogs = ($response === 'y');

echo "\n";

try {
    $db     = Database::getInstance();
    $logger = new Logger(RCLONE_LOG_DIR, $db);
    $inst   = new Installer($db, $logger);

    $result = $inst->uninstall($keepBackups, $keepLogs);

    echo "--- Steps ---\n";
    foreach ($result['steps'] as $step) {
        $status = '';
        if (is_array($step['result']) && isset($step['result']['ok'])) {
            $status = $step['result']['ok'] ? 'OK' : 'FAIL';
        } else {
            $status = 'DONE';
        }
        echo sprintf("  [%s] %s\n", $status, $step['step']);

        // Print dropped tables
        if ($step['step'] === 'Drop tables' && isset($step['result']['dropped'])) {
            foreach ($step['result']['dropped'] as $table) {
                echo sprintf("        - %s\n", $table);
            }
        }
    }

    echo "\n--- Result ---\n";
    echo ($result['success'] ? 'SUCCESS' : 'FAILURE') . ": {$result['message']}\n";
    echo "\nManual cleanup: remove " . RCLONE_MODULE_FILE . " and " . RCLONE_HOME . " if desired.\n";

    exit($result['success'] ? 0 : 1);
} catch (\Exception $e) {
    fwrite(STDERR, "\nFATAL: " . $e->getMessage() . "\n");
    exit(1);
}
