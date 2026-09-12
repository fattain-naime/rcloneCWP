<?php
/**
 * rcloneCWP Phase 7 API & CLI Integration Tests
 *
 * Verifies REST endpoints, CLI commands, and standalone wrappers.
 * Run with: php tests/api_cli_test.php
 */

require_once __DIR__ . '/../bootstrap.php';

use CWP\RcloneCWP\API;
use CWP\RcloneCWP\CLI;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Scheduling\ScheduleManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  \033[31m✗\033[0m $name: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assertEq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new Exception("$msg: expected " . json_encode($expected) . ", got " . json_encode($actual));
    }
}

function assertTrue($cond, string $msg = ''): void {
    if (!$cond) throw new Exception("$msg: expected truthy");
}

function assertFalse($cond, string $msg = ''): void {
    if ($cond) throw new Exception("$msg: expected falsy");
}

function assertContains($needle, $haystack, string $msg = ''): void {
    if (is_array($haystack)) {
        $found = in_array($needle, $haystack, true);
    } else {
        $found = strpos((string)$haystack, (string)$needle) !== false;
    }
    if (!$found) throw new Exception("$msg: '$needle' not found");
}

echo "\n=== Phase 7: API & CLI Tests ===\n\n";

// ---------------------------------------------------------------------------
// CLI Tests
// ---------------------------------------------------------------------------

echo "CLI Engine:\n";

// CLI::version()
test('CLI::version() returns string', function() {
    $ref = new ReflectionClass(CLI::class);
    $method = $ref->getMethod('version');
    $method->setAccessible(true);
    $version = $method->invoke(new CLI());
    assertTrue(is_string($version) && strlen($version) > 0);
});

// CLI::main() with help
test('CLI::main() help command returns exit 0', function() {
    ob_start();
    $code = CLI::main(['cli', 'help']);
    $output = ob_get_clean();
    assertEq(0, $code);
    assertContains('rcloneCWP CLI', $output);
});

// CLI::main() with unknown command
test('CLI::main() unknown command returns exit 1', function() {
    ob_start();
    $code = CLI::main(['cli', 'nonexistentcmd']);
    $output = ob_get_clean();
    assertEq(1, $code);
});

// CLI parsing flags --json
test('CLI parses --json flag', function() {
    $cli = new CLI(['status', '--json']);
    $ref = new ReflectionClass($cli);
    $prop = $ref->getProperty('json');
    $prop->setAccessible(true);
    assertTrue($prop->getValue($cli) === true);
});

// CLI parsing flags --no-color
test('CLI parses --no-color flag', function() {
    $cli = new CLI(['status', '--no-color']);
    $ref = new ReflectionClass($cli);
    $prop = $ref->getProperty('noColor');
    $prop->setAccessible(true);
    assertTrue($prop->getValue($cli) === true);
});

// CLI table rendering
test('CLI table() formats rows correctly', function() {
    $cli = new CLI();
    $ref = new ReflectionClass($cli);
    $method = $ref->getMethod('table');
    $method->setAccessible(true);
    ob_start();
    $method->invoke($cli, ['A', 'B'], [['1', '2'], ['3', '4']]);
    $output = ob_get_clean();
    assertContains('1', $output);
    assertContains('2', $output);
});

// CLI humanBytes
test('CLI::humanBytes() formats correctly', function() {
    $cli = new CLI();
    $ref = new ReflectionClass($cli);
    $method = $ref->getMethod('humanBytes');
    $method->setAccessible(true);
    assertEq('1 KB', $method->invoke($cli, 1024));
    assertEq('1 MB', $method->invoke($cli, 1024 * 1024));
});

// ---------------------------------------------------------------------------
// API Tests (unit-level: authenticate, permissions, rate limit)
// ---------------------------------------------------------------------------

echo "\nAPI Engine:\n";

test('API constructs without errors', function() {
    $api = new API();
    assertTrue($api instanceof API);
});

test('API::getDatabase() returns Database instance', function() {
    $api = new API();
    $db = $api->getDatabase();
    assertTrue($db instanceof Database);
});

test('API::getLogger() returns Logger instance', function() {
    $api = new API();
    $logger = $api->getLogger();
    assertTrue($logger instanceof Logger);
});

test('API::hasPermission() respects * wildcard', function() {
    $api = new API();
    $ref = new ReflectionClass($api);
    $prop = $ref->getProperty('currentPermissions');
    $prop->setAccessible(true);
    $prop->setValue($api, ['*']);
    assertTrue($api->hasPermission('backup:run'));
});

test('API::hasPermission() respects category wildcard', function() {
    $api = new API();
    $ref = new ReflectionClass($api);
    $prop = $ref->getProperty('currentPermissions');
    $prop->setAccessible(true);
    $prop->setValue($api, ['backup:*']);
    assertTrue($api->hasPermission('backup:run'));
    assertTrue($api->hasPermission('backup:list'));
    assertFalse($api->hasPermission('destination:list'));
});

