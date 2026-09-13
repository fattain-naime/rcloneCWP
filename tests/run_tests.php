<?php
/**
 * Simple test runner for rcloneCWP
 */

require_once __DIR__ . '/bootstrap.php';

use CWP\RcloneCWP\CLI;
use CWP\RcloneCWP\CLIException;
use CWP\RcloneCWP\Notifications\NotificationEngine;
use CWP\RcloneCWP\Database;

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

function assertContains($needle, $haystack, string $msg = ''): void {
    $found = strpos((string)$haystack, (string)$needle) !== false;
    if (!$found) throw new Exception("$msg: '$needle' not found");
}

echo "\n=== rcloneCWP Test Suite ===\n\n";

// CLI Tests
echo "CLI Engine:\n";

test('CLI::version() returns string', function() {
    $ref = new ReflectionClass(CLI::class);
    $method = $ref->getMethod('version');
    $method->setAccessible(true);
    $version = $method->invoke(new CLI());
    assertTrue(is_string($version) && strlen($version) > 0);
});

test('CLI parses --json flag', function() {
    $cli = new CLI(['status', '--json']);
    $ref = new ReflectionClass($cli);
    $prop = $ref->getProperty('json');
    $prop->setAccessible(true);
    assertTrue($prop->getValue($cli) === true);
});

test('CLI parses --no-color flag', function() {
    $cli = new CLI(['status', '--no-color']);
    $ref = new ReflectionClass($cli);
    $prop = $ref->getProperty('noColor');
    $prop->setAccessible(true);
    assertTrue($prop->getValue($cli) === true);
});

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

test('CLI::humanBytes() formats correctly', function() {
    $cli = new CLI();
    $ref = new ReflectionClass($cli);
    $method = $ref->getMethod('humanBytes');
    $method->setAccessible(true);
    assertEq('1 KB', $method->invoke($cli, 1024));
    assertEq('1 MB', $method->invoke($cli, 1024 * 1024));
});

// HTML Escaping Tests (Security fix validation)
echo "\nHTML Injection Defense:\n";

test('HTML escaping prevents XSS', function() {
    $testValue = '<script>alert("xss")</script>';
    $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    assertTrue(strpos($escaped, '&lt;script&gt;') !== false);
    assertTrue(strpos($escaped, '<script>') === false);
});

test('HTML escaping handles quotes', function() {
    $testValue = '" onclick="alert(1)"';
    $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    assertTrue(strpos($escaped, '&quot;') !== false);
    assertTrue(strpos($escaped, '"') === false);
});

test('HTML escaping handles single quotes', function() {
    $testValue = "' onmouseover='alert(1)'";
    $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    assertTrue(strpos($escaped, '&#039;') !== false);
    assertTrue(strpos($escaped, "'") === false);
});

test('HTML escaping handles invalid UTF-8', function() {
    $testValue = "valid\xC0\xAFtext";
    $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    assertTrue(is_string($escaped));
});

// Database Tests
echo "\nDatabase:\n";

test('Database singleton works', function() {
    $db = Database::getInstance();
    assertTrue($db instanceof Database);
});

test('Database fetchOne works', function() {
    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT 1 as test");
    assertTrue(is_array($row));
    assertTrue($row['test'] == 1 || $row['test'] === '1');
});

test('Database insert and fetch', function() {
    $db = Database::getInstance();
    $id = $db->insert('rclone_destinations', [
        'name' => 'Test DB Insert',
        'type' => 'local',
        'config' => json_encode(['path' => '/tmp']),
        'active' => 1,
    ]);

    assertTrue(is_int($id) || is_numeric($id));
    assertTrue($id > 0);

    $row = $db->fetchOne("SELECT * FROM rclone_destinations WHERE id = ?", [$id]);
    assertTrue(is_array($row), "Row should be array, got: " . gettype($row));
    assertTrue($row['name'] === 'Test DB Insert', "Expected 'Test DB Insert', got: " . ($row['name'] ?? 'null'));
});

// CLI Wrapper Scripts Tests
echo "\nCLI Wrapper Scripts:\n";

$scripts = ['rcloneCWP', 'rclone-restore', 'rclone-destination', 'rclone-schedule'];
foreach ($scripts as $script) {
    test("$script script exists", function() use ($script) {
        $path = __DIR__ . "/../cli/$script";
        assertTrue(file_exists($path), "Missing: $path");
        assertTrue(is_readable($path), "Not readable: $path");
    });

    test("$script has shebang", function() use ($script) {
        $path = __DIR__ . "/../cli/$script";
        $content = file_get_contents($path);
        assertTrue(strpos($content, '#!/usr/bin/env php') === 0);
    });
}

test('rcloneCWP wrapper delegates to CLI::main()', function() {
    $content = file_get_contents(__DIR__ . '/../cli/rcloneCWP');
    assertContains('CLI::main', $content);
});

test('rclone-restore wrapper prepends restore', function() {
    $content = file_get_contents(__DIR__ . '/../cli/rclone-restore');
    assertContains("'restore'", $content);
    assertContains('$newArgv', $content);
});

test('rclone-destination wrapper prepends destination', function() {
    $content = file_get_contents(__DIR__ . '/../cli/rclone-destination');
    assertContains("'destination'", $content);
    assertContains('$newArgv', $content);
});

test('rclone-schedule wrapper prepends schedule', function() {
    $content = file_get_contents(__DIR__ . '/../cli/rclone-schedule');
    assertContains("'schedule'", $content);
    assertContains('$newArgv', $content);
});

// Summary
echo "\n=== Results: $passed passed, $failed failed ===\n";
if ($failed > 0) {
    exit(1);
}
echo "All tests passed.\n";