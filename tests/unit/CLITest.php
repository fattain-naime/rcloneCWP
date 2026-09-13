<?php
/**
 * Unit tests for the CLI Engine
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\CLI;
use CWP\RcloneCWP\CLIException;
use PHPUnit\Framework\TestCase;

class CLITest extends TestCase
{
    private $cli;
    private $originalArgv;
    private $capturedOutput;

    protected function setUp(): void
    {
        $this->originalArgv = $_SERVER['argv'] ?? [];
        $this->cli = new CLI();
        $this->capturedOutput = '';

        // Suppress exit in tests by mocking
        $_SERVER['argv'] = ['rcloneCWP'];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->originalArgv;
    }

    // Test CLI instantiation
    public function testCliInstantiation()
    {
        $this->assertInstanceOf(CLI::class, $this->cli);
    }

    // Test argument parsing
    public function testParseEmptyArgs()
    {
        $this->cli->parse([]);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        $flagsProp = $ref->getProperty('flags');
        $flagsProp->setAccessible(true);

        $this->assertEquals([], $argsProp->getValue($this->cli));
        $this->assertEquals([], $flagsProp->getValue($this->cli));
    }

    public function testParseWithFlags()
    {
        $this->cli->parse(['status', '--json', '--no-color']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        $flagsProp = $ref->getProperty('flags');
        $flagsProp->setAccessible(true);

        $this->assertEquals(['status'], $argsProp->getValue($this->cli));
        $flags = $flagsProp->getValue($this->cli);
        $this->assertTrue($flags['json']);
        $this->assertTrue($flags['no-color']);
    }

    public function testParseWithArgs()
    {
        $this->cli->parse(['job', 'list', '--limit', '10']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        $flagsProp = $ref->getProperty('flags');
        $flagsProp->setAccessible(true);

        $this->assertEquals(['job', 'list'], $argsProp->getValue($this->cli));
        $flags = $flagsProp->getValue($this->cli);
        $this->assertEquals('10', $flags['limit']);
    }

    public function testParseJobRunCommand()
    {
        $this->cli->parse(['job', 'run', '42', '--dry-run']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        $flagsProp = $ref->getProperty('flags');
        $flagsProp->setAccessible(true);

        $this->assertEquals(['job', 'run', '42'], $argsProp->getValue($this->cli));
        $flags = $flagsProp->getValue($this->cli);
        $this->assertTrue($flags['dry-run']);
    }

    public function testParseBackupList()
    {
        $this->cli->parse(['backup', 'list', '--limit', '5', '--json']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        $flagsProp = $ref->getProperty('flags');
        $flagsProp->setAccessible(true);

        $this->assertEquals(['backup', 'list'], $argsProp->getValue($this->cli));
        $flags = $flagsProp->getValue($this->cli);
        $this->assertEquals('5', $flags['limit']);
        $this->assertTrue($flags['json']);
    }

    public function testParseDestinationTest()
    {
        $this->cli->parse(['destination', 'test', '3']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);

        $this->assertEquals(['destination', 'test', '3'], $argsProp->getValue($this->cli));
    }

    public function testParseRestoreRun()
    {
        $this->cli->parse(['restore', 'run', '123', '--dry-run', '--components', 'files,databases']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        $flagsProp = $ref->getProperty('flags');
        $flagsProp->setAccessible(true);

        $this->assertEquals(['restore', 'run', '123'], $argsProp->getValue($this->cli));
        $flags = $flagsProp->getValue($this->cli);
        $this->assertTrue($flags['dry-run']);
        $this->assertEquals('files,databases', $flags['components']);
    }

    public function testParseScheduleRunDue()
    {
        $this->cli->parse(['schedule', 'run-due']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);

        $this->assertEquals(['schedule', 'run-due'], $argsProp->getValue($this->cli));
    }

    public function testParseLogShow()
    {
        $this->cli->parse(['log', 'show', '100']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);

        $this->assertEquals(['log', 'show', '100'], $argsProp->getValue($this->cli));
    }

    public function testParseLogTail()
    {
        $this->cli->parse(['log', 'tail']);
        $ref = new ReflectionClass($this->cli);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);

        $this->assertEquals(['log', 'tail'], $argsProp->getValue($this->cli));
    }

    // Test help rendering
    public function testRenderHelp()
    {
        $help = $this->cli->renderHelp();
        $this->assertStringContainsString('rcloneCWP', $help);
        $this->assertStringContainsString('COMMANDS', $help);
        $this->assertStringContainsString('status', $help);
        $this->assertStringContainsString('job', $help);
        $this->assertStringContainsString('backup', $help);
        $this->assertStringContainsString('destination', $help);
        $this->assertStringContainsString('restore', $help);
        $this->assertStringContainsString('schedule', $help);
        $this->assertStringContainsString('log', $help);
    }

    // Test JSON rendering
    public function testRenderJson()
    {
        $data = ['ok' => true, 'data' => ['test' => 'value']];
        $json = $this->cli->renderJson($data);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    public function testRenderJsonPretty()
    {
        $data = ['ok' => true];
        $json = $this->cli->renderJson($data, true);
        $this->assertStringContainsString("\n", $json);
    }

    // Test color helpers
    public function testColorForLevel()
    {
        $this->assertEquals('\033[32m', $this->cli->colorForLevel('success'));
        $this->assertEquals('\033[31m', $this->cli->colorForLevel('error'));
        $this->assertEquals('\033[33m', $this->cli->colorForLevel('warning'));
        $this->assertEquals('\033[34m', $this->cli->colorForLevel('info'));
        $this->assertEquals('\033[0m', $this->cli->colorForLevel('unknown'));
    }

    public function testColor()
    {
        $colored = $this->cli->color('test', 'red');
        $this->assertStringContainsString('\033[31m', $colored);
        $this->assertStringContainsString('\033[0m', $colored);
    }

    public function testBold()
    {
        $bold = $this->cli->bold('test');
        $this->assertStringContainsString('\033[1m', $bold);
        $this->assertStringContainsString('\033[0m', $bold);
    }

    public function testReset()
    {
        $reset = $this->cli->reset();
        $this->assertEquals('\033[0m', $reset);
    }

    // Test humanBytes
    public function testHumanBytes()
    {
        $this->assertEquals('0 B', $this->cli->humanBytes(0));
        $this->assertEquals('1.00 KB', $this->cli->humanBytes(1024));
        $this->assertEquals('1.00 MB', $this->cli->humanBytes(1024 * 1024));
        $this->assertEquals('1.00 GB', $this->cli->humanBytes(1024 * 1024 * 1024));
        $this->assertEquals('1.00 TB', $this->cli->humanBytes(1024 * 1024 * 1024 * 1024));
    }

    // Test normalizeRowWidths
    public function testNormalizeRowWidths()
    {
        $rows = [
            ['col1', 'col2'],
            ['short', 'very long content here'],
        ];
        $widths = $this->cli->normalizeRowWidths($rows);
        $this->assertCount(2, $widths);
        $this->assertGreaterThanOrEqual(strlen('col1'), $widths[0]);
        $this->assertGreaterThanOrEqual(strlen('very long content here'), $widths[1]);
    }

    // Test CLIException
    public function testCliException()
    {
        $exception = new CLIException('Test error', 1);
        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(1, $exception->getCode());
    }
}