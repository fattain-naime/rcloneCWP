<?php
/**
 * Unit tests for Standalone CLI Wrappers
 */
namespace CWP\RcloneCWP\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CliWrappersTest extends TestCase
{
    private $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = '/root/rcloneCWP';
    }

    public function testRcloneCwpWrapperExists()
    {
        $this->assertFileExists($this->projectRoot . '/cli/rcloneCWP');
        $this->assertFileIsReadable($this->projectRoot . '/cli/rcloneCWP');
    }

    public function testRcloneRestoreWrapperExists()
    {
        $this->assertFileExists($this->projectRoot . '/cli/rclone-restore');
        $this->assertFileIsReadable($this->projectRoot . '/cli/rclone-restore');
    }

    public function testRcloneDestinationWrapperExists()
    {
        $this->assertFileExists($this->projectRoot . '/cli/rclone-destination');
        $this->assertFileIsReadable($this->projectRoot . '/cli/rclone-destination');
    }

    public function testRcloneScheduleWrapperExists()
    {
        $this->assertFileExists($this->projectRoot . '/cli/rclone-schedule');
        $this->assertFileIsReadable($this->projectRoot . '/cli/rclone-schedule');
    }

    public function testRcloneCwpWrapperHasShebang()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rcloneCWP');
        $this->assertStringStartsWith('#!/usr/bin/env php', $content);
    }

    public function testRcloneRestoreWrapperHasShebang()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rclone-restore');
        $this->assertStringStartsWith('#!/usr/bin/env php', $content);
    }

    public function testRcloneDestinationWrapperHasShebang()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rclone-destination');
        $this->assertStringStartsWith('#!/usr/bin/env php', $content);
    }

    public function testRcloneScheduleWrapperHasShebang()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rclone-schedule');
        $this->assertStringStartsWith('#!/usr/bin/env php', $content);
    }

    public function testRcloneCwpWrapperDelegatesToCLI()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rcloneCWP');
        $this->assertStringContainsString('CLI::main()', $content);
    }

    public function testRcloneRestoreWrapperPrependsRestore()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rclone-restore');
        $this->assertStringContainsString('restore', $content);
        $this->assertStringContainsString('$newArgv', $content);
        $this->assertStringContainsString("'restore'", $content);
    }

    public function testRcloneDestinationWrapperPrependsDestination()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rclone-destination');
        $this->assertStringContainsString('destination', $content);
        $this->assertStringContainsString('$newArgv', $content);
        $this->assertStringContainsString("'destination'", $content);
    }

    public function testRcloneScheduleWrapperPrependsSchedule()
    {
        $content = file_get_contents($this->projectRoot . '/cli/rclone-schedule');
        $this->assertStringContainsString('schedule', $content);
        $this->assertStringContainsString('$newArgv', $content);
        $this->assertStringContainsString("'schedule'", $content);
    }

    public function testWrappersHaveBootstrapResolution()
    {
        $wrappers = ['rcloneCWP', 'rclone-restore', 'rclone-destination', 'rclone-schedule'];

        foreach ($wrappers as $wrapper) {
            $content = file_get_contents($this->projectRoot . '/cli/' . $wrapper);
            $this->assertStringContainsString('bootstrap', $content, "Wrapper $wrapper should have bootstrap resolution");
        }
    }

    public function testWrappersHandleDevAndProdPaths()
    {
        $wrappers = ['rcloneCWP', 'rclone-restore', 'rclone-destination', 'rclone-schedule'];

        foreach ($wrappers as $wrapper) {
            $content = file_get_contents($this->projectRoot . '/cli/' . $wrapper);
            $this->assertStringContainsString('/root/rcloneCWP', $content, "Wrapper $wrapper should handle dev path");
            $this->assertStringContainsString('/usr/local/cwp/rcloneCWP', $content, "Wrapper $wrapper should handle prod path");
        }
    }
}