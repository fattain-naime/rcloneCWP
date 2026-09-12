<?php
/**
 * rcloneCWP Phase 2 Destinations Engine Verification Test Suite
 *
 * Comprehensive automated assertions testing all 12 storage providers,
 * AES-256-GCM encryption/decryption, SSRF protection, path traversal defenses,
 * dynamic in-memory rclone execution, and MariaDB CRUD operations.
 *
 * Usage: /usr/local/cwp/php71/bin/php tests/destinations_test.php
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Tests
 */

namespace CWP\RcloneCWP\Tests;

// CLI environment check
if (php_sapi_name() !== 'cli') {
    die("CLI execution only\n");
}

require_once __DIR__ . '/../bootstrap.php';

use CWP\RcloneCWP\CSRF;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationInterface;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Encryption;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;
use CWP\RcloneCWP\Validator;

class DestinationsTestSuite
{
    private $passed = 0;
    private $failed = 0;
    private $db;
    private $dm;
    private $encryption;
    private $tempDir;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->dm = new DestinationManager($this->db, $this->encryption);
        $this->tempDir = '/tmp/rcloneCWP_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    public function __destruct()
    {
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    private function assert($condition, $message)
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✓\033[0m {$message}\n";
        } else {
            $this->failed++;
            echo "  \033[31m✗ FAIL:\033[0m {$message}\n";
        }
    }

    public function run()
    {
        echo "\n====================================================================\n";
        echo "  rcloneCWP Phase 2 Destinations Engine — Test Suite\n";
        echo "====================================================================\n";
        echo "PHP Version: " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
        echo "rclone: " . (Rclone::version() ?: 'not found') . "\n";
        echo "Keyfile: " . (Encryption::hasKeyFile() ? 'Present (0600)' : 'Missing') . "\n\n";

        $this->testProviderRegistry();
        $this->testSecurityAndValidation();
        $this->testEncryptionAtRest();
        $this->testDynamicRcloneEnvironment();
        $this->testDatabaseCrudAndLifecycle();
        $this->testLiveLocalConnection();

        echo "\n--------------------------------------------------------------------\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n";
        echo "--------------------------------------------------------------------\n\n";

        return $this->failed === 0;
    }

    /**
     * Test 1: Verify all 12 providers are registered and implement DestinationInterface
     */
    private function testProviderRegistry()
    {
        echo "Test 1: Provider Registry & Interface Compliance\n";

        $expectedTypes = [
            'local', 's3', 'sftp', 'ftp', 'webdav', 'b2',
            'gs', 'azure', 'dropbox', 'onedrive', 'swift', 'tencent'
        ];

        $available = $this->dm->getAvailableTypes();
        $this->assert(count($available) === 12, "DestinationManager registers exactly 12 unique provider types");

        foreach ($expectedTypes as $type) {
            $this->assert(isset($available[$type]), "Provider '{$type}' is present in available types");
            $provider = $this->dm->getProvider($type);
            $this->assert($provider instanceof DestinationInterface, "Provider '{$type}' implements DestinationInterface");
            $this->assert(!empty($provider->getName()), "Provider '{$type}' returns a friendly name: '{$provider->getName()}'");
            $this->assert(!empty($provider->getRcloneType()), "Provider '{$type}' returns rclone backend type: '{$provider->getRcloneType()}'");

            $fields = $provider->getConfigFields();
            $this->assert(is_array($fields) && !empty($fields), "Provider '{$type}' defines configuration fields (" . count($fields) . " fields)");
        }
    }

