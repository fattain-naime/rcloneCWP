<?php
/**
 * rcloneCWP API v1 - Jobs Endpoint
 *
 * Handles backup job CRUD and execution.
 */

require_once __DIR__ . '/../../bootstrap.php';

use CWP\RcloneCWP\API;
use CWP\RcloneCWP\Backup\BackupEngine;
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

// Parse path: /api/v1/jobs/{id}/{action}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim(preg_replace('#^/api/v1/jobs#', '', $path), '/');
$segments = explode('/', $path);
$id = isset($segments[0]) && is_numeric($segments[0]) ? (int)$segments[0] : null;
$action = $segments[1] ?? '';

try {
    // POST /jobs/{id}/run
    if ($method === 'POST' && $id && $action === 'run') {
        $api->requirePermission('backup:run');
        $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
        // Whitelist run-override keys; only boolean flags are accepted.
        $allowedKeys = ['dry_run'];
        $input = [];
        foreach ($allowedKeys as $k) {
            if (array_key_exists($k, $rawInput)) {
                $input[$k] = (bool)$rawInput[$k];
            }
        }
        $engine = new BackupEngine($api->getDatabase(), $dm, $api->getLogger());
        $res = $engine->runJob($id, $input);
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
        exit(0);
    }

    // GET /jobs
    if ($method === 'GET' && !$id) {
        $api->requirePermission('job:list');
        $list = $bjm->listJobs();
        echo json_encode(['ok' => true, 'data' => $list], JSON_PRETTY_PRINT);
        exit(0);
    }

    // GET /jobs/{id}
    if ($method === 'GET' && $id) {
        $api->requirePermission('job:read');
        $job = $bjm->getJob($id);
        if (!$job) {
            throw new Exception("Job #{$id} not found", 404);
        }
        echo json_encode(['ok' => true, 'data' => $job], JSON_PRETTY_PRINT);
        exit(0);
    }

    // POST /jobs
    if ($method === 'POST' && !$id) {
        $api->requirePermission('job:create');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = $bjm->createJob($input);
        if (!($res['ok'] ?? false)) {
            throw new Exception($res['error'] ?? 'Failed to create job', 400);
        }
        http_response_code(201);
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
        exit(0);
    }

    // PUT /jobs/{id}
    if ($method === 'PUT' && $id) {
        $api->requirePermission('job:update');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = $bjm->updateJob($id, $input);
        if (!($res['ok'] ?? false)) {
            throw new Exception($res['error'] ?? 'Failed to update job', 400);
        }
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
        exit(0);
    }

    // DELETE /jobs/{id}
    if ($method === 'DELETE' && $id) {
        $api->requirePermission('job:delete');
        $res = $bjm->deleteJob($id);
        if (!($res['ok'] ?? false)) {
            throw new Exception($res['error'] ?? 'Failed to delete job', 400);
        }
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
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
