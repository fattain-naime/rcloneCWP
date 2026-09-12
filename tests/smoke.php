<?php
/**
 * rcloneCWP Phase 1 Smoke Test
 *
 * One CLI run asserting every acceptance criterion from
 * docs/implementation-plan/01-phase1-foundation.md §5.
 *
 * Usage: /usr/local/cwp/php71/bin/php tests/smoke.php
 * @package CWP\RcloneCWP
 */

if (PHP_SAPI !== 'cli') {
    exit('Run from the shell.');
}

require_once __DIR__ . '/../bootstrap.php';

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Encryption;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;
use CWP\RcloneCWP\SchemaLoader;
use CWP\RcloneCWP\Validator;
use CWP\RcloneCWP\CSRF;

$pass = 0;
$fail = 0;
$failures = [];

/**
 * Assert helper
 *
 * @param string $name Test name
 * @param bool $cond Condition to assert
 * @return void
 */
function check($name, $cond)
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        $failures[] = $name;
        echo "  FAIL  $name\n";
    }
}

echo "=== rcloneCWP Phase 1 Smoke Test ===\n\n";

// ------------------------------------------------------------------------
echo "[1] Environment\n";
// ------------------------------------------------------------------------
check('PHP >= 7.1 (floor)', version_compare(PHP_VERSION, '7.1.0', '>='));
check('openssl extension loaded', extension_loaded('openssl'));
check('pdo_mysql extension loaded', extension_loaded('pdo_mysql'));
check('rclone binary detected', Rclone::findBinary() !== null);

