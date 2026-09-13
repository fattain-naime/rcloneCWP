<?php
/**
 * Unit tests for Database class
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
    }

    public function testDatabaseInstantiation()
    {
        $this->assertInstanceOf(Database::class, $this->db);
    }

    public function testFetchAll()
    {
        $rows = $this->db->fetchAll("SELECT * FROM rclone_destinations");
        $this->assertIsArray($rows);
    }

    public function testFetchOne()
    {
        $row = $this->db->fetchOne("SELECT 1 as test");
        $this->assertIsArray($row);
        $this->assertEquals(1, $row['test']);
    }

    public function testInsertAndFetch()
    {
        $id = $this->db->insert('rclone_destinations', [
            'name' => 'Test DB Insert',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp']),
            'active' => 1,
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $row = $this->db->fetchOne("SELECT * FROM rclone_destinations WHERE id = ?", [$id]);
        $this->assertIsArray($row);
        $this->assertEquals('Test DB Insert', $row['name']);
    }

    public function testUpdate()
    {
        $id = $this->db->insert('rclone_destinations', [
            'name' => 'Test Update',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp']),
            'active' => 1,
        ]);

        $this->db->update('rclone_destinations', ['name' => 'Updated Name'], 'id = ?', [$id]);

        $row = $this->db->fetchOne("SELECT * FROM rclone_destinations WHERE id = ?", [$id]);
        $this->assertEquals('Updated Name', $row['name']);
    }

    public function testDelete()
    {
        $id = $this->db->insert('rclone_destinations', [
            'name' => 'Test Delete',
            'type' => 'local',
            'config' => json_encode(['path' => '/tmp']),
            'active' => 1,
        ]);

        $this->db->delete('rclone_destinations', 'id = ?', [$id]);

        $row = $this->db->fetchOne("SELECT * FROM rclone_destinations WHERE id = ?", [$id]);
        $this->assertNull($row);
    }
}