test('API::hasPermission() exact match', function() {
    $api = new API();
    $ref = new ReflectionClass($api);
    $prop = $ref->getProperty('currentPermissions');
    $prop->setAccessible(true);
    $prop->setValue($api, ['job:read']);
    assertTrue($api->hasPermission('job:read'));
    assertFalse($api->hasPermission('job:create'));
});

test('API::requirePermission() throws on missing', function() {
    $api = new API();
    $ref = new ReflectionClass($api);
    $prop = $ref->getProperty('currentPermissions');
    $prop->setAccessible(true);
    $prop->setValue($api, []);
    try {
        $api->requirePermission('backup:run');
        throw new Exception('Expected exception not thrown');
    } catch (Exception $e) {
        assertEq(403, $e->getCode());
    }
});

test('API::checkRateLimit() returns expected structure', function() {
    $api = new API();
    $ref = new ReflectionClass($api);
    $prop = $ref->getProperty('currentApiKey');
    $prop->setAccessible(true);
    $prop->setValue($api, ['id' => 999999]);
    $res = $api->checkRateLimit(10);
    assertTrue(isset($res['ok']));
});

// ---------------------------------------------------------------------------
// Rclone wrapper tests
// ---------------------------------------------------------------------------

echo "\nRclone Wrapper:\n";

test('Rclone::findBinary() returns path or null', function() {
    $bin = Rclone::findBinary();
    assertTrue($bin === null || is_string($bin));
});

test('Rclone::version() returns array or string', function() {
    $v = Rclone::version();
    assertTrue(is_array($v) || is_string($v));
});

// ---------------------------------------------------------------------------
// Subsystem Integration (CLI -> subsystems)
// ---------------------------------------------------------------------------

echo "\nCLI Subsystem Integration:\n";

test('CLI::status --json outputs valid JSON', function() {
    $cli = new CLI(['status', '--json']);
    ob_start();
    $cli->dispatch();
    $output = ob_get_clean();
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Invalid JSON: $output");
    assertTrue(isset($data['ok']));
    assertTrue(isset($data['data']));
    assertTrue($data['ok'] === true);
});

test('CLI::job list --json outputs valid JSON', function() {
    $cli = new CLI(['job', 'list', '--json']);
    ob_start();
    $cli->dispatch();
    $output = ob_get_clean();
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Invalid JSON: $output");
    assertTrue(isset($data['ok']));
    assertTrue(isset($data['data']));
    assertTrue($data['ok'] === true);
});

test('CLI::destination list --json outputs valid JSON', function() {
    $cli = new CLI(['destination', 'list', '--json']);
    ob_start();
    $cli->dispatch();
    $output = ob_get_clean();
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Invalid JSON: $output");
    assertTrue(isset($data['ok']));
    assertTrue(isset($data['data']));
    assertTrue($data['ok'] === true);
});

test('CLI::schedule list --json outputs valid JSON', function() {
    $cli = new CLI(['schedule', 'list', '--json']);
    ob_start();
    $cli->dispatch();
    $output = ob_get_clean();
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Invalid JSON: $output");
    assertTrue(isset($data['ok']));
    assertTrue(isset($data['data']));
    assertTrue($data['ok'] === true);
});

test('CLI::backup list --json outputs valid JSON', function() {
    $cli = new CLI(['backup', 'list', '--json']);
    ob_start();
    $cli->dispatch();
    $output = ob_get_clean();
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Invalid JSON: $output");
    assertTrue(isset($data['ok']));
    assertTrue(isset($data['data']));
    assertTrue($data['ok'] === true);
});

test('CLI::log show --json outputs valid JSON', function() {
    $cli = new CLI(['log', 'show', '--json', '--limit=5']);
    ob_start();
    $cli->dispatch();
    $output = ob_get_clean();
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Invalid JSON: $output");
    assertTrue(isset($data['ok']));
    assertTrue(isset($data['data']));
    assertTrue($data['ok'] === true);
});

// ---------------------------------------------------------------------------
// Standalone CLI wrapper scripts
// ---------------------------------------------------------------------------

echo "\nStandalone CLI Wrappers:\n";

$phpBin = PHP_BINARY;

test('rclone-destination script invokes destination list', function() use ($phpBin) {
    $script = __DIR__ . '/../cli/rclone-destination';
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' list --json';
    $output = shell_exec($cmd);
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Output was not valid JSON: $output");
    assertTrue($data['ok'] === true);
});

test('rclone-schedule script invokes schedule list', function() use ($phpBin) {
    $script = __DIR__ . '/../cli/rclone-schedule';
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' list --json';
    $output = shell_exec($cmd);
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Output was not valid JSON: $output");
    assertTrue($data['ok'] === true);
});

test('rclone-restore script invokes restore list', function() use ($phpBin) {
    $script = __DIR__ . '/../cli/rclone-restore';
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' list 1 --json';
    $output = shell_exec($cmd);
    $data = json_decode($output, true);
    assertTrue(is_array($data), "Output was not valid JSON: $output");
    assertTrue($data['ok'] === true);
});

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n=== Results: $passed passed, $failed failed ===\n";
if ($failed > 0) {
    exit(1);
}
echo "All tests passed.\n";
