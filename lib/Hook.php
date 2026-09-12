<?php
/**
 * rcloneCWP Hook Execution Engine
 *
 * Executes pre/post backup/restore hooks in shell, PHP, Python, and URL formats.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use Exception;

class Hook
{
    private $db;
    private $logger;
    private $rclone;

    public function __construct(Database $db = null, Logger $logger = null, Rclone $rclone = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
        $this->rclone = $rclone ?: new Rclone();
    }

    /**
     * Enforce administrative authorization for hook management
     *
     * @throws Exception If called from an unauthorized context
     */
    private function assertAdminAccess(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $isAdmin = (
            (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ||
            (!empty($_SESSION['username']) && $_SESSION['username'] === 'root')
        );

        if (!$isAdmin) {
            throw new Exception('Access denied: Administrative privileges required.');
        }
    }

    /**
     * Validate URL safety to prevent SSRF attacks
     *
     * @param string $url
     * @return array ['safe' => bool, 'error' => string|null]
     */
    public static function validateSafeUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return ['safe' => false, 'error' => 'Invalid URL structure'];
        }

        // Prohibit embedded credentials (userinfo) to prevent credential leakage and parser confusion
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return ['safe' => false, 'error' => 'URLs with embedded credentials (userinfo) are prohibited'];
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['safe' => false, 'error' => 'Only HTTP and HTTPS protocols are permitted'];
        }

        $host = strtolower($parts['host']);

        // Check for obvious localhost / metadata names
        $blockedHosts = ['localhost', '127.0.0.1', '::1', 'metadata.google.internal', 'instance-data'];
        if (in_array($host, $blockedHosts, true) || substr($host, -10) === '.localhost') {
            return ['safe' => false, 'error' => 'Requests to loopback or internal hosts are prohibited'];
        }

        // Resolve host to IPs
        $ips = @gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            // Check if host itself is an IP
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ips = [$host];
            } else {
                return ['safe' => false, 'error' => 'Unable to resolve hostname'];
            }
        }

        foreach ($ips as $ip) {
            // Explicit check for AWS/GCP/Azure link-local metadata IP 169.254.169.254 and 169.254.0.0/16
            if (strpos($ip, '169.254.') === 0) {
                return ['safe' => false, 'error' => "Requests to cloud metadata IP {$ip} are prohibited"];
            }

            // Validate IP is public (not private/reserved/loopback/link-local/multicast)
            // Handles both IPv4 and IPv6 properly
            $validation = self::validatePublicIp($ip);
            if (!$validation['valid']) {
                return ['safe' => false, 'error' => $validation['error']];
            }
        }

        return ['safe' => true, 'error' => null];
    }

    /**
     * Validate that an IP address is public (not private, reserved, loopback, link-local, or multicast)
     * Properly handles both IPv4 and IPv6 addresses
     *
     * @param string $ip
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validatePublicIp(string $ip): array
    {
        // First check if it's a valid IP at all
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['valid' => false, 'error' => "Invalid IP address: {$ip}"];
        }

        // Check IPv4 ranges using flags
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['valid' => false, 'error' => "Resolved IPv4 address {$ip} is in a private or reserved range"];
            }
            return ['valid' => true, 'error' => null];
        }

        // IPv6 - explicit validation since FILTER_FLAG_NO_PRIV_RANGE doesn't work for IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $v6Checks = self::checkIpv6Public($ip);
            if (!$v6Checks['valid']) {
                return $v6Checks;
            }
            return ['valid' => true, 'error' => null];
        }

        return ['valid' => false, 'error' => "Unsupported IP format: {$ip}"];
    }

    /**
     * Check IPv6 address against private/reserved ranges
     * Per RFC 4193 (ULA), RFC 3849 (documentation), RFC 6052 (IPv4-translatable), RFC 4291 (loopback, link-local, multicast)
     *
     * @param string $ip
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private static function checkIpv6Public(string $ip): array
    {
        $ip = strtolower($ip);

        // Loopback ::1
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is loopback"];
        }

        // Unspecified ::
        if ($ip === '::' || $ip === '0:0:0:0:0:0:0:0') {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is unspecified"];
        }

        // Link-local fe80::/10
        if (substr($ip, 0, 4) === 'fe80') {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is link-local"];
        }

        // Unique Local Address (ULA) fc00::/7 (fc00::/8 and fd00::/8)
        if (substr($ip, 0, 2) === 'fc' || substr($ip, 0, 2) === 'fd') {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is unique local (private)"];
        }

        // Documentation prefix 2001:db8::/32
        if (strpos($ip, '2001:0db8') === 0 || strpos($ip, '2001:db8') === 0) {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is documentation prefix"];
        }

        // IPv4-mapped ::ffff:0:0/96 - check embedded IPv4
        if (strpos($ip, '::ffff:') === 0) {
            $embedded = substr($ip, 7);
            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (!filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} embeds private IPv4 {$embedded}"];
                }
            }
        }

        // Multicast ff00::/8
        if (substr($ip, 0, 2) === 'ff') {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is multicast"];
        }

        // ORCHID 2001:10::/28 (deprecated)
        if (strpos($ip, '2001:001') === 0 || strpos($ip, '2001:10:') === 0) {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is ORCHID (reserved)"];
        }

        // Teredo 2001::/32
        if (strpos($ip, '2001:') === 0 && strpos($ip, '2001:0db8') !== 0 && strpos($ip, '2001:10:') !== 0) {
            $parts = explode(':', $ip);
            if (count($parts) >= 2 && $parts[0] === '2001' && $parts[1] === '0000') {
                return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is Teredo (tunneling)"];
            }
        }

        // 6to4 2002::/16
        if (strpos($ip, '2002:') === 0) {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is 6to4 (tunneling)"];
        }

        // Benchmarking 2001:2::/48
        if (strpos($ip, '2001:0002:') === 0 || strpos($ip, '2001:2:') === 0) {
            return ['valid' => false, 'error' => "Resolved IPv6 address {$ip} is benchmarking (reserved)"];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Execute all enabled hooks for a given hook point
     *
     * @param string $hookPoint The hook point name (e.g., 'backup_start', 'backup_complete', 'backup_fail', 'restore_start', 'restore_complete', 'restore_fail')
     * @param int|null $jobId The job ID
     * @param array $context Additional context data (backup results, error messages, etc.)
     * @return bool True if all hooks succeeded
     */
    public function execute(string $hookPoint, $jobId = null, array $context = []): bool
    {
        $hooks = $this->getHooks($hookPoint);

        if (empty($hooks)) {
            return true;
        }

        $success = true;

        foreach ($hooks as $hook) {
            try {
                $result = $this->executeHook($hook, $jobId, $context);
                if (!($result['success'] ?? false)) {
                    $success = false;
                }
            } catch (Exception $e) {
                $this->logger->error("Hook {$hook['name']} failed: " . $e->getMessage(), [
                    'hook_id' => $hook['id'] ?? null,
                    'hook_point' => $hookPoint,
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Execute a single hook
     *
     * @param array $hook Hook definition row
     * @param int|null $jobId Job ID
     * @param array $context Context array
     * @return array Result array with success, output, returnCode/httpCode
     */
    public function executeHook(array $hook, $jobId, array $context): array
    {
        $this->logger->info("Executing hook: {$hook['name']}", [
            'hook_id' => $hook['id'] ?? null,
            'type' => $hook['type'],
            'hook_point' => $hook['event'],
            'job_id' => $jobId,
            'timeout' => $hook['timeout'] ?? 300
        ]);

        $startTime = microtime(true);

        $result = null;
        switch ($hook['type']) {
            case 'shell':
                $result = $this->executeShellHook($hook, $jobId, $context);
                break;
            case 'php':
                $result = $this->executePHPHook($hook, $jobId, $context);
                break;
            case 'python':
                $result = $this->executePythonHook($hook, $jobId, $context);
                break;
            case 'url':
                $result = $this->executeURLHook($hook, $jobId, $context);
                break;
            default:
                throw new Exception("Unknown hook type: {$hook['type']}");
        }

        $duration = round(microtime(true) - $startTime, 3);

        $this->logger->info("Hook {$hook['name']} completed in {$duration}s", [
            'hook_id' => $hook['id'] ?? null,
            'success' => $result['success'] ?? false,
            'duration' => $duration,
            'output' => isset($result['output']) ? substr($result['output'], 0, 500) : ''
        ]);

        // Log hook execution to rclone_logs
        $this->logHookExecution($hook['id'] ?? 0, $jobId, $hook['event'] ?? 'unknown', $result, $duration);

        return $result;
    }

    /**
     * Build clean environment variables for hook execution
     * Filters variable names and prevents unsafe environment injections
     */
    private function buildHookEnvironment($jobId, string $hookPoint, string $hookName, array $context): array
    {
        $env = [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'RCLONE_JOB_ID' => (string)($jobId ?? ''),
            'RCLONE_HOOK_POINT' => $hookPoint,
            'RCLONE_HOOK_NAME' => $hookName,
        ];

        // Blocked dangerous env vars that could affect loader or shell execution
        $blocked = ['LD_PRELOAD', 'LD_LIBRARY_PATH', 'BASH_ENV', 'ENV', 'SHELLOPTS', 'IFS'];

        foreach ($context as $key => $value) {
            $cleanedKey = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string)$key));
            if (in_array($cleanedKey, $blocked, true)) {
                continue;
            }
            $envKey = 'RCLONE_CTX_' . $cleanedKey;
            $env[$envKey] = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
        }

        return $env;
    }

    /**
     * Execute a process with controlled arguments and environment via proc_open
     */
    private function runProcessWithEnv(array $cmdParts, array $env, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $escapedCmd = implode(' ', array_map('escapeshellarg', $cmdParts));
        if (file_exists('/usr/bin/timeout')) {
            $escapedCmd = '/usr/bin/timeout ' . (int)$timeout . ' ' . $escapedCmd;
        }

        $process = proc_open($escapedCmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return ['success' => false, 'output' => 'Failed to spawn process', 'returnCode' => -1];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $output = trim($stdout . "\n" . $stderr);

        return [
            'success' => $status === 0,
            'output' => $output,
            'returnCode' => $status,
        ];
    }

    /**
     * Execute shell hook script
     */
    private function executeShellHook(array $hook, $jobId, array $context): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rclone_hook_');
        if ($tmpFile === false) {
            return ['success' => false, 'error' => 'Failed to create temp file'];
        }

        try {
            file_put_contents($tmpFile, $hook['command']);
            @chmod($tmpFile, 0700);

            $timeout = (int)($hook['timeout'] ?? 300);
            if ($timeout < 1) {
                $timeout = 300;
            }

            $env = $this->buildHookEnvironment($jobId, $hook['event'] ?? '', $hook['name'] ?? '', $context);
            return $this->runProcessWithEnv(['/bin/bash', $tmpFile], $env, $timeout);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Execute PHP hook script
     */
    private function executePHPHook(array $hook, $jobId, array $context): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rclone_hook_') . '.php';
        if ($tmpFile === false) {
            return ['success' => false, 'error' => 'Failed to create temp file'];
        }

        try {
            file_put_contents($tmpFile, $hook['command']);
            @chmod($tmpFile, 0600);

            $timeout = (int)($hook['timeout'] ?? 300);
            if ($timeout < 1) {
                $timeout = 300;
            }
            $phpBinary = file_exists('/usr/local/cwp/php71/bin/php') ? '/usr/local/cwp/php71/bin/php' : '/usr/bin/php';

            $env = $this->buildHookEnvironment($jobId, $hook['event'] ?? '', $hook['name'] ?? '', $context);
            return $this->runProcessWithEnv([$phpBinary, '-d', 'max_execution_time=' . $timeout, $tmpFile], $env, $timeout);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Execute Python hook script
     */
    private function executePythonHook(array $hook, $jobId, array $context): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rclone_hook_') . '.py';
        if ($tmpFile === false) {
            return ['success' => false, 'error' => 'Failed to create temp file'];
        }

        try {
            file_put_contents($tmpFile, $hook['command']);
            @chmod($tmpFile, 0600);

            $timeout = (int)($hook['timeout'] ?? 300);
            if ($timeout < 1) {
                $timeout = 300;
            }
            $pythonBinary = file_exists('/usr/bin/python3') ? '/usr/bin/python3' : '/usr/bin/python';

            $env = $this->buildHookEnvironment($jobId, $hook['event'] ?? '', $hook['name'] ?? '', $context);
            return $this->runProcessWithEnv([$pythonBinary, $tmpFile], $env, $timeout);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Execute URL/webhook hook with SSRF protection and SSL verification
     */
    private function executeURLHook(array $hook, $jobId, array $context): array
    {
        $url = trim($hook['command']);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid URL format'];
        }

        // Enforce SSRF protection
        $safety = self::validateSafeUrl($url);
        if (!$safety['safe']) {
            return ['success' => false, 'error' => 'SSRF security policy blocked request: ' . $safety['error']];
        }

        $data = array_merge([
            'hook_point' => $hook['event'] ?? '',
            'hook_name' => $hook['name'] ?? '',
            'job_id' => $jobId,
            'timestamp' => time(),
            'datetime' => date('c'),
        ], $context);

        $timeout = (int)($hook['timeout'] ?? 30);
        if ($timeout < 1) {
            $timeout = 30;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: rcloneCWP-Hook/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, // Disallow redirects to prevent redirect-based SSRF
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = ($httpCode >= 200 && $httpCode < 300);

        return [
            'success' => $success,
            'output' => $response,
            'httpCode' => $httpCode,
            'error' => $error ?: ($success ? null : "HTTP Error {$httpCode}")
        ];
    }

    /**
     * Get all enabled hooks for a hook point
     */
    public function getHooks(string $hookPoint): array
    {
        $validEvents = [
            'backup_start', 'backup_complete', 'backup_fail',
            'restore_start', 'restore_complete', 'restore_fail'
        ];
        if (!in_array($hookPoint, $validEvents, true)) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT * FROM rclone_hooks WHERE event = ? AND enabled = 1 ORDER BY run_order ASC, id ASC",
            [$hookPoint]
        );
    }

    /**
     * CRUD: Create a new hook
     */
    public function createHook(array $data): array
    {
        $this->assertAdminAccess();

        $validated = $this->validateHookData($data);
        if ($validated !== true) {
            return ['ok' => false, 'error' => $validated];
        }

        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO rclone_hooks (name, event, command, type, enabled, timeout, run_order) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['name'],
            $data['event'],
            $data['command'],
            $data['type'],
            $data['enabled'] ?? 1,
            $data['timeout'] ?? 300,
            $data['run_order'] ?? 10
        ]);

        return ['ok' => true, 'id' => (int)$this->db->getConnection()->lastInsertId()];
    }

    /**
     * CRUD: Get hook by ID
     */
    public function getHook($id)
    {
        Validator::int($id, 1);
        return $this->db->fetch(
            "SELECT * FROM rclone_hooks WHERE id = ?",
            [$id]
        );
    }

    /**
     * CRUD: List all hooks
     */
    public function listHooks(): array
    {
        return $this->db->fetchAll("SELECT * FROM rclone_hooks ORDER BY event, run_order ASC, id ASC");
    }

    /**
     * CRUD: Update hook
     */
    public function updateHook($id, array $data): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);
        $validated = $this->validateHookData($data, true);
        if ($validated !== true) {
            return ['ok' => false, 'error' => $validated];
        }

        $fields = [];
        $params = [];
        foreach (['name', 'event', 'command', 'type', 'enabled', 'timeout', 'run_order'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (empty($fields)) {
            return ['ok' => false, 'error' => 'No fields to update'];
        }

        $params[] = $id;
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE rclone_hooks SET " . implode(', ', $fields) . " WHERE id = ?"
        );
        $stmt->execute($params);

        return ['ok' => true];
    }

    /**
     * CRUD: Delete hook
     */
    public function deleteHook($id): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);
        $stmt = $this->db->getConnection()->prepare(
            "DELETE FROM rclone_hooks WHERE id = ?"
        );
        $stmt->execute([$id]);

        return ['ok' => $stmt->rowCount() > 0];
    }

    /**
     * Toggle hook enabled state
     */
    public function toggleHook($id): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);
        $hook = $this->getHook($id);
        if (!$hook) {
            return ['ok' => false, 'error' => 'Hook not found'];
        }

        $newState = $hook['enabled'] ? 0 : 1;
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE rclone_hooks SET enabled = ? WHERE id = ?"
        );
        $stmt->execute([$newState, $id]);

        return ['ok' => true, 'enabled' => $newState];
    }

    /**
     * Test a hook execution
     */
    public function testHook($id, array $sampleContext = []): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);
        $hook = $this->getHook($id);
        if (!$hook) {
            return ['ok' => false, 'error' => 'Hook not found'];
        }

        $context = array_merge([
            'test_mode' => true,
            'job_id' => 9999,
            'job_name' => 'test-simulation',
            'backup_id' => 8888,
            'status' => 'completed',
            'timestamp' => time(),
            'message' => 'rcloneCWP test execution event'
        ], $sampleContext);

        try {
            $result = $this->executeHook($hook, 9999, $context);
            return [
                'ok' => $result['success'] ?? false,
                'result' => $result
            ];
        } catch (Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate hook data
     */
    private function validateHookData(array $data, bool $isUpdate = false)
    {
        if (!$isUpdate) {
            $required = ['name', 'event', 'command', 'type'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return "Field '$field' is required";
                }
            }
        }

        if (isset($data['name']) && strlen($data['name']) > 255) {
            return "Name too long (max 255 chars)";
        }

        $validEvents = ['backup_start', 'backup_complete', 'backup_fail', 'restore_start', 'restore_complete', 'restore_fail'];
        if (isset($data['event']) && !in_array($data['event'], $validEvents, true)) {
            return "Invalid event type";
        }

        $validTypes = ['shell', 'php', 'python', 'url'];
        if (isset($data['type']) && !in_array($data['type'], $validTypes, true)) {
            return "Invalid hook type (must be shell, php, python, or url)";
        }

        if (isset($data['timeout'])) {
            Validator::int($data['timeout'], 1, 3600);
        }

        if (isset($data['run_order'])) {
            Validator::int($data['run_order'], 0, 1000);
        }

        if (isset($data['enabled']) && !in_array($data['enabled'], [0, 1], true)) {
            return "Enabled must be 0 or 1";
        }

        // For URL type, validate URL format and SSRF safety
        if (isset($data['type']) && $data['type'] === 'url' && isset($data['command'])) {
            $safety = self::validateSafeUrl(trim($data['command']));
            if (!$safety['safe']) {
                return $safety['error'];
            }
        }

        return true;
    }

    /**
     * Log hook execution to rclone_logs
     */
    private function logHookExecution($hookId, $jobId, string $hookPoint, array $result, float $duration): void
    {
        try {
            $level = ($result['success'] ?? false) ? 'info' : 'error';
            $message = "Hook {$hookId} executed at {$hookPoint}: " . (($result['success'] ?? false) ? 'success' : 'failed');

            $context = [
                'hook_id' => $hookId,
                'hook_point' => $hookPoint,
                'job_id' => $jobId,
                'duration' => $duration,
                'success' => $result['success'] ?? false,
            ];
            if (isset($result['output'])) {
                $context['output'] = substr($result['output'], 0, 1000);
            }
            if (isset($result['error'])) {
                $context['error'] = $result['error'];
            }
            if (isset($result['returnCode'])) {
                $context['return_code'] = $result['returnCode'];
            }
            if (isset($result['httpCode'])) {
                $context['http_code'] = $result['httpCode'];
            }

            $this->db->getConnection()->prepare(
                "INSERT INTO rclone_logs (level, category, message, context) VALUES (?, ?, ?, ?)"
            )->execute([
                $level,
                'hook',
                $message,
                json_encode($context)
            ]);
        } catch (Exception $e) {
            // Don't fail hook execution if logging fails
        }
    }
}
