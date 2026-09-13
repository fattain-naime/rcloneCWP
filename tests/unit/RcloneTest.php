<?php
/**
 * Unit tests for the Rclone Wrapper
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Rclone;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use PHPUnit\Framework\TestCase;

class RcloneTest extends TestCase
{
    public function testGetBinaryPath()
    {
        $path = Rclone::getBinaryPath();
        $this->assertIsString($path);
        $this->assertNotEmpty($path);
    }

    public function testVersion()
    {
        $version = Rclone::version();
        $this->assertIsArray($version);
        $this->assertArrayHasKey('version', $version);
    }

    public function testConfigFile()
    {
        // Test that config file path is correct
        $reflection = new ReflectionClass(Rclone::class);
        $property = $reflection->getProperty('CONFIG_FILE');
        $property->setAccessible(true);
        $configFile = $property->getValue();
        $this->assertEquals('/etc/rclone/rclone.conf', $configFile);
    }
}