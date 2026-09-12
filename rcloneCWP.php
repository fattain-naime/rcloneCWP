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
