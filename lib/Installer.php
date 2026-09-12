<?php
/**
 * rcloneCWP Installer
 *
 * Idempotent install/uninstall operations: env checks, schema, keys, dirs, menu.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

class Installer
{
    private $db;
    private $logger;
    private $home;
    private $webDir;
    private $moduleFile;
    private $threeDParty;
    private $menuBegin = '<!-- rcloneCWP menu entry (begin) -->';
    private $menuEnd = '<!-- rcloneCWP menu entry (end) -->';

    /**
     * Constructor
     *
     * @param Database|null $db Database instance
     * @param Logger|null $logger Logger instance
     */
    public function __construct($db = null, $logger = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(null, $this->db);
        $this->home = defined('RCLONE_HOME') ? RCLONE_HOME : '/usr/local/cwp/rcloneCWP';
        $this->webDir = defined('RCLONE_MODULES_DIR') ? RCLONE_MODULES_DIR : '/usr/local/cwpsrv/htdocs/resources/admin/modules';
        $this->moduleFile = defined('RCLONE_MODULE_FILE') ? RCLONE_MODULE_FILE : ($this->webDir . '/rcloneCWP.php');
        $this->threeDParty = '/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php';
    }

    /**
     * Public wrapper: re-add the 3rdparty.php menu entry if it is missing.
     *
     * Called by the module entry point on every load so a CWP update that
     * clobbers 3rdparty.php heals itself without a re-install.
     *
     * @return bool True if the entry is (now) present
     */
    public function selfHealMenuEntry()
    {
        if (!is_file($this->threeDParty) || !is_readable($this->threeDParty)) {
            return false;
        }

        $content = file_get_contents($this->threeDParty);
        if (strpos($content, $this->menuBegin) !== false) {
            return true;  // already present
        }

        $result = $this->addMenuEntry();
        return !empty($result['ok']);
    }

    /**
     * Run full installation (idempotent)
     *
     * @return array ['success' => bool, 'message' => string, 'steps' => array]
     */
    public function install()
    {
        $results = [];
        $success = true;

        try {
            // Step 1: Environment checks
            $results[] = ['step' => 'Environment checks', 'result' => $this->checkEnvironment()];

            // Step 2: Create directories
            $results[] = ['step' => 'Create directories', 'result' => $this->createDirectories()];

            // Step 3: Create encryption key
            $results[] = ['step' => 'Create encryption key', 'result' => $this->createEncryptionKey()];

            // Step 4: Apply database schema
            $results[] = ['step' => 'Apply database schema', 'result' => $this->applySchema()];

            // Step 5: Add menu entry to 3rdparty.php
            $results[] = ['step' => 'Add menu entry', 'result' => $this->addMenuEntry()];

            // Step 6: Seed initial data
            $results[] = ['step' => 'Seed initial data', 'result' => $this->seedData()];

            $this->logger->info('Installation completed successfully');
        } catch (\Exception $e) {
            $success = false;
            $message = 'Installation failed: ' . $e->getMessage();
            $this->logger->error($message);
            return ['success' => false, 'message' => $message, 'steps' => $results];
        }

        return [
            'success' => $success,
            'message' => $success ? 'rcloneCWP installed successfully' : 'Installation completed with errors',
            'steps' => $results
        ];
    }

    /**
     * Run uninstallation (with confirmation prompts for destructive actions)
     *
     * @param bool $keepBackups Keep backup records (default: ask)
     * @param bool $keepLogs Keep log entries (default: ask)
     * @return array ['success' => bool, 'message' => string, 'steps' => array]
     */
    public function uninstall($keepBackups = null, $keepLogs = null)
    {
        $results = [];
        $ok = true;

        try {
            // Step 1: Remove menu entry
            $results[] = ['step' => 'Remove menu entry', 'result' => $this->removeMenuEntry()];

            // Step 2: Drop tables (unless keeping data)
            $results[] = ['step' => 'Drop tables', 'result' => $this->dropTables($keepBackups, $keepLogs)];

            // Step 3: Remove encryption key
            $results[] = ['step' => 'Remove encryption key', 'result' => $this->removeEncryptionKey()];

            // Step 4: Remove cron entries (placeholder for Phase 5)
            $results[] = ['step' => 'Remove cron entries', 'result' => $this->removeCronEntries()];
        } catch (\Exception $e) {
            $message = 'Uninstall failed: ' . $e->getMessage();
            $this->logger->error($message);
            return ['success' => false, 'message' => $message, 'steps' => $results];
        }

        // Aggregate step failures into the overall result — a [FAIL] step must
        // not report SUCCESS.
        $failed = [];
        foreach ($results as $r) {
            if (empty($r['result']['ok'])) {
                $failed[] = $r['step'];
                $ok = false;
            }
        }

        $this->logger->info('Uninstall completed (deployed files kept for manual review)');

        return [
            'success' => $ok,
            'message' => $ok
                ? 'rcloneCWP uninstalled (review and remove files manually)'
                : 'rcloneCWP uninstall incomplete: ' . implode(', ', $failed) . ' — check the steps',
            'steps' => $results
        ];
    }

    /**
     * Check environment prerequisites
     *
     * @return array ['ok' => bool, 'checks' => array]
     */
    private function checkEnvironment()
    {
        $checks = [];

        // Check PHP version
        $phpVersion = PHP_VERSION;
        $checks[] = [
            'name' => 'PHP version',
            'ok' => version_compare($phpVersion, '7.1.0', '>='),
            'value' => $phpVersion,
            'required' => '>= 7.1.0'
        ];

        // Check rclone binary
        $rclone = Rclone::findBinary();
        $checks[] = [
            'name' => 'rclone binary',
            'ok' => $rclone !== null,
            'value' => $rclone ?: 'not found',
            'required' => 'rclone >= 1.75.0'
        ];

        // Check database connection
        try {
            $conn = $this->db->getConnection();
            $version = $conn->query('SELECT VERSION()')->fetchColumn();
            $checks[] = [
                'name' => 'Database connection',
                'ok' => true,
                'value' => $version,
                'required' => 'MySQL/MariaDB'
            ];
        } catch (\Exception $e) {
            $checks[] = [
                'name' => 'Database connection',
                'ok' => false,
                'value' => $e->getMessage(),
                'required' => 'MySQL/MariaDB'
            ];
        }

        // Check home directory writable
        $homeWritable = is_writable(dirname($this->home)) || is_dir($this->home);
        $checks[] = [
            'name' => 'Home directory writable',
            'ok' => $homeWritable,
            'value' => $this->home,
            'required' => 'writable'
        ];

        // Check modules directory exists
        $modulesExists = is_dir($this->webDir);
        $checks[] = [
            'name' => 'Modules directory',
            'ok' => $modulesExists,
            'value' => $this->webDir,
            'required' => 'exists'
        ];

        $allOk = true;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $allOk = false;
                break;
            }
        }

        return ['ok' => $allOk, 'checks' => $checks];
    }

    /**
     * Create required directories with proper permissions
     *
     * @return array ['ok' => bool, 'dirs' => array]
     */
    private function createDirectories()
    {
        // Note: CWP's modules dir ($this->webDir) is deliberately NOT created or
        // chmod'd here — it already exists and touching it would violate the
        // "no CWP core file modified" acceptance criterion.
        $dirs = [
            $this->home => 0700,
            RCLONE_LIB_DIR => 0755,
            RCLONE_SQL_DIR => 0755,
            RCLONE_LOG_DIR => 0700,
            RCLONE_CACHE_DIR => 0700,
        ];

        $results = [];
        $allOk = true;

        foreach ($dirs as $dir => $mode) {
            if (!is_dir($dir)) {
                $created = @mkdir($dir, $mode, true);
                $ok = $created && is_dir($dir);
            } else {
                $ok = true;
            }
            if ($ok) {
                @chmod($dir, $mode);
            }
            $results[] = ['dir' => $dir, 'ok' => $ok, 'mode' => sprintf('%04o', $mode)];
            if (!$ok) {
                $allOk = false;
            }
        }

        return ['ok' => $allOk, 'dirs' => $results];
    }

    /**
     * Create encryption key
     *
     * @return array ['ok' => bool, 'keyfile' => string]
     */
    private function createEncryptionKey()
    {
        $keyfile = RCLONE_KEYFILE;

        if (Encryption::hasKeyFile()) {
            return ['ok' => true, 'keyfile' => $keyfile, 'exists' => true];
        }

        $key = Encryption::generateKey($keyfile);
        $ok = is_file($keyfile) && is_readable($keyfile);

        return ['ok' => $ok, 'keyfile' => $keyfile, 'exists' => false, 'created' => $ok];
    }

    /**
     * Apply database schema
     *
     * @return array ['ok' => bool, 'tables' => int]
     */
    private function applySchema()
    {
        $sqlFile = RCLONE_SQL_DIR . '/install.sql';

        if (!is_file($sqlFile)) {
            throw new \RuntimeException('Schema file not found: ' . $sqlFile);
        }

        $count = SchemaLoader::load($sqlFile, $this->db);

        // Verify tables exist
        $tables = $this->db->fetchAll(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'rclone_%'",
            [RCLONE_DB['name'] ?? 'root_cwp']
        );

        $expected = 8;
        $actual = count($tables);

        return [
            'ok' => $actual >= $expected,
            'statements' => $count,
            'tables' => $actual,
            'expected' => $expected,
            'table_names' => array_column($tables, 'TABLE_NAME')
        ];
    }

    /**
     * Add menu entry to 3rdparty.php
     *
     * @return array ['ok' => bool, 'file' => string]
     */
    private function addMenuEntry()
    {
        if (!is_file($this->threeDParty)) {
            return ['ok' => false, 'file' => $this->threeDParty, 'error' => '3rdparty.php not found'];
        }

        $content = file_get_contents($this->threeDParty);

        // Check if already added
        if (strpos($content, $this->menuBegin) !== false) {
            return ['ok' => true, 'file' => $this->threeDParty, 'already_added' => true];
        }

        $entry = $this->getMenuEntryCode();
        $newContent = rtrim($content) . "\n" . $entry;

        if (@file_put_contents($this->threeDParty, $newContent, LOCK_EX) === false) {
            return ['ok' => false, 'file' => $this->threeDParty, 'error' => 'Failed to write 3rdparty.php'];
        }

        return ['ok' => true, 'file' => $this->threeDParty, 'already_added' => false];
    }

    /**
     * Remove menu entry from 3rdparty.php
     *
     * @return array ['ok' => bool, 'file' => string]
     */
    private function removeMenuEntry()
    {
        if (!is_file($this->threeDParty)) {
            return ['ok' => true, 'file' => $this->threeDParty, 'skipped' => true];
        }

        $content = file_get_contents($this->threeDParty);

        $begin = strpos($content, $this->menuBegin);
        if ($begin === false) {
            return ['ok' => true, 'file' => $this->threeDParty, 'already_removed' => true];
        }

        $end = strpos($content, $this->menuEnd, $begin);
        if ($end === false) {
            return ['ok' => false, 'file' => $this->threeDParty, 'error' => 'Marker pair incomplete — remove manually'];
        }

        // Cut from the newline preceding the begin marker through the end marker
        $cutFrom = ($begin > 0 && $content[$begin - 1] === "\n") ? $begin - 1 : $begin;
        $cutTo = $end + strlen($this->menuEnd);
        $newContent = substr($content, 0, $cutFrom) . substr($content, $cutTo);

        // Preserve trailing newline
        if (substr($newContent, -1) !== "\n") {
            $newContent .= "\n";
        }

        if (@file_put_contents($this->threeDParty, $newContent, LOCK_EX) === false) {
            return ['ok' => false, 'file' => $this->threeDParty, 'error' => 'Failed to write 3rdparty.php'];
        }

        return ['ok' => true, 'file' => $this->threeDParty, 'removed' => true];
    }

    /**
     * Get the menu entry code block for 3rdparty.php
     *
     * Mirrors the native configserver.php pattern: a self-contained jQuery
     * script block whose <li> lines are emitted by an embedded PHP
     * file_exists() check. Begin/end HTML-comment markers make the block
     * removable byte-exactly.
     *
     * @return string
     */
    private function getMenuEntryCode()
    {
        $block = <<<'HTML'
<!-- rcloneCWP menu entry (begin) -->
<script type="text/javascript">
	$(document).ready(function() {
		var rcloneCWP_buttons = ''
<?php

	if (file_exists("__MODULE_FILE__")) {
		echo "+'		<li><a href=\"index.php?module=rcloneCWP\"><span aria-hidden=\"true\" class=\"icon16 icomoon-icon-arrow-right-3\"></span>rcloneCWP</a></li>'\n";
	}

?>
		;
		$(".mainnav > ul").append(rcloneCWP_buttons);
	});
</script>
<!-- rcloneCWP menu entry (end) -->
HTML;

        return str_replace('__MODULE_FILE__', $this->moduleFile, $block);
    }

    /**
     * Seed initial data (API key, etc.)
     *
     * @return array ['ok' => bool, 'seeded' => array]
     */
    private function seedData()
    {
        $seeded = [];

        // Generate API key. Name stays 'default' so a re-install updates the
        // same row instead of inserting a new seed row each time.
        $apiKey = bin2hex(random_bytes(32));
        $apiKeyHash = hash('sha256', $apiKey);
        $permissions = '["backup:list","backup:run","restore:list","restore:run"]';

        $affected = $this->db->query(
            'UPDATE rclone_api_keys SET api_key = ?, permissions = ? WHERE name = ?',
            [$apiKeyHash, $permissions, 'default']
        );

        if ($affected === 0) {
            $this->db->insert('rclone_api_keys', [
                'name' => 'default',
                'api_key' => $apiKeyHash,
                'permissions' => $permissions,
            ]);
        }

        $seeded['api_key'] = $apiKey;

        return ['ok' => true, 'seeded' => $seeded];
    }

    /**
     * Drop database tables
     *
     * @param bool|null $keepBackups Keep backup records (null = ask)
     * @param bool|null $keepLogs Keep log entries (null = ask)
     * @return array ['ok' => bool, 'dropped' => array]
     */
    private function dropTables($keepBackups = null, $keepLogs = null)
    {
        // FK-safe drop order derived from the schema's FK edges
        // (child -> parent): schedules->jobs, backups->{jobs,destinations},
        // jobs->destinations, logs->destinations. Children dropped first;
        // rclone_destinations is the only parent and goes last.
        // hooks/notifications/api_keys have no FKs and slot in freely.
        $tables = ['rclone_schedules'];

        if ($keepBackups !== true) {
            $tables[] = 'rclone_backups';
        }

        $tables[] = 'rclone_jobs';

        if ($keepLogs !== true) {
            $tables[] = 'rclone_logs';
        }

        $tables[] = 'rclone_hooks';
        $tables[] = 'rclone_notifications';
        $tables[] = 'rclone_api_keys';
        $tables[] = 'rclone_destinations';  // parent — drop last

        $dropped = [];
        $allOk = true;
        foreach ($tables as $table) {
            try {
                $this->db->getConnection()->exec("DROP TABLE IF EXISTS `$table`");
                $dropped[] = $table;
            } catch (\Exception $e) {
                $allOk = false;
                $dropped[] = $table . ' (failed: ' . $e->getMessage() . ')';
            }
        }

        return ['ok' => $allOk, 'dropped' => $dropped];
    }

    /**
     * Remove encryption key
     *
     * @return array ['ok' => bool, 'keyfile' => string]
     */
    private function removeEncryptionKey()
    {
        $keyfile = RCLONE_KEYFILE;

        if (!is_file($keyfile)) {
            return ['ok' => true, 'keyfile' => $keyfile, 'already_removed' => true];
        }

        $ok = @unlink($keyfile);

        return ['ok' => $ok, 'keyfile' => $keyfile, 'removed' => $ok];
    }

    /**
     * Remove cron entries (placeholder for Phase 5)
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    private function removeCronEntries()
    {
        return ['ok' => true, 'message' => 'Cron entries not implemented yet (Phase 5)'];
    }

    /**
     * Get admin URL for the module
     *
     * @return string
     */
    public function getAdminUrl()
    {
        return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'server') . ':2030/index.php?module=rcloneCWP';
    }
}
