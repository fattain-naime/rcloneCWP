<?php
/**
 * rcloneCWP Module Entry Point
 *
 * The CWP dispatcher includes this file after setting $include_path.
 * Handles AJAX requests and renders the module user interface.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

// Primary guard: refuse if not loaded via CWP dispatcher
if (!isset($include_path)) {
    echo "invalid access";
    exit();
}

// Defense-in-depth: verify an admin session exists when one is trackable.
if (session_status() === PHP_SESSION_ACTIVE
    && !isset($_SESSION['lkey'])
    && !isset($_SESSION['cp'])
    && !isset($_SESSION['username'])
) {
    echo "authentication required";
    exit();
}

// Load the off-htdocs runtime bootstrap
$homeDir = '/usr/local/cwp/rcloneCWP';
if (!is_file($homeDir . '/bootstrap.php')) {
    echo "<h3>rcloneCWP runtime not found</h3>";
    echo "<p>Run the installer first:</p>";
    echo "<pre>curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash</pre>";
    exit();
}

require_once $homeDir . '/bootstrap.php';

use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Backup\CwpAccountDiscovery;
use CWP\RcloneCWP\CSRF;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Restore\RestoreEngine;
use CWP\RcloneCWP\Restore\SnapshotBrowser;
use CWP\RcloneCWP\Scheduling\CronParser;
use CWP\RcloneCWP\Scheduling\CrontabService;
use CWP\RcloneCWP\Scheduling\RetentionManager;
use CWP\RcloneCWP\Scheduling\ScheduleManager;
use CWP\RcloneCWP\Validator;

// ============================================================================
// AJAX DISPATCHER
// ============================================================================
if (!empty($_REQUEST['ajax'])) {
    // Clear any preceding output buffering from CWP dispatcher
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $db = Database::getInstance();
        $logger = new Logger(RCLONE_LOG_DIR, $db);
        $dm = new DestinationManager($db, null, $logger);
        $bjm = new BackupJobManager($db, $dm, $logger);
        $discovery = new CwpAccountDiscovery($db);
        $restoreEngine = new RestoreEngine($db, $dm, $logger);
        $snapshotBrowser = new SnapshotBrowser($db, $dm, $logger);
        $scheduleManager = new ScheduleManager($db);
        $retentionManager = new RetentionManager($db, $dm, $logger);
        $action = trim($_REQUEST['action'] ?? '');

        // CSRF validation helper for state-mutating actions
        $verifyCsrf = function () {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
            if (!CSRF::validateToken($token)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Invalid or expired CSRF security token. Please refresh the page.',
                ]);
                exit();
            }
        };

        // Authorization helper: rcloneCWP is a CWP root administrative module.
        // Explicitly deny non-admin callers if invoked outside an authenticated admin context.
        $requireAdmin = function () {
            // Ensure a session is started
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                session_start();
            }
            // Only allow users whose session marks them as admin (root administrator in CWP)
            $isAdmin = (
                (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ||
                (!empty($_SESSION['username']) && $_SESSION['username'] === 'root')
            );
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Access denied. Administrative privileges required.']);
                exit();
            }
        };

        switch ($action) {
            case 'get_types':
                $types = $dm->getAvailableTypes();
                echo json_encode(['ok' => true, 'types' => $types]);
                exit();

            case 'list_destinations':
                $destinations = $dm->listDestinations();
                $types = $dm->getAvailableTypes();
                $formatted = [];

                foreach ($destinations as $d) {
                    $type = $d['type'] ?? '';
                    $typeName = $types[$type]['name'] ?? strtoupper($type);
                    $config = $d['config'] ?? [];

                    // Generate target representation for table
                    $target = '';
                    try {
                        $target = $dm->getRcloneTarget($d['id'])['target'] ?? '';
                    } catch (\Exception $e) {
                        $target = $config['path'] ?? $config['bucket'] ?? $config['container'] ?? '';
                    }

                    $lastTested = $config['_last_tested'] ?? null;
                    $lastTestOk = isset($config['_last_test_ok']) ? (bool)$config['_last_test_ok'] : null;
                    $lastTestMsg = $config['_last_test_msg'] ?? '';

                    $formatted[] = [
                        'id'                => (int)$d['id'],
                        'name'              => $d['name'],
                        'type'              => $type,
                        'type_name'         => $typeName,
                        'remote_target'     => $target,
                        'enabled'           => (bool)$d['enabled'],
                        'last_tested'       => $lastTested,
                        'last_tested_short' => $lastTested ? date('M j, H:i', strtotime($lastTested)) : null,
                        'last_test_ok'      => $lastTestOk,
                        'last_test_msg'     => $lastTestMsg,
                        'created_at'        => $d['created_at'],
                    ];
                }

                echo json_encode(['ok' => true, 'destinations' => $formatted]);
                exit();

            case 'get_destination':
                $id = (int)($_REQUEST['id'] ?? 0);
                $dest = $dm->getDestination($id, false);
                if (!$dest) {
                    echo json_encode(['ok' => false, 'error' => 'Destination not found']);
                    exit();
                }

                // Mask sensitive fields
                $provider = $dm->getProvider($dest['type']);
                $sensitive = $provider->getSensitiveFields();
                foreach ($sensitive as $field) {
                    if (!empty($dest['config'][$field])) {
                        $dest['config'][$field] = '••••••••';
                    }
                }

                echo json_encode(['ok' => true, 'destination' => $dest]);
                exit();

            case 'save_destination':
                $verifyCsrf();
                $id = (int)($_POST['id'] ?? 0);
                $name = Validator::string($_POST['name'] ?? '', 100, 'name');
                $type = Validator::string($_POST['type'] ?? '', 50, 'type');
                $enabled = !empty($_POST['enabled']) ? 1 : 0;
                $config = isset($_POST['config']) && is_array($_POST['config']) ? $_POST['config'] : [];

                if (!$name) {
                    echo json_encode(['ok' => false, 'error' => 'Destination name is required (max 100 characters).']);
                    exit();
                }

                if ($id > 0) {
                    $result = $dm->updateDestination($id, [
                        'name'    => $name,
                        'enabled' => $enabled,
                        'config'  => $config,
                    ]);
                } else {
                    $result = $dm->createDestination([
                        'name'    => $name,
                        'type'    => $type,
                        'enabled' => $enabled,
                        'config'  => $config,
                    ]);
                }

                echo json_encode($result);
                exit();

            case 'test_destination':
                $verifyCsrf();
                $id = (int)($_REQUEST['id'] ?? 0);
                $start = microtime(true);
                $result = $dm->testDestination($id);
                $latencyMs = round((microtime(true) - $start) * 1000, 1);
                $result['latency_ms'] = $latencyMs;
                echo json_encode($result);
                exit();

            case 'test_raw_config':
                $verifyCsrf();
                $type = Validator::string($_POST['type'] ?? '', 50, 'type');
                $config = isset($_POST['config']) && is_array($_POST['config']) ? $_POST['config'] : [];
                $start = microtime(true);
                $result = $dm->testRawConfig($type, $config);
                $latencyMs = round((microtime(true) - $start) * 1000, 1);
                $result['latency_ms'] = $latencyMs;
                echo json_encode($result);
                exit();

            case 'delete_destination':
                $verifyCsrf();
                $id = (int)($_REQUEST['id'] ?? 0);
                $result = $dm->deleteDestination($id);
                echo json_encode($result);
                exit();

            case 'toggle_status':
                $verifyCsrf();
                $id = (int)($_REQUEST['id'] ?? 0);
                $enabled = !empty($_REQUEST['enabled']) ? 1 : 0;
                $result = $dm->toggleDestination($id, (bool)$enabled);
                echo json_encode($result);
                exit();

            // ================================================================
            // BACKUP JOBS & HISTORY ENDPOINTS
            // ================================================================
            case 'list_jobs':
                $jobs = $bjm->listJobs();
                echo json_encode(['ok' => true, 'jobs' => $jobs]);
                exit();

            case 'get_job':
                $id = (int)($_REQUEST['id'] ?? 0);
                $job = $bjm->getJob($id);
                if (!$job) {
                    echo json_encode(['ok' => false, 'error' => 'Backup job not found']);
                    exit();
                }
                echo json_encode(['ok' => true, 'job' => $job]);
                exit();

            case 'save_job':
                $verifyCsrf();
                $id = (int)($_POST['id'] ?? 0);
                $data = [
                    'name'              => $_POST['name'] ?? '',
                    'destination_id'    => (int)($_POST['destination_id'] ?? 0),
                    'job_type'          => $_POST['job_type'] ?? 'incremental',
                    'retention_days'    => (int)($_POST['retention_days'] ?? 7),
                    'compression'       => !empty($_POST['compression']) ? 1 : 0,
                    'notify_on_success' => !empty($_POST['notify_on_success']) ? 1 : 0,
                    'notify_on_failure' => !empty($_POST['notify_on_failure']) ? 1 : 0,
                    'accounts'          => isset($_POST['accounts']) && is_array($_POST['accounts']) ? $_POST['accounts'] : ['*'],
                    'components'        => isset($_POST['components']) && is_array($_POST['components']) ? $_POST['components'] : [],
                ];

                if ($id > 0) {
                    $result = $bjm->updateJob($id, $data);
                } else {
                    $result = $bjm->createJob($data);
                }
                echo json_encode($result);
                exit();

            case 'delete_job':
                $verifyCsrf();
                $id = (int)($_REQUEST['id'] ?? 0);
                $result = $bjm->deleteJob($id);
                echo json_encode($result);
                exit();

            case 'run_job_now':
                $verifyCsrf();
                $id = (int)($_REQUEST['id'] ?? 0);
                $result = $bjm->runJobNow($id);
                echo json_encode($result);
                exit();

            case 'list_accounts':
                $accounts = $discovery->listAccounts();
                $simpleAccounts = [];
                foreach ($accounts as $u => $a) {
                    $simpleAccounts[] = [
                        'username'       => $u,
                        'primary_domain' => $a['primary_domain'],
                        'databases'      => count($a['databases']),
                        'all_domains'    => count($a['all_domains']),
                    ];
                }
                echo json_encode(['ok' => true, 'accounts' => $simpleAccounts]);
                exit();

            case 'list_history':
                $jobId = (int)($_REQUEST['job_id'] ?? 0);
                $limit = (int)($_REQUEST['limit'] ?? 50);
                $history = $bjm->listHistory($jobId, $limit);
                echo json_encode(['ok' => true, 'history' => $history]);
                exit();

            // ================================================================
            // RESTORE ENGINE ENDPOINTS
            // ================================================================
            case 'list_snapshots':
                $destId = (int)($_REQUEST['destination_id'] ?? 0);
                if ($destId <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'A valid destination ID is required.']);
                    exit();
                }
                $snapshotsResult = $snapshotBrowser->listSnapshots($destId);
                echo json_encode($snapshotsResult);
                exit();

            case 'inspect_snapshot':
                $destId = (int)($_REQUEST['destination_id'] ?? 0);
                $path = Validator::string($_REQUEST['path'] ?? '', 1000, 'path');
                if ($destId <= 0 || !$path) {
                    echo json_encode(['ok' => false, 'error' => 'Destination ID and snapshot path are required.']);
                    exit();
                }
                $inspectResult = $snapshotBrowser->inspectSnapshot($destId, $path);
                echo json_encode($inspectResult);
                exit();

            case 'run_restore':
                $verifyCsrf();
                $destId = (int)($_POST['destination_id'] ?? 0);
                $path = Validator::string($_POST['path'] ?? '', 1000, 'path');
                $username = Validator::string($_POST['username'] ?? '', 100, 'username');
                $components = isset($_POST['components']) && is_array($_POST['components']) ? $_POST['components'] : [];
                $options = isset($_POST['options']) && is_array($_POST['options']) ? $_POST['options'] : [];

                if ($destId <= 0 || !$path || !$username) {
                    echo json_encode(['ok' => false, 'error' => 'Destination, snapshot path, and username are required.']);
                    exit();
                }

                $restoreResult = $restoreEngine->executeRestore($destId, $path, $username, $components, $options);
                echo json_encode($restoreResult);
                exit();

            case 'list_restore_history':
                $limit = max(1, min(100, (int)($_REQUEST['limit'] ?? 50)));
                $sql = "SELECT b.*, d.name AS destination_name, d.type AS destination_type " .
                       "FROM rclone_backups b " .
                       "LEFT JOIN rclone_destinations d ON b.destination_id = d.id " .
                       "WHERE b.backup_type = 'restore' " .
                       "ORDER BY b.id DESC LIMIT " . $limit;
                $restores = $db->fetchAll($sql);
                echo json_encode(['ok' => true, 'restores' => $restores]);
                exit();

            // ----------------------------------------------------------------
            // PHASE 5: SCHEDULING & RETENTION ACTIONS
            // ----------------------------------------------------------------
            case 'list_schedules':
                $requireAdmin();
                $schedules = $scheduleManager->listSchedules();
                echo json_encode(['ok' => true, 'schedules' => $schedules]);
                exit();

            case 'get_schedule':
                $requireAdmin();
                $schedId = (int)($_REQUEST['id'] ?? 0);
                if ($schedId <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Valid schedule ID is required.']);
                    exit();
                }
                $sched = $scheduleManager->getSchedule($schedId);
                if (!$sched) {
                    echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
                    exit();
                }
                echo json_encode(['ok' => true, 'schedule' => $sched]);
                exit();

            case 'save_schedule':
                $requireAdmin();
                $verifyCsrf();
                $id       = (int)($_POST['id'] ?? 0);
                $jobId    = (int)($_POST['job_id'] ?? 0);
                $cron     = trim($_POST['cron_expression'] ?? '');
                $timezone = trim($_POST['timezone'] ?? 'UTC');
                $active   = !empty($_POST['active']) ? 1 : 0;

                if (!CronParser::isValid($cron)) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid cron expression format.']);
                    exit();
                }

                if ($id > 0) {
                    $existing = $scheduleManager->getSchedule($id);
                    if (!$existing) {
                        echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
                        exit();
                    }
                    $saveRes = $scheduleManager->updateSchedule($id, [
                        'cron_expression' => $cron,
                        'timezone'        => $timezone,
                        'active'          => $active,
                    ]);
                } else {
                    if ($jobId <= 0 || !$bjm->getJob($jobId)) {
                        echo json_encode(['ok' => false, 'error' => 'Valid backup job ID is required.']);
                        exit();
                    }
                    $saveRes = $scheduleManager->createSchedule([
                        'job_id'          => $jobId,
                        'cron_expression' => $cron,
                        'timezone'        => $timezone,
                        'active'          => $active,
                    ]);
                }
                echo json_encode($saveRes);
                exit();

            case 'delete_schedule':
                $requireAdmin();
                $verifyCsrf();
                $schedId = (int)($_POST['id'] ?? 0);
                if ($schedId <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Valid schedule ID is required.']);
                    exit();
                }
                $existing = $scheduleManager->getSchedule($schedId);
                if (!$existing) {
                    echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
                    exit();
                }
                echo json_encode($scheduleManager->deleteSchedule($schedId));
                exit();

            case 'toggle_schedule':
                $requireAdmin();
                $verifyCsrf();
                $schedId = (int)($_POST['id'] ?? 0);
                if ($schedId <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Valid schedule ID is required.']);
                    exit();
                }
                $existing = $scheduleManager->getSchedule($schedId);
                if (!$existing) {
                    echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
                    exit();
                }
                $active  = !empty($_POST['active']);
                echo json_encode($scheduleManager->toggleActive($schedId, $active));
                exit();

            case 'preview_cron':
                $cron = trim($_REQUEST['cron_expression'] ?? '');
                $tz   = trim($_REQUEST['timezone'] ?? 'UTC');
                if (!CronParser::isValid($cron)) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid cron expression format.']);
                    exit();
                }
                try {
                    $nextRuns = CronParser::nextRuns($cron, 5, $tz);
                    $formatted = [];
                    foreach ($nextRuns as $dt) {
                        $formatted[] = $dt->format('Y-m-d H:i:s T');
                    }
                    echo json_encode(['ok' => true, 'next_runs' => $formatted]);
                } catch (\Exception $e) {
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                }
                exit();

            case 'prune_retention':
                $requireAdmin();
                $verifyCsrf();
                $jobId = (int)($_POST['job_id'] ?? 0);
                if ($jobId > 0 && !$bjm->getJob($jobId)) {
                    echo json_encode(['ok' => false, 'error' => 'Backup job not found.']);
                    exit();
                }
                $policy = Validator::enum($_POST['policy'] ?? 'days', ['days', 'count', 'gfs'], 'policy') ?: 'days';
                $retentionDays = !empty($_POST['retention_days']) ? max(1, min(3650, (int)$_POST['retention_days'])) : null;
                $keepCount = !empty($_POST['keep_count']) ? max(1, min(1000, (int)$_POST['keep_count'])) : null;
                $options = [
                    'policy'         => $policy,
                    'retention_days' => $retentionDays,
                    'keep_count'     => $keepCount,
                    'dry_run'        => !empty($_POST['dry_run']),
                ];
                $pruneRes = $bjm->pruneExpiredBackups($jobId, array_filter($options));
                echo json_encode($pruneRes);
                exit();

            case 'get_crontab_status':
                $requireAdmin();
                echo json_encode(['ok' => true, 'status' => CrontabService::getStatus()]);
                exit();

            case 'install_crontab':
                $requireAdmin();
                $verifyCsrf();
                echo json_encode(CrontabService::install());
                exit();

            case 'uninstall_crontab':
                $requireAdmin();
                $verifyCsrf();
                echo json_encode(CrontabService::uninstall());
                exit();

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
                exit();
        }
    } catch (\Exception $e) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Internal error: ' . $e->getMessage(),
        ]);
        exit();
    }
}

// ============================================================================
// HTML DASHBOARD PAGE RENDER
// ============================================================================
try {
    // Self-heal: ensure menu entry in 3rdparty.php is intact
    $installer = new \CWP\RcloneCWP\Installer(null, null);
    $installer->selfHealMenuEntry();

    // Render Master Tabbed Layout
    $layoutFile = defined('RCLONE_VIEWS_DIR') ? RCLONE_VIEWS_DIR . '/layout.php' : $homeDir . '/views/layout.php';
    if (file_exists($layoutFile)) {
        require $layoutFile;
    } else {
        echo "<div class=\"alert alert-danger\">Views layout not found at: " . htmlspecialchars($layoutFile) . "</div>";
    }
} catch (\Exception $e) {
    echo "<div class=\"alert alert-danger\"><strong>rcloneCWP Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}
