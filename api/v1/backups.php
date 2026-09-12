<?php
/**
 * rcloneCWP API v1 - Backups Endpoint
 *
 * Handles backup run history and detailed status inspection.
 */

require_once __DIR__ . '/../../bootstrap.php';

use CWP\RcloneCWP\API;
use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Destinations\DestinationManager;

$api = new API();

// Verify authentication
$auth = $api->authenticate();
if (!$auth['ok']) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $auth['error'], 'code' => 401], JSON_PRETTY_PRINT);
    exit(1);
}

// Check rate limit
$rateLimit = $api->checkRateLimit();
if (!$rateLimit['ok']) {
    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => $rateLimit['error'],
        'code' => 429,
        'meta' => ['retry_after' => $rateLimit['retry_after'] ?? 60]
    ], JSON_PRETTY_PRINT);
    exit(1);
}

$dm = new DestinationManager($api->getDatabase(), null, $api->getLogger());
$bjm = new BackupJobManager($api->getDatabase(), $dm, $api->getLogger());
$method = $_SERVER['REQUEST_METHOD'];

// Parse path: /api/v1/backups/{id}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim(preg_replace('#^/api/v1/backups#', '', $path), '/');
$id = !empty($path) && is_numeric($path) ? (int)$path : null;

try {
    // GET /backups
    if ($method === 'GET' && !$id) {
        $api->requirePermission('backup:list');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
        $history = $bjm->listHistory($jobId, $limit);
        echo json_encode(['ok' => true, 'data' => $history], JSON_PRETTY_PRINT);
        exit(0);
    }

    // GET /backups/{id}
    if ($method === 'GET' && $id) {
        $api->requirePermission('backup:read');
        $row = $api->getDatabase()->fetch(
            "SELECT b.*, j.name AS job_name, d.name AS destination_name, d.type AS destination_type
             FROM rclone_backups b
             LEFT JOIN rclone_jobs j ON b.job_id = j.id
             LEFT JOIN rclone_destinations d ON b.destination_id = d.id
             WHERE b.id = ?",
            [$id]
        );
        if (!$row) {
            throw new Exception("Backup record #{$id} not found", 404);
        }
        echo json_encode(['ok' => true, 'data' => $row], JSON_PRETTY_PRINT);
        exit(0);
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed', 'code' => 405], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'code' => $code,
    ], JSON_PRETTY_PRINT);
}