    /**
     * Test 2: Input validation, SSRF protection, and Path Traversal defenses
     */
    private function testSecurityAndValidation()
    {
        echo "\nTest 2: Security Defenses (SSRF, Path Traversal, Malformed Inputs)\n";

        // 2a: LocalDestination path traversal and root denial
        $local = $this->dm->getProvider('local');

        $traversalRes = $local->validateConfig(['path' => '/var/backup/../../etc/passwd']);
        $this->assert(!$traversalRes['valid'], "LocalDestination blocks directory traversal ('..')");

        $rootRes = $local->validateConfig(['path' => '/']);
        $this->assert(!$rootRes['valid'], "LocalDestination blocks root filesystem ('/')");

        $sysRes = $local->validateConfig(['path' => '/etc/cron.d']);
        $this->assert(!$sysRes['valid'], "LocalDestination blocks critical system paths ('/etc/cron.d')");

        $validLocal = $local->validateConfig(['path' => '/backup/cwp_daily']);
        $this->assert($validLocal['valid'], "LocalDestination allows valid backup path ('/backup/cwp_daily')");

        // 2b: S3Destination SSRF prevention
        $s3 = $this->dm->getProvider('s3');
        $s3Ssrf = $s3->validateConfig([
            'provider'          => 'Minio',
            'endpoint'          => 'http://169.254.169.254/latest/meta-data',
            'access_key_id'     => 'AKIAEXAMPLE',
            'secret_access_key' => 'secret123',
            'bucket'            => 'cwp-test-bucket',
        ]);
        $this->assert(!$s3Ssrf['valid'], "S3Destination blocks AWS/cloud metadata IP (169.254.169.254)");

        $s3Loopback = $s3->validateConfig([
            'provider'          => 'Minio',
            'endpoint'          => 'http://127.0.0.1:8080',
            'access_key_id'     => 'AKIAEXAMPLE',
            'secret_access_key' => 'secret123',
            'bucket'            => 'cwp-test-bucket',
        ]);
        $this->assert(!$s3Loopback['valid'], "S3Destination blocks loopback endpoint (127.0.0.1)");

        $s3Valid = $s3->validateConfig([
            'provider'          => 'AWS',
            'access_key_id'     => 'AKIAEXAMPLE',
            'secret_access_key' => 'secret123',
            'bucket'            => 'cwp-valid-bucket',
            'region'            => 'us-east-1',
        ]);
        $this->assert($s3Valid['valid'], "S3Destination accepts valid standard AWS S3 configuration");

        // 2c: WebdavDestination SSRF prevention
        $webdav = $this->dm->getProvider('webdav');
        $webdavSsrf = $webdav->validateConfig([
            'url'  => 'http://169.254.169.254/meta',
            'user' => 'admin',
            'pass' => 'secret',
        ]);
        $this->assert(!$webdavSsrf['valid'], "WebdavDestination blocks cloud metadata SSRF (169.254.169.254)");

        $webdavValid = $webdav->validateConfig([
            'url'    => 'https://nextcloud.example.com/remote.php/dav/files/admin/',
            'vendor' => 'nextcloud',
            'user'   => 'admin',
            'pass'   => 'secret',
        ]);
        $this->assert($webdavValid['valid'], "WebdavDestination accepts valid public HTTPS URL");
    }

    /**
     * Test 3: At-rest AES-256-GCM encryption and in-memory decryption
     */
    private function testEncryptionAtRest()
    {
        echo "\nTest 3: At-Rest AES-256-GCM Encryption & Decryption\n";

        $s3 = $this->dm->getProvider('s3');
        $plaintextSecret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY_SuperSecret123!';

        $rawConfig = [
            'provider'          => 'AWS',
            'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => $plaintextSecret,
            'bucket'            => 'my-backup-bucket',
            'region'            => 'us-east-1',
        ];

        $encryptedConfig = $s3->encryptConfig($rawConfig);

        $this->assert($encryptedConfig['secret_access_key'] !== $plaintextSecret, "Sensitive field 'secret_access_key' is encrypted");
        $rawCipher = base64_decode($encryptedConfig['secret_access_key'], true);
        $this->assert($rawCipher !== false && strlen($rawCipher) > 28, "Ciphertext contains valid binary IV, authentication tag, and encrypted payload");

        // Verify JSON encoding of encrypted config does NOT leak secret
        $jsonDump = json_encode($encryptedConfig);
        $this->assert(strpos($jsonDump, $plaintextSecret) === false, "Raw serialized JSON contains zero occurrences of plaintext secret");

        // Decrypt in-memory
        $decryptedConfig = $s3->decryptConfig($encryptedConfig);
        $this->assert($decryptedConfig['secret_access_key'] === $plaintextSecret, "Decrypted secret matches original plaintext exactly");
    }

