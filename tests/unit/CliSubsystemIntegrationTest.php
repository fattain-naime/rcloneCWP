<?php
/**
 * Unit tests for CLI Subsystem Integration
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\CLI;
use CWP\RcloneCWP\Database;
use PHPUnit\Framework\TestCase;

class CliSubsystemIntegrationTest extends TestCase
{
    private $cli;
    private $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->cli = new CLI();
    }

    public function testStatusSubsystem()
    {
        // Status should return system status
        $reflection = new ReflectionClass(CLI::class);
        $method = $reflection->getMethod('runStatus');
        $method->setAccessible(true);

        ob_start();
        $code = $method->invoke($this->cli, [], ['json' => true]);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
        $this->assertArrayHasKey('rclone', $data['data'] ?? []);
        $this->assertArrayHasKey('database', $data['data'] ?? []);
    }

    public function testJobSubsystemList()
    {
        $reflection = new ReflectionClass(CLI::class);
        $method = $reflection->getMethod('runJob');
        $method->setAccessible(true);

        ob_start();
        $code = $method->invoke($this->cli, ['list'], ['json' => true]);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
    }

    public function testBackupSubsystemList()
    {
        $reflection = new ReflectionClass(CLI::class);
        $method = $reflection->getMethod('runBackup');
        $method->setAccessible(true);

        ob_start();
        $code = $method->invoke($this->cli, ['list'], ['json' => true]);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
    }

    public function testDestinationSubsystemList()
    {
        $reflection = new ReflectionClass(CLI::class);
        $method = $reflection->getMethod('runDestination');
        $method->setAccessible(true);

        ob_start();
        $code = $method->invoke($this->cli, ['list'], ['json' => true]);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
    }

    public function testScheduleSubsystemList()
    {
        $reflection = new ReflectionClass(CLI::class);
        $method = $reflection->getMethod('runSchedule');
        $method->setAccessible(true);

        ob_start();
        $code = $method->invoke($this->cli, ['list'], ['json' => true]);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
    }

    public function testLogSubsystemShow()
    {
        $reflection = new ReflectionClass(CLI::class);
        $method = $reflection->getMethod('runLog');
        $method->setAccessible(true);

        ob_start();
        $code = $method->invoke($this->cli, ['show'], ['json' => true, 'limit' => 10]);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
    }
}