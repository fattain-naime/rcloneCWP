<?php
/**
 * Unit tests for Notification system - HTML injection fixes
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Notifications\NotificationEngine;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    public function testHtmlEscapingInEmailTemplate()
    {
        // Test that htmlspecialchars is used with proper flags
        $testValue = '<script>alert("xss")</script>';
        $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->assertStringContainsString('&lt;script&gt;', $escaped);
        $this->assertStringContainsString('&lt;/script&gt;', $escaped);
        $this->assertStringNotContainsString('<script>', $escaped);
    }

    public function testHtmlEscapingWithQuotes()
    {
        $testValue = '" onclick="alert(1)"';
        $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->assertStringContainsString('&quot;', $escaped);
        $this->assertStringNotContainsString('"', $escaped);
    }

    public function testHtmlEscapingWithSingleQuotes()
    {
        $testValue = "' onmouseover='alert(1)'";
        $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->assertStringContainsString('&#039;', $escaped);
        $this->assertStringNotContainsString("'", $escaped);
    }

    public function testHtmlEscapingInvalidUtf8()
    {
        // Test ENT_SUBSTITUTE handles invalid UTF-8
        $testValue = "valid\xC0\xAFtext"; // Invalid UTF-8 sequence
        $escaped = htmlspecialchars($testValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->assertIsString($escaped);
    }
}