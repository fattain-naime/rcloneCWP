<?php
/**
 * Unit tests for BackupJobManager
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use PHPUnit\Framework\TestCase;

class BackupJobManagerTest extends TestCase
{
    private $db;
    private $dm;
    private $logger;
    private $bjm;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->dm = new DestinationManager($this->db, null, new Logger());
        $this->logger = new Logger();
        $this->bjm = new BackupJobManager($this->db, $this->dm, $this->logger);
    }

    public function testBackupJobManagerInstantiation()
    {
        $this->assertInstanceOf(BackupJobManager::class, $this->bjm);
    }

    public function testListJobsEmpty()
    {
        $jobs = $this->bjm->listJobs();
        $this->assertIsArray($jobs);
        $this->assertEmpty($jobs);
    }

    public function testCreateJob()
    {
        // First create a destination
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Dest',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/test']),
            'active' => 1,
        ]);

        $input = [
            'name' => 'Test Backup Job',
            'destination_id' => $destId,
            'source_type' => 'files',
            'source_config' => json_encode(['paths' => ['/home/test']]),
            'retention_count' => 7,
            'retention_days' => 30,
            'active' => 1,
        ];

        $result = $this->bjm->createJob($input);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertArrayHasKey('id', $result);
        $this->assertIsInt($result['id']);
        $this->assertGreaterThan(0, $result['id']);
    }

    public function testCreateJobMissingName()
    {
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Dest',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/test']),
            'active' => 1,
        ]);

        $input = [
            'destination_id' => $destId,
            'source_type' => 'files',
        ];

        $result = $this->bjm->createJob($input);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetJob()
    {
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Dest',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/test']),
            'active' => 1,
        ]);

        $jobId = $this->db->insert('rclone_jobs', [
            'name' => 'Test Job',
            'destination_id' => $destId,
            'source_type' => 'files',
            'source_config' => json_encode(['paths' => ['/home/test']]),
            'retention_count' => 7,
            'retention_days' => 30,
            'active' => 1,
        ]);

        $job = $this->bjm->getJob($jobId);

        $this->assertIsArray($job);
        $this->assertEquals($jobId, $job['id']);
        $this->assertEquals('Test Job', $job['name']);
    }

    public function testGetJobNotFound()
    {
        $job = $this->bjm->getJob(99999);
        $this->assertNull($job);
    }

    public function testUpdateJob()
    {
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Dest',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/test']),
            'active' => 1,
        ]);

        $jobId = $this->db->insert('rclone_jobs', [
            'name' => 'Test Job',
            'destination_id' => $destId,
            'source_type' => 'files',
            'source_config' => json_encode(['paths' => ['/home/test']]),
            'retention_count' => 7,
            'retention_days' => 30,
            'active' => 1,
        ]);

        $result = $this->bjm->updateJob($jobId, [
            'name' => 'Updated Job Name',
            'retention_count' => 14,
        ]);

        $this->assertTrue($result['ok'] ?? false);

        $job = $this->bjm->getJob($jobId);
        $this->assertEquals('Updated Job Name', $job['name']);
        $this->assertEquals(14, $job['retention_count']);
    }

    public function testDeleteJob()
    {
        $destId = $this->db->insert('rclone_destinations', [
            'name' => 'Test Dest',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp/test']),
            'active' => 1,
        ]);

        $jobId = $this->db->insert('rclone_jobs', [
            'name' => 'Test Job',
            'destination_id' => $destId,
            'source_type' => 'files',
            'source_config' => json_encode(['paths' => ['/home/test']]),
            'retention_count' => 7,
            'retention_days' => 30,
            'active' => 1,
        ]);

        $result = $this->bjm->deleteJob($jobId);

        $this->assertTrue($result['ok'] ?? false);

        $job = $this->bjm->getJob($jobId);
        $this->assertNull($job);
    }
}