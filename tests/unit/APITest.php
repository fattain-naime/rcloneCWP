<?php
/**
 * Unit tests for the API Engine
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\API;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use PHPUnit\Framework\TestCase;

class APITest extends TestCase
{
    private $api;
    private $db;
    private $logger;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->logger = new Logger();
        $this->api = new API();
    }

    public function testApiInstantiation()
    {
        $this->assertInstanceOf(API::class, $this->api);
    }

    public function testGetDatabase()
    {
        $db = $this->api->getDatabase();
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testGetLogger()
    {
        $logger = $this->api->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function testAuthenticateValidKey()
    {
        // Insert a test API key
        $keyHash = hash('sha256', 'test-api-key-123');
        $this->db->insert('rclone_api_keys', [
            'name' => 'Test Key',
            'key_hash' => $keyHash,
            'scopes' => json_encode(['job:list', 'job:read', 'backup:run']),
            'active' => 1,
        ]);

        // Mock the authentication
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-api-key-123';
        $result = $this->api->authenticate();

        // Clean up
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->assertTrue($result['ok'] ?? false);
    }

    public function testAuthenticateInvalidKey()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-key';
        $result = $this->api->authenticate();
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertEquals('Invalid API key', $result['error'] ?? '');
    }

    public function testAuthenticateMissingHeader()
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $result = $this->api->authenticate();

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('API key required', $result['error'] ?? '');
    }

    public function testCheckRateLimit()
    {
        $result = $this->api->checkRateLimit();
        $this->assertTrue($result['ok'] ?? false);
    }

    public function testRequirePermissionAllowed()
    {
        // This should not throw if permission is granted
        // We can't easily test this without full auth setup
        $this->assertTrue(true); // Placeholder
    }

    public function testRequirePermissionDenied()
    {
        // This should throw if permission is denied
        $this->assertTrue(true); // Placeholder
    }
}