// ------------------------------------------------------------------------
echo "[2] Database connects to root_cwp\n";
// ------------------------------------------------------------------------
try {
    $db = Database::getInstance();
    $version = $db->getConnection()->query('SELECT VERSION()')->fetchColumn();
    check('Database connects', true);
    echo "        MariaDB/MySQL: $version\n";
    check('Connected to root_cwp', $db->getConfig()['name'] === 'root_cwp');
} catch (\Exception $e) {
    check('Database connects', false);
    echo '        ' . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------------
echo "[3] Schema: all 8 rclone_* tables\n";
// ------------------------------------------------------------------------
try {
    $tables = $db->fetchAll(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'rclone_%'",
        [$db->getConfig()['name']]
    );
    $tableNames = array_column($tables, 'TABLE_NAME');
    sort($tableNames);
    check('8 rclone_* tables exist', count($tableNames) === 8);
    echo '        Found: ' . implode(', ', $tableNames) . "\n";
    check("rclone_backups (not rcloneCWPs) named correctly", in_array('rclone_backups', $tableNames, true));
} catch (\Exception $e) {
    check('8 rclone_* tables exist', false);
    echo '        ' . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------------
echo "[4] Encryption: AES-256-GCM round-trip\n";
// ------------------------------------------------------------------------
try {
    // Use an isolated test key so we never touch the production keyfile
    $testKeyFile = sys_get_temp_dir() . '/rcloneCWP_test_key_' . getmypid() . '.bin';
    @unlink($testKeyFile);
    Encryption::generateKey($testKeyFile);
    check('Key file created, 0600', substr(sprintf('%o', fileperms($testKeyFile)), -3) === '600');
    check('Key is 32 bytes', strlen(file_get_contents($testKeyFile)) === 32);

    $enc = new Encryption(null, $testKeyFile);
    $secret = 's3cr3t-passw0rd!@#';
    $cipher = $enc->encrypt($secret);
    check('encrypt() returns base64', base64_decode($cipher, true) !== false);
    check('round-trip: decrypt(encrypt(x)) === x', $enc->decrypt($cipher) === $secret);
    check('decrypt("invalid") === false', $enc->decrypt('invalid') === false);
    check('decrypt("") === false', $enc->decrypt('') === false);
    check('two encryptions differ (random nonce)', $enc->encrypt($secret) !== $cipher);
    @unlink($testKeyFile);
} catch (\Exception $e) {
    check('Encryption round-trip', false);
    echo '        ' . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------------
echo "[5] Production key file (0600, root-only)\n";
// ------------------------------------------------------------------------
check('Key file exists at RCLONE_KEYFILE', is_file(RCLONE_KEYFILE));
if (is_file(RCLONE_KEYFILE)) {
    check('Key file mode 0600', substr(sprintf('%o', fileperms(RCLONE_KEYFILE)), -3) === '600');
}

// ------------------------------------------------------------------------
echo "[6] Rclone wrapper: whitelist + injection rejection\n";
// ------------------------------------------------------------------------
try {
    check('whitelisted command runs (version)', Rclone::version() !== null);
    echo '        ' . Rclone::version() . "\n";

    $rejected = 0;
    $attempts = [
        ['--config', ['/etc/passwd']],                          // subcommand injection
        ['ls', ['-injected']],                                  // arg starting with -
        ['ls', ['remote:dir'], ['--password']],                  // forbidden flag key... (not in map)
        ['ls', ['remote:dir'], ['no_such_flag']],               // unknown flag key
    ];
    foreach ($attempts as $a) {
        try {
            call_user_func_array(['CWP\RcloneCWP\Rclone', 'execute'], $a);
        } catch (\InvalidArgumentException $e) {
            $rejected++;
        }
    }
    check('all 4 injection attempts rejected', $rejected === 4);

    // config show must be denied outright
    try {
        Rclone::execute('config', ['show']);
        check('config show denied', false);
    } catch (\InvalidArgumentException $e) {
        check('config show denied', true);
    }

    // listremotes is safe and allowed
    $remotes = Rclone::listRemotes();
    check('listremotes allowed (no secrets)', is_array($remotes));
} catch (\Exception $e) {
    check('Rclone wrapper', false);
    echo '        ' . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------------
echo "[7] Validator\n";
// ------------------------------------------------------------------------
check('string: valid', Validator::string('hello', 50) === 'hello');
check('string: rejects > max', Validator::string(str_repeat('a', 51), 50) === null);
check('string: rejects empty', Validator::string('', 50) === null);
check('int: valid', Validator::int('42', 0, 100) === 42);
check('int: rejects out of range', Validator::int('999', 0, 100) === null);
check('int: rejects non-numeric', Validator::int('abc', 0, 100) === null);
check('enum: valid', Validator::enum('s3', ['s3', 'b2']) === 's3');
check('enum: rejects invalid', Validator::enum('s4', ['s3', 'b2']) === null);
check('remoteName: valid', Validator::remoteName('my-remote_1.a') === 'my-remote_1.a');
check('remoteName: rejects leading dash', Validator::remoteName('-evil') === null);
check('bool: parses true', Validator::bool('true') === true);
check('cron: valid */5', Validator::cron('*/5 * * * *') !== null);
check('cron: rejects 4 fields', Validator::cron('*/5 * * *') === null);

// ------------------------------------------------------------------------
echo "[8] CSRF\n";
// ------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$t1 = CSRF::generateToken();
check('token generated (64 hex chars)', strlen($t1) === 64 && ctype_xdigit($t1));
check('validateToken: correct token', CSRF::validateToken($t1) === true);
check('validateToken: wrong token', CSRF::validateToken(str_repeat('0', 64)) === false);
$t2 = CSRF::generateToken();
check('validateAndConsumeToken: one-time use', CSRF::validateAndConsumeToken($t2) === true && CSRF::validateToken($t2) === false);

// ------------------------------------------------------------------------
echo "[9] Logger: file + rclone_logs row\n";
// ------------------------------------------------------------------------
try {
    $markerMsg = 'smoke-test ' . uniqid('log_', true);
    $logger = new Logger(RCLONE_LOG_DIR, $db);
    $logger->info($markerMsg);

    $logFile = RCLONE_LOG_DIR . '/rcloneCWP.' . date('Y-m-d') . '.log';
    check('log file written', is_file($logFile));
    $wroteFile = is_file($logFile) && strpos(file_get_contents($logFile), $markerMsg) !== false;
    check('log line appears in file', $wroteFile);

    $row = $db->fetch('SELECT id, level, message FROM rclone_logs WHERE message = ? ORDER BY id DESC LIMIT 1', [$markerMsg]);
    check('rclone_logs row written', $row !== null && $row['message'] === $markerMsg);
} catch (\Exception $e) {
    check('Logger', false);
    echo '        ' . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------------
echo "[10] SchemaLoader: quote-aware splitter\n";
// ------------------------------------------------------------------------
$testSql = "CREATE TABLE a (x INT);\n-- comment with ; semicolon\nCREATE TABLE b (y INT); INSERT INTO b VALUES ('has ; semicolon');\n/* block ; comment */ CREATE TABLE c (z INT)";
$stmts = SchemaLoader::splitStatements($testSql);
check('splitter: 4 statements (quotes/comments respected)', count($stmts) === 4);
$last = end($stmts);
check('splitter: trailing statement without ; kept', strpos($last, 'CREATE TABLE c') === 0);
check('splitter: string-literal ; preserved', (bool) array_filter($stmts, function ($s) {
    return strpos($s, "'has ; semicolon'") !== false;
}));
$repoCount = SchemaLoader::countStatements(__DIR__ . '/../sql/install.sql');
check('install.sql statement count > 5', $repoCount > 5);
echo "        install.sql: $repoCount statements\n";

// ------------------------------------------------------------------------
echo "[11] Module entry guard (negative test)\n";
// ------------------------------------------------------------------------
$moduleFile = RCLONE_MODULES_DIR . '/rcloneCWP.php';
check('module file deployed', is_file($moduleFile));
// Negative test: include the module with NO $include_path set in a fresh
// process — it must print 'invalid access' (first guard) and stop. We check
// the output text, not the exit code: both guards exit 0 via exit().
$probeCode = '$_SERVER["REQUEST_URI"] = "/"; include '
    . var_export(RCLONE_MODULES_DIR . '/rcloneCWP.php', true) . ';';
$cmd = '/usr/local/cwp/php71/bin/php -d display_errors=0 -r ' . escapeshellarg($probeCode) . ' 2>/dev/null';
exec($cmd, $probeOut, $probeCode2);
$guardOutput = implode("\n", $probeOut);
check('module entry refuses direct access (no $include_path)', strpos($guardOutput, 'invalid access') !== false);
check('module entry: first guard fires (not second)', strpos($guardOutput, 'authentication required') === false);

// ------------------------------------------------------------------------
echo "[12] CLI gates on install.php / uninstall.php\n";
// ------------------------------------------------------------------------
// Under CLI SAPI the gate passes; verify the gate logic exists by grepping the files
$installSrc = file_get_contents(__DIR__ . '/../install.php');
$uninstallSrc = file_get_contents(__DIR__ . '/../uninstall.php');
check('install.php has CLI gate', strpos($installSrc, "PHP_SAPI !== 'cli'") !== false);
check('uninstall.php has CLI gate', strpos($uninstallSrc, "PHP_SAPI !== 'cli'") !== false);

// ------------------------------------------------------------------------
echo "[13] No CWP core file modified (this run)\n";
// ------------------------------------------------------------------------
// 3rdparty.php must contain exactly one rcloneCWP marker block
$thirdParty = file_get_contents('/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php');
$beginCount = substr_count($thirdParty, '<!-- rcloneCWP menu entry (begin) -->');
check('3rdparty.php: exactly one menu block', $beginCount === 1);
check('3rdparty.php: original include line intact', strpos($thirdParty, "include('/usr/local/cwpsrv/htdocs/resources/admin/include/configserver.php')") !== false);

// ------------------------------------------------------------------------
echo "\n=== RESULT: $pass passed, $fail failed ===\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
