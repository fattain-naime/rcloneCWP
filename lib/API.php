<?php
/**
 * rcloneCWP RESTful API Engine
 *
 * Provides a clean RESTful API for external automation, CI/CD, and CLI tools.
 * Supports Bearer token authentication via rclone_api_keys, fine-grained permission
 * scopes, rate limiting, CORS headers, and standard JSON response envelopes.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use CWP\RcloneCWP\Backup\BackupEngine;
use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Restore\RestoreEngine;
use CWP\RcloneCWP\Restore\SnapshotBrowser;
use CWP\RcloneCWP\Scheduling\ScheduleManager;
use Exception;

class API
{
    /**
     * @var Database
     */
    private $db;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array|null Currently authenticated API key record
     */
    private $currentApiKey = null;

    /**
     * @var array Decoded permissions for current key
     */
    private $currentPermissions = [];

    /**
     * @var int Rate limit window in seconds
     */
    private $rateLimitWindow = 60;

    /**
     * @var int Default requests per window
     */
    private $defaultRateLimit = 120;

    public function __construct(Database $db = null, Logger $logger = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
    }

    /**
     * Handle incoming HTTP request and dispatch to handler
     *
     * @param string|null $method HTTP method override
     * @param string|null $path Request path override
     * @param array|null $params Query or body parameters override
     * @return void
     */
    public function getDatabase(): Database
    {
        return $this->db;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function handleRequest($method = null, $path = null, $params = null)
    {
        $httpMethod = strtoupper($method ?: ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $requestUri = $path ?: ($_SERVER['REQUEST_URI'] ?? '/');

        // Parse path from URI
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        $cleanPath = trim(preg_replace('#^/api(/v1)?#', '', $parsedPath), '/');

        // Send CORS headers
        $this->sendCorsHeaders();

        if ($httpMethod === 'OPTIONS') {
            http_response_code(204);
            exit();
        }

        // Parse input body
        $rawInput = file_get_contents('php://input');
        $body = [];
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        $queryParams = $_GET ?? [];
        $requestData = is_array($params) ? $params : array_merge($queryParams, $body);

        try {
            // Healthcheck endpoint does not require auth
            if ($cleanPath === 'health' && $httpMethod === 'GET') {
                $this->respond([
                    'status' => 'healthy',
                    'timestamp' => time(),
                    'datetime' => date('c'),
                    'rcloneCWP_version' => defined('RCLONE_VERSION') ? RCLONE_VERSION : '1.0.0',
                ]);
                return;
            }

            // Authenticate API key via Bearer token
            $auth = $this->authenticate();
            if (!$auth['ok']) {
                $this->respondError($auth['error'], 401);
                return;
            }

            // Enforce Rate Limiting
            $rateLimit = $this->checkRateLimit();
            if (!$rateLimit['ok']) {
                $this->respondError($rateLimit['error'], 429, [
                    'Retry-After' => (string)($rateLimit['retry_after'] ?? 60)
                ]);
                return;
            }

            // Route request
            $response = $this->route($httpMethod, $cleanPath, $requestData);
            $this->respond($response['data'] ?? [], $response['code'] ?? 200, $response['meta'] ?? []);
        } catch (Exception $e) {
            $this->logger->error("API error [{$httpMethod} {$cleanPath}]: " . $e->getMessage());
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            $this->respondError($e->getMessage(), $code);
        }
    }

    /**
     * Authenticate request using Bearer API token
     *
     * @param string|null $tokenOverride
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public function authenticate($tokenOverride = null): array
    {
        $rawToken = '';
        if ($tokenOverride !== null) {
            $rawToken = $tokenOverride;
        } else {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (empty($authHeader) && function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }

            if (!empty($authHeader) && preg_match('/^Bearer\s+([A-Fa-f0-9]+)$/i', trim($authHeader), $matches)) {
                $rawToken = $matches[1];
            } elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
                $rawToken = trim($_SERVER['HTTP_X_API_KEY']);
            }
        }

        if (empty($rawToken)) {
            return ['ok' => false, 'error' => 'Missing or malformed Authorization header. Expected: Bearer <api_key>'];
        }

        // The database stores hash('sha256', $rawToken) or raw key
        $tokenHash = hash('sha256', $rawToken);

        $apiKeyRow = $this->db->fetch(
            "SELECT * FROM rclone_api_keys WHERE (api_key = ? OR api_key = ?) LIMIT 1",
            [$tokenHash, $rawToken]
        );

        if (!$apiKeyRow) {
            return ['ok' => false, 'error' => 'Invalid API key'];
        }

        // Check expiration
        if (!empty($apiKeyRow['expires_at'])) {
            if (strtotime($apiKeyRow['expires_at']) <= time()) {
                return ['ok' => false, 'error' => 'API key has expired'];
            }
        }

        $this->currentApiKey = $apiKeyRow;
        $perms = json_decode($apiKeyRow['permissions'] ?? '[]', true);
        $this->currentPermissions = is_array($perms) ? $perms : [];

        // Update last_used timestamp
        try {
            $this->db->query(
                "UPDATE rclone_api_keys SET last_used = NOW() WHERE id = ?",
                [(int)$apiKeyRow['id']]
            );
        } catch (Exception $e) {
            // Non-fatal if update fails
        }

        return ['ok' => true, 'key' => $apiKeyRow];
    }

    /**
     * Check if current key possesses a required permission scope
     *
     * @param string $requiredPermission E.g. 'backup:run', 'destination:list'
     * @return bool
     */
    public function hasPermission(string $requiredPermission): bool
    {
        if (in_array('*', $this->currentPermissions, true) || in_array('all', $this->currentPermissions, true)) {
            return true;
        }

        if (in_array($requiredPermission, $this->currentPermissions, true)) {
            return true;
        }

        // Check wildcard category, e.g. 'backup:*' covers 'backup:run'
        $parts = explode(':', $requiredPermission);
        if (count($parts) === 2) {
            $wildcard = $parts[0] . ':*';
            if (in_array($wildcard, $this->currentPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert that current authenticated caller has permission
     *
     * @throws Exception If permission is lacking
     */
    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            throw new Exception("Forbidden: Permission '{$permission}' required.", 403);
        }
    }

    /**
     * Check sliding rate limit for current API key
     *
     * @param int|null $customLimit
     * @param string|null $clientIp
     * @return array ['ok' => bool, 'error' => string|null, 'retry_after' => int]
     */
    public function checkRateLimit($customLimit = null, $clientIp = null): array
    {
        $keyId = $this->currentApiKey['id'] ?? 'anonymous';
        $ip = $clientIp ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $limit = $customLimit ?: $this->defaultRateLimit;

        $now = time();
        $window = $this->rateLimitWindow;
        $tempDir = defined('RCLONE_CACHE_DIR') ? RCLONE_CACHE_DIR : sys_get_temp_dir();
        $cacheFile = $tempDir . '/rclonecwp_ratelimit_' . md5($keyId . '_' . $ip) . '.json';

        // Use flock for the entire read-modify-write cycle to prevent race conditions
        $fp = @fopen($cacheFile, 'c+');
        if ($fp === false) {
            // If we can't open the file, allow the request but log the issue
            error_log("rcloneCWP API: Failed to open rate limit cache file: $cacheFile");
            return ['ok' => true];
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            error_log("rcloneCWP API: Failed to acquire lock on rate limit cache file: $cacheFile");
            return ['ok' => true];
        }

        try {
            $content = stream_get_contents($fp);
            $requests = [];
            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $requests = $decoded;
                }
            }

            // Filter out timestamps outside current window
            $activeRequests = array_values(array_filter($requests, function ($ts) use ($now, $window) {
                return ($now - $ts) < $window;
            }));

            if (count($activeRequests) >= $limit) {
                $oldestInWindow = min($activeRequests);
                $retryAfter = max(1, $window - ($now - $oldestInWindow));
                flock($fp, LOCK_UN);
                fclose($fp);
                return [
                    'ok' => false,
                    'error' => "Rate limit exceeded. Maximum {$limit} requests per minute.",
                    'retry_after' => $retryAfter,
                ];
            }

            $activeRequests[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($activeRequests));
            flock($fp, LOCK_UN);
            fclose($fp);

            return ['ok' => true];
        } catch (\Exception $e) {
            flock($fp, LOCK_UN);
            fclose($fp);
            error_log("rcloneCWP API: Rate limit check failed: " . $e->getMessage());
            return ['ok' => true]; // Fail open
        }
    }

    /**
     * Route API requests
     *
     * @param string $method GET, POST, PUT, DELETE
     * @param string $path Clean request path (e.g. 'jobs', 'jobs/1/run', 'destinations')
     * @param array $data Request payload
     * @return array ['data' => mixed, 'code' => int, 'meta' => array]
     */
    public function route(string $method, string $path, array $data = []): array
    {
        $segments = explode('/', $path);
        $resource = $segments[0] ?? '';
        $id = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;
        $subAction = $segments[2] ?? ($id === null ? ($segments[1] ?? '') : '');

        switch ($resource) {
            case 'status':
                return $this->handleStatus($method);

            case 'destinations':
                return $this->handleDestinations($method, $id, $subAction, $data);

            case 'jobs':
                return $this->handleJobs($method, $id, $subAction, $data);

            case 'backups':
                return $this->handleBackups($method, $id, $subAction, $data);

            case 'snapshots':
                return $this->handleSnapshots($method, $data);

            case 'restore':
                return $this->handleRestore($method, $data);

            case 'schedules':
                return $this->handleSchedules($method, $id, $subAction, $data);

            case 'hooks':
                return $this->handleHooks($method, $id, $subAction, $data);

            case 'notifications':
                return $this->handleNotifications($method, $id, $subAction, $data);

            case 'logs':
                return $this->handleLogs($method, $data);

            case 'keys':
                return $this->handleKeys($method, $id, $subAction, $data);

            default:
                throw new Exception("Resource '/{$resource}' not found", 404);
        }
    }

    // ========================================================================
    // RESOURCE HANDLERS
    // ========================================================================

    /**
     * System status overview
     */
    private function handleStatus(string $method): array
    {
        $this->requirePermission('status:read');

        $destCount = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM rclone_destinations WHERE enabled = 1") ?: 0);
        $jobCount = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM rclone_jobs WHERE enabled = 1") ?: 0);
        $backupCount = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM rclone_backups WHERE status = 'completed'") ?: 0);
        $totalBytes = (float)($this->db->fetchColumn("SELECT SUM(bytes_transferred) FROM rclone_backups WHERE status = 'completed'") ?: 0);
        $runningBackups = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM rclone_backups WHERE status = 'running'") ?: 0);

        $rclone = new Rclone();
        $rcloneVer = $rclone->version();

        return [
            'data' => [
                'status' => 'operational',
                'active_destinations' => $destCount,
                'active_jobs' => $jobCount,
                'completed_backups' => $backupCount,
                'running_backups' => $runningBackups,
                'total_bytes_transferred' => $totalBytes,
                'rclone_version' => $rcloneVer,
                'server' => gethostname(),
                'php_version' => PHP_VERSION,
            ],
            'code' => 200,
        ];
    }

    /**
     * Destinations CRUD and connectivity testing
     */
    private function handleDestinations(string $method, $id, string $action, array $data): array
    {
        $dm = new DestinationManager($this->db, null, $this->logger);

        // POST /destinations/{id}/test
        if ($method === 'POST' && $id && $action === 'test') {
            $this->requirePermission('destination:test');
            $res = $dm->testDestination($id);
            return ['data' => $res, 'code' => 200];
        }

        // GET /destinations
        if ($method === 'GET' && !$id) {
            $this->requirePermission('destination:list');
            $list = $dm->listDestinations();
            return ['data' => $list, 'code' => 200];
        }

        // GET /destinations/{id}
        if ($method === 'GET' && $id) {
            $this->requirePermission('destination:read');
            $dest = $dm->getDestination($id);
            if (!$dest) {
                throw new Exception("Destination #{$id} not found", 404);
            }
            return ['data' => $dest, 'code' => 200];
        }

        // POST /destinations
        if ($method === 'POST' && !$id) {
            $this->requirePermission('destination:create');
            $res = $dm->createDestination($data);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to create destination', 400);
            }
            return ['data' => $res, 'code' => 201];
        }

        // PUT /destinations/{id}
        if ($method === 'PUT' && $id) {
            $this->requirePermission('destination:update');
            $res = $dm->updateDestination($id, $data);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to update destination', 400);
            }
            return ['data' => $res, 'code' => 200];
        }

        // DELETE /destinations/{id}
        if ($method === 'DELETE' && $id) {
            $this->requirePermission('destination:delete');
            $res = $dm->deleteDestination($id);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to delete destination', 400);
            }
            return ['data' => $res, 'code' => 200];
        }

        throw new Exception("Method not allowed on /destinations", 405);
    }

    /**
     * Backup Jobs CRUD and execution
     */
    private function handleJobs(string $method, $id, string $action, array $data): array
    {
        $dm = new DestinationManager($this->db, null, $this->logger);
        $bjm = new BackupJobManager($this->db, $dm, $this->logger);

        // POST /jobs/{id}/run
        if ($method === 'POST' && $id && $action === 'run') {
            $this->requirePermission('backup:run');
            $engine = new BackupEngine($this->db, $dm, $this->logger);
            $res = $engine->runJob($id, $data);
            return ['data' => $res, 'code' => 200];
        }

        // GET /jobs
        if ($method === 'GET' && !$id) {
            $this->requirePermission('job:list');
            $jobs = $bjm->listJobs();
            return ['data' => $jobs, 'code' => 200];
        }

        // GET /jobs/{id}
        if ($method === 'GET' && $id) {
            $this->requirePermission('job:read');
            $job = $bjm->getJob($id);
            if (!$job) {
                throw new Exception("Job #{$id} not found", 404);
            }
            return ['data' => $job, 'code' => 200];
        }

        // POST /jobs
        if ($method === 'POST' && !$id) {
            $this->requirePermission('job:create');
            $res = $bjm->createJob($data);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to create job', 400);
            }
            return ['data' => $res, 'code' => 201];
        }

        // PUT /jobs/{id}
        if ($method === 'PUT' && $id) {
            $this->requirePermission('job:update');
            $res = $bjm->updateJob($id, $data);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to update job', 400);
            }
            return ['data' => $res, 'code' => 200];
        }

        // DELETE /jobs/{id}
        if ($method === 'DELETE' && $id) {
            $this->requirePermission('job:delete');
            $res = $bjm->deleteJob($id);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to delete job', 400);
            }
            return ['data' => $res, 'code' => 200];
        }

        throw new Exception("Method not allowed on /jobs", 405);
    }

    /**
     * Backup History & Run Records
     */
    private function handleBackups(string $method, $id, string $action, array $data): array
    {
        $dm = new DestinationManager($this->db, null, $this->logger);
        $bjm = new BackupJobManager($this->db, $dm, $this->logger);

        // GET /backups
        if ($method === 'GET' && !$id) {
            $this->requirePermission('backup:list');
            $limit = isset($data['limit']) ? (int)$data['limit'] : 50;
            $jobId = isset($data['job_id']) ? (int)$data['job_id'] : 0;

            $history = $bjm->listHistory($jobId, $limit);
            return ['data' => $history, 'code' => 200];
        }

        // GET /backups/{id}
        if ($method === 'GET' && $id) {
            $this->requirePermission('backup:read');
            $row = $this->db->fetch(
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
            return ['data' => $row, 'code' => 200];
        }

        throw new Exception("Method not allowed on /backups", 405);
    }

    /**
     * Remote snapshot inspection via SnapshotBrowser
     */
    private function handleSnapshots(string $method, array $data): array
    {
        if ($method !== 'GET') {
            throw new Exception("Method not allowed on /snapshots", 405);
        }

        $this->requirePermission('restore:list');

        $destId = (int)($data['destination_id'] ?? 0);
        if ($destId <= 0) {
            throw new Exception("Missing required parameter: destination_id", 400);
        }

        $dm = new DestinationManager($this->db, null, $this->logger);
        $browser = new SnapshotBrowser($this->db, $dm, $this->logger);

        // Single snapshot inspect
        if (!empty($data['path'])) {
            $manifest = $browser->inspectSnapshot($destId, trim($data['path']));
            return ['data' => $manifest, 'code' => 200];
        }

        // List snapshots
        $snapshots = $browser->listSnapshots($destId, $data['prefix'] ?? '');
        return ['data' => $snapshots, 'code' => 200];
    }

    /**
     * Restore execution
     */
    private function handleRestore(string $method, array $data): array
    {
        if ($method !== 'POST') {
            throw new Exception("Method not allowed on /restore", 405);
        }

        $this->requirePermission('restore:run');

        $destId = (int)($data['destination_id'] ?? 0);
        $snapshotPath = trim($data['snapshot_path'] ?? '');
        $username = trim($data['username'] ?? '');

        // Strict whitelist validation for snapshot_path and username
        if (!preg_match('/^[a-zA-Z0-9_\/-]+$/', $snapshotPath) || strpos($snapshotPath, '..') !== false || $snapshotPath[0] === '-') {
            throw new Exception("Invalid snapshot_path format", 400);
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username[0] === '-') {
            throw new Exception("Invalid username format", 400);
        }

        $allowedComps = ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'];
        $rawComps = is_array($data['components'] ?? null) ? $data['components'] : $allowedComps;
        $components = array_values(array_intersect($rawComps, $allowedComps));

        if ($destId <= 0 || empty($snapshotPath) || empty($username)) {
            throw new Exception("Missing required fields: destination_id, snapshot_path, username", 400);
        }

        $dm = new DestinationManager($this->db, null, $this->logger);
        $restoreEngine = new RestoreEngine($this->db, $dm, $this->logger);

        $result = $restoreEngine->executeRestore($destId, $snapshotPath, $username, $components, [
            'dry_run' => !empty($data['dry_run']),
            'overwrite' => !empty($data['overwrite']),
            'create_account' => !empty($data['create_account']),
        ]);

        return ['data' => $result, 'code' => 200];
    }

    /**
     * Schedules CRUD
     */
    private function handleSchedules(string $method, $id, string $action, array $data): array
    {
        $schedMgr = new ScheduleManager($this->db);

        // GET /schedules
        if ($method === 'GET' && !$id) {
            $this->requirePermission('schedule:list');
            $jobId = isset($data['job_id']) ? (int)$data['job_id'] : null;
            $list = $jobId ? $schedMgr->getJobSchedules($jobId) : $this->db->fetchAll("SELECT * FROM rclone_schedules ORDER BY id ASC");
            return ['data' => $list, 'code' => 200];
        }

        // GET /schedules/{id}
        if ($method === 'GET' && $id) {
            $this->requirePermission('schedule:read');
            $sched = $schedMgr->getSchedule($id);
            if (!$sched) {
                throw new Exception("Schedule #{$id} not found", 404);
            }
            return ['data' => $sched, 'code' => 200];
        }

        // POST /schedules
        if ($method === 'POST' && !$id) {
            $this->requirePermission('schedule:create');
            $res = $schedMgr->createSchedule($data);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to create schedule', 400);
            }
            return ['data' => $res, 'code' => 201];
        }

        // PUT /schedules/{id}
        if ($method === 'PUT' && $id) {
            $this->requirePermission('schedule:update');
            $res = $schedMgr->updateSchedule($id, $data);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to update schedule', 400);
            }
            return ['data' => $res, 'code' => 200];
        }

        // DELETE /schedules/{id}
        if ($method === 'DELETE' && $id) {
            $this->requirePermission('schedule:delete');
            $res = $schedMgr->deleteSchedule($id);
            if (!($res['ok'] ?? false)) {
                throw new Exception($res['error'] ?? 'Failed to delete schedule', 400);
            }
            return ['data' => $res, 'code' => 200];
        }

        throw new Exception("Method not allowed on /schedules", 405);
    }

    /**
     * Hooks listing and testing
     */
    private function handleHooks(string $method, $id, string $action, array $data): array
    {
        $hookEngine = new Hook($this->db, $this->logger);

        // POST /hooks/{id}/test
        if ($method === 'POST' && $id && $action === 'test') {
            $this->requirePermission('hook:test');
            $res = $hookEngine->testHook($id);
            return ['data' => $res, 'code' => 200];
        }

        // GET /hooks
        if ($method === 'GET' && !$id) {
            $this->requirePermission('hook:list');
            $hooks = $hookEngine->listHooks();
            return ['data' => $hooks, 'code' => 200];
        }

        // GET /hooks/{id}
        if ($method === 'GET' && $id) {
            $this->requirePermission('hook:read');
            $hook = $hookEngine->getHook($id);
            if (!$hook) {
                throw new Exception("Hook #{$id} not found", 404);
            }
            return ['data' => $hook, 'code' => 200];
        }

        throw new Exception("Method not allowed on /hooks", 405);
    }

    /**
     * Notifications listing and testing
     */
    private function handleNotifications(string $method, $id, string $action, array $data): array
    {
        $notifEngine = new Notification($this->db, $this->logger);

        // POST /notifications/{id}/test
        if ($method === 'POST' && $id && $action === 'test') {
            $this->requirePermission('notification:test');
            $res = $notifEngine->testNotification($id);
            return ['data' => $res, 'code' => 200];
        }

        // GET /notifications
        if ($method === 'GET' && !$id) {
            $this->requirePermission('notification:list');
            $notifs = $notifEngine->listNotifications();
            return ['data' => $notifs, 'code' => 200];
        }

        // GET /notifications/{id}
        if ($method === 'GET' && $id) {
            $this->requirePermission('notification:read');
            $notif = $notifEngine->getNotification($id);
            if (!$notif) {
                throw new Exception("Notification channel #{$id} not found", 404);
            }
            return ['data' => $notif, 'code' => 200];
        }

        throw new Exception("Method not allowed on /notifications", 405);
    }

    /**
     * Log querying
     */
    private function handleLogs(string $method, array $data): array
    {
        if ($method !== 'GET') {
            throw new Exception("Method not allowed on /logs", 405);
        }

        $this->requirePermission('logs:read');

        $limit = isset($data['limit']) ? min(500, max(1, (int)$data['limit'])) : 100;
        $offset = isset($data['offset']) ? max(0, (int)$data['offset']) : 0;
        $level = isset($data['level']) ? trim($data['level']) : null;
        $category = isset($data['category']) ? trim($data['category']) : null;

        $conditions = [];
        $params = [];

        if ($level) {
            $conditions[] = "level = ?";
            $params[] = $level;
        }
        if ($category) {
            $conditions[] = "category = ?";
            $params[] = $category;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT id, timestamp, level, category, message, context, destination_id
                FROM rclone_logs
                {$whereClause}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $logs = $this->db->fetchAll($sql, $params);

        return ['data' => $logs, 'code' => 200, 'meta' => ['count' => count($logs)]];
    }

    /**
     * API Key Management
     */
    private function handleKeys(string $method, $id, string $action, array $data): array
    {
        $this->requirePermission('keys:manage');

        // GET /keys
        if ($method === 'GET' && !$id) {
            $keys = $this->db->fetchAll(
                "SELECT id, name, permissions, expires_at, last_used, created_at FROM rclone_api_keys ORDER BY id ASC"
            );
            foreach ($keys as &$k) {
                $k['permissions'] = json_decode($k['permissions'], true) ?: [];
            }
            return ['data' => $keys, 'code' => 200];
        }

        // POST /keys
        if ($method === 'POST' && !$id) {
            $name = trim($data['name'] ?? '');
            if (empty($name)) {
                throw new Exception("API Key 'name' is required", 400);
            }

            $rawKey = bin2hex(random_bytes(32));
            $hash = hash('sha256', $rawKey);
            $perms = is_array($data['permissions'] ?? null) ? $data['permissions'] : ['*'];
            $expires = !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;

            $keyId = $this->db->insert('rclone_api_keys', [
                'name' => $name,
                'api_key' => $hash,
                'permissions' => json_encode($perms),
                'expires_at' => $expires,
            ]);

            return [
                'data' => [
                    'id' => $keyId,
                    'name' => $name,
                    'api_key' => $rawKey, // Returned once upon creation
                    'permissions' => $perms,
                    'expires_at' => $expires,
                    'message' => 'Save this API key now. It will not be shown again.',
                ],
                'code' => 201,
            ];
        }

        // DELETE /keys/{id}
        if ($method === 'DELETE' && $id) {
            $affected = $this->db->query("DELETE FROM rclone_api_keys WHERE id = ?", [$id]);
            return ['data' => ['deleted' => $affected > 0], 'code' => 200];
        }

        throw new Exception("Method not allowed on /keys", 405);
    }

    // ========================================================================
    // RESPONSE & ENVELOPE FORMATTERS
    // ========================================================================

    /**
     * Output successful JSON envelope
     */
    private function respond($data, int $code = 200, array $meta = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');

        $envelope = [
            'ok' => ($code >= 200 && $code < 300),
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => time(),
                'datetime' => date('c'),
            ], $meta),
        ];

        echo json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Output error JSON envelope
     */
    private function respondError(string $message, int $code = 400, array $headers = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        foreach ($headers as $hKey => $hVal) {
            header("{$hKey}: {$hVal}");
        }

        $envelope = [
            'ok' => false,
            'error' => $message,
            'code' => $code,
            'meta' => [
                'timestamp' => time(),
                'datetime' => date('c'),
            ],
        ];

        echo json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send standard CORS headers - restricted to CWP admin panel origin
     */
    private function sendCorsHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Determine allowed origin - CWP admin panel typically on :2030
        $allowedOrigins = [
            'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ':2030',
            'https://' . (gethostname() ?: 'localhost') . ':2030',
        ];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
    }
}