    /**
     * Test 4: Dynamic in-memory environment variable generation for proc_open
     */
    private function testDynamicRcloneEnvironment()
    {
        echo "\nTest 4: Dynamic rclone Environment Generation (proc_open injection)\n";

        $remoteName = 'cwp_temp_remote';

        // 4a: Test S3
        $s3 = $this->dm->getProvider('s3');
        $s3Config = [
            'provider'          => 'AWS',
            'access_key_id'     => 'AKIAEXAMPLE',
            'secret_access_key' => 'mysecretkey',
            'region'            => 'eu-central-1',
            'bucket'            => 'prod-backups',
            'prefix'            => 'cwp-daily',
        ];
        $s3Env = $s3->getRcloneEnv($s3Config, $remoteName);
        $this->assert($s3Env['RCLONE_CONFIG_CWP_TEMP_REMOTE_TYPE'] === 's3', "S3 env defines TYPE = s3");
        $this->assert($s3Env['RCLONE_CONFIG_CWP_TEMP_REMOTE_ACCESS_KEY_ID'] === 'AKIAEXAMPLE', "S3 env defines ACCESS_KEY_ID");
        $this->assert($s3Env['RCLONE_CONFIG_CWP_TEMP_REMOTE_SECRET_ACCESS_KEY'] === 'mysecretkey', "S3 env defines SECRET_ACCESS_KEY");
        $this->assert($s3->getRemoteTarget($s3Config, $remoteName) === 'cwp_temp_remote:prod-backups/cwp-daily', "S3 getRemoteTarget generates correct remote path");

        // 4b: Test B2
        $b2 = $this->dm->getProvider('b2');
        $b2Config = [
            'account' => '0011223344',
            'key'     => 'K001deadbeef',
            'bucket'  => 'backblaze-cwp',
            'prefix'  => 'accounts',
        ];
        $b2Env = $b2->getRcloneEnv($b2Config, $remoteName);
        $this->assert($b2Env['RCLONE_CONFIG_CWP_TEMP_REMOTE_TYPE'] === 'b2', "B2 env defines TYPE = b2");
        $this->assert($b2Env['RCLONE_CONFIG_CWP_TEMP_REMOTE_ACCOUNT'] === '0011223344', "B2 env defines ACCOUNT");
        $this->assert($b2Env['RCLONE_CONFIG_CWP_TEMP_REMOTE_KEY'] === 'K001deadbeef', "B2 env defines KEY");

        // 4c: Test SFTP
        $sftp = $this->dm->getProvider('sftp');
        $sftpConfig = [
            'host' => 'storage.example.com',
            'port' => '22',
            'user' => 'cwpbackup',
            'pass' => 'sshpass123',
            'path' => '/home/cwpbackup/data',
        ];
        $sftpEnv = $sftp->getRcloneEnv($sftpConfig, $remoteName);
        $this->assert($sftpEnv['RCLONE_CONFIG_CWP_TEMP_REMOTE_TYPE'] === 'sftp', "SFTP env defines TYPE = sftp");
        $this->assert($sftpEnv['RCLONE_CONFIG_CWP_TEMP_REMOTE_HOST'] === 'storage.example.com', "SFTP env defines HOST");
        $this->assert($sftp->getRemoteTarget($sftpConfig, $remoteName) === 'cwp_temp_remote:/home/cwpbackup/data', "SFTP getRemoteTarget formatted correctly");

        // 4d: Test Azure Blob
        $azure = $this->dm->getProvider('azure');
        $azureConfig = [
            'account'   => 'cwpstorage',
            'key'       => 'azureKey123',
            'container' => 'backups',
            'prefix'    => 'server1',
        ];
        $azureEnv = $azure->getRcloneEnv($azureConfig, $remoteName);
        $this->assert($azureEnv['RCLONE_CONFIG_CWP_TEMP_REMOTE_TYPE'] === 'azureblob', "Azure env defines TYPE = azureblob");
        $this->assert($azure->getRemoteTarget($azureConfig, $remoteName) === 'cwp_temp_remote:backups/server1', "Azure target formatted correctly");
    }

