<?php
/**
 * rcloneCWP API v1 - Destinations Endpoint
 *
 * Handles destination CRUD and connectivity testing.
 */

require_once __DIR__ . '/../../bootstrap.php';

use CWP\RcloneCWP\API;
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
$method = $_SERVER['REQUEST_METHOD'];

// Parse path: /api/v1/destinations/{id}/{action}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim(preg_replace('#^/api/v1/destinations#', '', $path), '/');
$segments = explode('/', $path);
$id = isset($segments[0]) && is_numeric($segments[0]) ? (int)$segments[0] : null;
$action = $segments[1] ?? '';

try {
    // POST /destinations/{id}/test
    if ($method === 'POST' && $id && $action === 'test') {
        $api->requirePermission('destination:test');
        $res = $dm->testDestination($id);
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
        exit(0);
    }

    // GET /destinations
    if ($method === 'GET' && !$id) {
        $api->requirePermission('destination:list');
        $list = $dm->listDestinations();
        echo json_encode(['ok' => true, 'data' => $list], JSON_PRETTY_PRINT);
        exit(0);
    }

    // GET /destinations/{id}
    if ($method === 'GET' && $id) {
        $api->requirePermission('destination:read');
        $dest = $dm->getDestination($id);
        if (!$dest) {
            throw new Exception("Destination #{$id} not found", 404);
        }
        echo json_encode(['ok' => true, 'data' => $dest], JSON_PRETTY_PRINT);
        exit(0);
    }

    // POST /destinations
    if ($method === 'POST' && !$id) {
        $api->requirePermission('destination:create');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = $dm->createDestination($input);
        if (!($res['ok'] ?? false)) {
            throw new Exception($res['error'] ?? 'Failed to create destination', 400);
        }
        http_response_code(201);
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
        exit(0);
    }

    // PUT /destinations/{id}
    if ($method === 'PUT' && $id) {
        $api->requirePermission('destination:update');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = $dm->updateDestination($id, $input);
        if (!($res['ok'] ?? false)) {
            throw new Exception($res['error'] ?? 'Failed to update destination', 400);
        }
        echo json_encode(['ok' => true, 'data' => $res], JSON_PRETTY_PRINT);
        exit(0);
    }

    // DELETE /destinations/{id}
    if ($method === 'DELETE' && $id) {
        $api->requirePermission('destination:delete');
        $res = $dm->deleteDestination($id);
        if (!($res['ok'] ?? false)) {
            throw new Exception($res['error'] ?? 'Failed to delete destination', 400);
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