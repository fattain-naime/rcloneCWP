<?php
/**
 * Unit tests for DestinationManager
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use PHPUnit\Framework\TestCase;

class DestinationManagerTest extends TestCase
{
    private $db;
    private $logger;
    private $dm;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->logger = new Logger();
        $this->dm = new DestinationManager($this->db, null, $this->logger);
    }

    public function testDestinationManagerInstantiation()
    {
        $this->assertInstanceOf(DestinationManager::class, $this->dm);
    }

    public function testListDestinationsEmpty()
    {
        $destinations = $this->dm->listDestinations();
        $this->assertIsArray($destinations);
        $this->assertEmpty($destinations);
    }

    public function testCreateDestination()
    {
        $input = [
            'name' => 'Test S3 Destination',
            'type' => 's3',
            'config' => [
                'provider' => 'AWS',
                'access_key_id' => 'test-key',
                'secret_access_key' => 'test-secret',
                'region' => 'us-east-1',
                'bucket' => 'test-bucket',
            ],
            'active' => 1,
        ];

        $result = $this->dm->createDestination($input);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertArrayHasKey('id', $result);
    }

    public function testGetDestination()
    {
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Dest',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/test']),
            'active' => 1,
        ]);

        $dest = $this->dm->getDestination($destId);

        $this->assertIsArray($dest);
        $this->assertEquals($destId, $dest['id']);
        $this->assertEquals('Test Dest', $dest['name']);
    }

    public function testTestDestination()
    {
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Local',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp']),
            'active' => 1,
        ]);

        $result = $this->dm->testDestination($destId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
    }
}