    /**
     * Test 5: MariaDB CRUD Lifecycle via DestinationManager
     */
    private function testDatabaseCrudAndLifecycle()
    {
        echo "\nTest 5: MariaDB CRUD Operations & Destination Management\n";

        $testName = 'Automated Test Local Destination ' . bin2hex(random_bytes(4));
        $targetPath = $this->tempDir . '/backup_target';

        // 5a: CREATE
        $createRes = $this->dm->createDestination([
            'name'    => $testName,
            'type'    => 'local',
            'enabled' => 1,
            'config'  => ['path' => $targetPath],
        ]);
        $this->assert($createRes['ok'], "DestinationManager::createDestination succeeds: ID = " . ($createRes['id'] ?? 0));
        $destId = (int)($createRes['id'] ?? 0);
        $this->assert($destId > 0, "Created destination returned valid positive integer ID");

        // 5b: READ (getDestination)
        $fetched = $this->dm->getDestination($destId, false);
        $this->assert($fetched !== null, "getDestination returns record from DB");
        $this->assert($fetched['name'] === $testName, "Fetched record name matches created name");
        $this->assert($fetched['type'] === 'local', "Fetched record type is 'local'");
        $this->assert((int)$fetched['enabled'] === 1, "Fetched record enabled flag is 1");

        // 5c: UPDATE
        $updatedName = $testName . ' (Updated)';
        $updateRes = $this->dm->updateDestination($destId, [
            'name'    => $updatedName,
            'enabled' => 0,
            'config'  => ['path' => $targetPath . '_v2'],
        ]);
        $this->assert($updateRes['ok'], "DestinationManager::updateDestination succeeds");

        $fetchedUpdated = $this->dm->getDestination($destId, false);
        $this->assert($fetchedUpdated['name'] === $updatedName, "Updated record name verified in DB");
        $this->assert((int)$fetchedUpdated['enabled'] === 0, "Updated record enabled status is 0");

        // 5d: TOGGLE STATUS
        $toggleRes = $this->dm->toggleDestination($destId, true);
        $this->assert($toggleRes['ok'], "toggleDestination to enabled succeeds");
        $fetchedToggled = $this->dm->getDestination($destId, false);
        $this->assert((int)$fetchedToggled['enabled'] === 1, "Enabled flag in DB toggled back to 1");

        // 5e: LIST
        $list = $this->dm->listDestinations();
        $found = false;
        foreach ($list as $item) {
            if ((int)$item['id'] === $destId) {
                $found = true;
                break;
            }
        }
        $this->assert($found, "Created destination appears in listDestinations()");

        // 5f: DELETE
        $deleteRes = $this->dm->deleteDestination($destId);
        $this->assert($deleteRes['ok'], "DestinationManager::deleteDestination succeeds");

        $fetchedAfterDelete = $this->dm->getDestination($destId, false);
        $this->assert($fetchedAfterDelete === null, "Destination no longer exists in DB after deletion");
    }

    /**
     * Test 6: Real live connection test on local directory with rclone
     */
    private function testLiveLocalConnection()
    {
        echo "\nTest 6: Live rclone Connection Test (Local Filesystem Provider)\n";

        $testDir = $this->tempDir . '/live_test_store';
        if (!is_dir($testDir)) {
            mkdir($testDir, 0755, true);
        }

        // Test raw unsaved config
        $rawTest = $this->dm->testRawConfig('local', ['path' => $testDir]);
        $this->assert($rawTest['ok'], "testRawConfig on live local path returns ok = true ('{$rawTest['message']}')");
        $this->assert(isset($rawTest['details']['output']), "testRawConfig returns output details array");

        // Create saved destination and test via testDestination($id)
        $createRes = $this->dm->createDestination([
            'name'    => 'Live Local Test Target',
            'type'    => 'local',
            'enabled' => 1,
            'config'  => ['path' => $testDir],
        ]);
        $destId = (int)($createRes['id'] ?? 0);

        $testResult = $this->dm->testDestination($destId);
        $this->assert($testResult['ok'], "testDestination({$destId}) executes live rclone lsjson successfully: {$testResult['message']}");

        // Verify that _last_tested metadata was updated in DB
        $savedRecord = $this->dm->getDestination($destId, false);
        $this->assert(!empty($savedRecord['config']['_last_tested']), "Database records '_last_tested' timestamp ({$savedRecord['config']['_last_tested']})");
        $this->assert($savedRecord['config']['_last_test_ok'] === true, "Database records '_last_test_ok' = true");

        // Cleanup
        $this->dm->deleteDestination($destId);
        @rmdir($testDir);
    }
}

// Execute test runner
$suite = new DestinationsTestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
