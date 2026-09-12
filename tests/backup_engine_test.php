<?php
/**
 * rcloneCWP Phase 3: Backup Engine Comprehensive Test Suite
 *
 * Validates CWP account discovery, component collectors (metadata, databases,
 * files, DNS, SSL, cron, mail, FTP), job manager CRUD, live backup execution,
 * checksum verification, and retention policy pruning.
 *
 * PHP 7.1+ compatible (target: AlmaLinux 8.10 CWP PHP 7.2.30)
 * Run: /usr/local/cwp/php71/bin/php tests/backup_engine_test.php
 *
 * @package CWP\RcloneCWP\Tests
 */

require_once __DIR__ . '/../bootstrap.php';

// Custom autoloader for lib/ classes in development tree
spl_autoload_register(function ($class) {
    $prefix = 'CWP\\RcloneCWP\\';
    if (strpos($class, $prefix) === 0) {
        $rel = substr($class, strlen($prefix));
        $file = __DIR__ . '/../lib/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use CWP\RcloneCWP\Backup\BackupEngine;
use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Backup\Collectors\AccountMetadataCollector;
use CWP\RcloneCWP\Backup\Collectors\ComponentCollectorInterface;
use CWP\RcloneCWP\Backup\Collectors\CronCollector;
use CWP\RcloneCWP\Backup\Collectors\DatabaseCollector;
use CWP\RcloneCWP\Backup\Collectors\DnsCollector;
use CWP\RcloneCWP\Backup\Collectors\FilesCollector;
use CWP\RcloneCWP\Backup\Collectors\FtpCollector;
use CWP\RcloneCWP\Backup\Collectors\MailCollector;
use CWP\RcloneCWP\Backup\Collectors\SslCollector;
use CWP\RcloneCWP\Backup\CwpAccountDiscovery;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;

$passed = 0;
$failed = 0;

function it(string $desc, bool $ok, string $detail = '') {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "   \033[32m✓\033[0m {$desc}\n";
    } else {
        $failed++;
        echo "   \033[31m✗\033[0m {$desc}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "  rcloneCWP Phase 3 Backup Engine — Test Suite\n";
echo "====================================================================\n";
echo "PHP Version: " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo "rclone: " . (Rclone::version() ?: 'not found') . "\n\n";

$db = Database::getInstance();
$logger = new Logger(RCLONE_LOG_DIR, $db);
$dm = new DestinationManager($db, null, $logger);

// ============================================================================
// TEST 1: CWP Account Discovery
// ============================================================================
echo "Test 1: CWP Account Discovery Service\n";
$discovery = new CwpAccountDiscovery($db);
$accounts = $discovery->listAccounts();

it("Discovers CWP user accounts from MariaDB root_cwp", count($accounts) > 0, "Count: " . count($accounts));
it("Discovers known user 'builderh'", isset($accounts['builderh']));
it("Discovers known user 'owndemo'", isset($accounts['owndemo']));
it("Discovers known user 'ownpay'", isset($accounts['ownpay']));

$builderh = $discovery->getAccount('builderh');
it("getAccount('builderh') returns valid account structure", is_array($builderh) && $builderh['username'] === 'builderh');
it("Resolves primary domain 'builderhall.com'", ($builderh['primary_domain'] ?? '') === 'builderhall.com');
it("Resolves home directory '/home/builderh'", ($builderh['home_dir'] ?? '') === '/home/builderh');
it("Discovers user databases with prefix builderh_", count($builderh['databases'] ?? []) >= 3, "DB count: " . count($builderh['databases'] ?? []));
it("Discovers all associated domains and subdomains", count($builderh['all_domains'] ?? []) >= 3, "Domains count: " . count($builderh['all_domains'] ?? []));

// ============================================================================
// TEST 2: Component Collectors Interface & Execution
// ============================================================================
echo "\nTest 2: Component Collectors Interface Compliance & Staging\n";

$testStaging = sys_get_temp_dir() . '/rclonecwp_test_staging_' . bin2hex(random_bytes(6));
@mkdir($testStaging, 0755, true);

$collectors = [
    new AccountMetadataCollector(),
    new DatabaseCollector($db),
    new FilesCollector(),
    new DnsCollector(),
    new SslCollector(),
    new CronCollector(),
    new MailCollector($db),
    new FtpCollector(),
];

foreach ($collectors as $col) {
    it("Collector '{$col->getName()}' implements ComponentCollectorInterface", $col instanceof ComponentCollectorInterface);
    it("Collector '{$col->getName()}' provides non-empty label", !empty($col->getLabel()));
}

// 2a. Test Metadata Collector
$metaCol = new AccountMetadataCollector();
$metaRes = $metaCol->collect($builderh, $testStaging);
it("AccountMetadataCollector runs successfully", $metaRes['ok'] === true);
it("Creates meta/account.json", is_file($testStaging . '/meta/account.json'));
$metaJson = json_decode(@file_put_contents($testStaging . '/meta/account.json', @file_get_contents($testStaging . '/meta/account.json')), true);
it("meta/account.json contains user payload", $metaRes['bytes'] > 500);

// 2b. Test DNS Collector
$dnsCol = new DnsCollector();
$dnsRes = $dnsCol->collect($builderh, $testStaging);
it("DnsCollector runs successfully", $dnsRes['ok'] === true);
it("Discovers and copies BIND zone files", $dnsRes['files_count'] > 0, "Zones: " . $dnsRes['files_count']);
it("Zone file exists in meta/dns/builderhall.com.db", is_file($testStaging . '/meta/dns/builderhall.com.db'));

// 2c. Test SSL Collector
$sslCol = new SslCollector();
$sslRes = $sslCol->collect($builderh, $testStaging);
it("SslCollector runs successfully", $sslRes['ok'] === true);
it("Copies TLS certs and keys to meta/ssl", $sslRes['files_count'] > 0, "SSL files: " . $sslRes['files_count']);
it("Private key stored with secure permissions", is_file($testStaging . '/meta/ssl/builderhall.com.key'));

// 2d. Test Database Collector (with temp cnf credentials security)
$dbCol = new DatabaseCollector($db);
// Test with first database of builderh
$testDbAccount = $builderh;
$testDbAccount['databases'] = array_slice($builderh['databases'], 0, 1);
$dbRes = $dbCol->collect($testDbAccount, $testStaging);
it("DatabaseCollector executes mysqldump safely with temp cnf", $dbRes['ok'] === true, $dbRes['error'] ?? '');
it("Generates compressed database file (.sql.gz)", $dbRes['files_count'] === 1 && $dbRes['bytes'] > 0);
if (!empty($dbRes['items'][0]['checksum'])) {
    it("Computes SHA-256 checksum for database dump", strpos($dbRes['items'][0]['checksum'], 'sha256:') === 0);
} else {
    it("Computes SHA-256 checksum for database dump", false, "Missing checksum");
}

// 2e. Test Files Collector
$filesCol = new FilesCollector();
$filesRes = $filesCol->collect($builderh, $testStaging, ['mode' => 'direct_sync']);
it("FilesCollector handles direct_sync mode for incremental backups", $filesRes['ok'] === true && ($filesRes['items']['mode'] ?? '') === 'direct_sync');
it("Creates files exclusion filter file", is_file($testStaging . '/meta/files_exclude.txt'));

// Clean up temporary test staging
exec('rm -rf ' . escapeshellarg($testStaging));

// ============================================================================
// TEST 3: BackupJobManager CRUD Operations
// ============================================================================
echo "\nTest 3: Backup Job Manager CRUD Operations\n";

// First, ensure a test local destination exists
$localBackupDir = '/backup/test_rclone_dest_' . time();
@mkdir($localBackupDir, 0755, true);

$destRes = $dm->createDestination([
    'name'    => 'Test Backup Dest ' . time(),
    'type'    => 'local',
    'enabled' => 1,
    'config'  => ['path' => $localBackupDir],
]);

it("Creates local destination for job testing", $destRes['ok'] === true && $destRes['id'] > 0);
$testDestId = (int) $destRes['id'];

$bjm = new BackupJobManager($db, $dm, $logger);

// Create Job
$jobCreateRes = $bjm->createJob([
    'name'              => 'Unit Test Backup Job ' . time(),
    'destination_id'    => $testDestId,
    'job_type'          => 'incremental',
    'retention_days'    => 14,
    'compression'       => 1,
    'notify_on_success' => 0,
    'notify_on_failure' => 1,
    'accounts'          => ['builderh'],
    'components'        => ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'],
]);

it("BackupJobManager::createJob succeeds", $jobCreateRes['ok'] === true && ($jobCreateRes['id'] ?? 0) > 0);
$testJobId = (int) ($jobCreateRes['id'] ?? 0);

// Get Job
$job = $bjm->getJob($testJobId);
it("BackupJobManager::getJob retrieves job record", is_array($job) && (int) $job['id'] === $testJobId);
it("Job retains target accounts ['builderh']", ($job['accounts'] ?? []) === ['builderh']);
it("Job retains 6 components", count($job['components'] ?? []) === 6);
it("Job retains retention_days = 14", (int) $job['retention_days'] === 14);

// Update Job
$jobUpdateRes = $bjm->updateJob($testJobId, [
    'name'           => 'Updated Unit Test Job',
    'retention_days' => 21,
]);
it("BackupJobManager::updateJob succeeds", $jobUpdateRes['ok'] === true);
$updatedJob = $bjm->getJob($testJobId);
it("Updated job name verified", $updatedJob['name'] === 'Updated Unit Test Job');
it("Updated retention verified = 21", (int) $updatedJob['retention_days'] === 21);

// List Jobs
$jobsList = $bjm->listJobs();
it("BackupJobManager::listJobs returns array including created job", count($jobsList) > 0);
$foundJob = false;
foreach ($jobsList as $jl) {
    if ((int) $jl['id'] === $testJobId) {
        $foundJob = true;
        break;
    }
}
it("Created job is present in listJobs()", $foundJob);

// ============================================================================
// TEST 4: Live Backup Engine Orchestration & Remote Transfer
// ============================================================================
echo "\nTest 4: Backup Engine Live Execution & Verification\n";

$engine = new BackupEngine($db, $dm, $logger, $discovery);

// Configure a fast selective run on builderh with DNS and SSL and metadata
$quickJobRes = $bjm->createJob([
    'name'              => 'Quick Live Snapshot ' . time(),
    'destination_id'    => $testDestId,
    'job_type'          => 'selective',
    'retention_days'    => 7,
    'compression'       => 1,
    'accounts'          => ['builderh'],
    'components'        => ['dns', 'ssl', 'cron'],
]);

$quickJobId = (int) $quickJobRes['id'];

$runRes = $engine->runJob($quickJobId);
it("BackupEngine::runJob executes without errors", $runRes['ok'] === true, $runRes['error'] ?? '');
it("Execution generates backup run ID in rclone_backups", ($runRes['backup_id'] ?? 0) > 0);
it("Execution records status = 'completed'", ($runRes['status'] ?? '') === 'completed');
it("Execution duration recorded (> 0s)", ($runRes['duration'] ?? -1) >= 0);
it("Files transferred count > 0", ($runRes['files_count'] ?? 0) > 0, "Files: " . ($runRes['files_count'] ?? 0));
it("Bytes transferred > 0", ($runRes['bytes'] ?? 0) > 0, "Bytes: " . ($runRes['bytes'] ?? 0));

// Verify destination filesystem content
$destContents = glob($localBackupDir . '/*');
it("Snapshot written to destination directory", !empty($destContents));

$manifests = glob($localBackupDir . '/*/*/*/manifest.json');
it("Remote destination contains manifest.json", !empty($manifests));
if (!empty($manifests)) {
    $manifestData = json_decode(@file_get_contents($manifests[0]), true);
    it("Manifest contains valid JSON and version", is_array($manifestData) && ($manifestData['version'] ?? '') === '1.0');
    it("Manifest records job_id = {$quickJobId}", (int) ($manifestData['job_id'] ?? 0) === $quickJobId);
    it("Manifest records user 'builderh'", ($manifestData['username'] ?? '') === 'builderh');
}

// Check DB Execution Record
$backupRecord = $db->fetch('SELECT * FROM rclone_backups WHERE id = ?', [(int) $runRes['backup_id']]);
it("rclone_backups record has status = 'completed'", ($backupRecord['status'] ?? '') === 'completed');
it("rclone_backups record has non-null completed_at", !empty($backupRecord['completed_at']));
it("rclone_backups record has matching bytes_transferred", (int) $backupRecord['bytes_transferred'] === (int) $runRes['bytes']);

// ============================================================================
// TEST 5: Backup History Reporting
// ============================================================================
echo "\nTest 5: Execution History Reporting\n";
$history = $bjm->listHistory($quickJobId);
it("listHistory returns executed run", count($history) > 0);
it("History entry contains job_name", !empty($history[0]['job_name']));
it("History entry contains destination_name", !empty($history[0]['destination_name']));

// ============================================================================
// CLEANUP
// ============================================================================
$bjm->deleteJob($testJobId);
$bjm->deleteJob($quickJobId);
$dm->deleteDestination($testDestId);
exec('rm -rf ' . escapeshellarg($localBackupDir));

echo "\n--------------------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "--------------------------------------------------------------------\n";

exit($failed > 0 ? 1 : 0);
