<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Mock\TimyTecoDatabase;
use AiFace\WebSocket\Tests\TestCase;

class MockDeviceDatabaseTest extends TestCase
{
    private string $tempDb;
    private TimyTecoDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDb = sys_get_temp_dir() . '/test_tynyteko_' . uniqid() . '.sqlite';
        require_once __DIR__ . '/../../examples/TimyTecoDatabase.php';
        $this->db = new TimyTecoDatabase($this->tempDb, 'LF00000001');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    public function testInitialSeedAndCapacity(): void
    {
        $cap = $this->db->getDeviceCap();
        $this->assertEquals(10000, $cap['usersize']);
        $this->assertEquals(5, $cap['useduser']);
        $this->assertGreaterThan(0, $cap['usedface']);

        $ids = $this->db->getUserIds();
        $this->assertCount(5, $ids);
        $this->assertContains(101, $ids);
        $this->assertContains(105, $ids);
    }

    public function testUserCrudOperations(): void
    {
        // 1. Check user 107 does not exist yet
        $this->assertFalse($this->db->checkUserId(107));
        $this->assertEquals(106, $this->db->getUnusedUserId());

        // 2. Set user 107
        $this->db->setUserInfo(
            enrollId: 107,
            name: 'Normal User',
            backupNum: 10,
            record: 'password123',
            admin: 0,
            enable: 1
        );

        $this->assertTrue($this->db->checkUserId(107));
        $this->assertEquals('Normal User', $this->db->getUserName(107));

        $info = $this->db->getUserInfo(107, 10);
        $this->assertNotNull($info);
        $this->assertEquals('Normal User', $info['name']);
        $this->assertEquals('password123', $info['record']);

        // 3. Update user 107
        $this->db->setUserInfo(
            enrollId: 107,
            name: 'Normal User Updated',
            backupNum: 12,
            record: 'FACE_HEX_DATA'
        );
        $this->assertEquals('Normal User Updated', $this->db->getUserName(107));

        // 4. Delete specific credential
        $this->db->deleteUser(107, 12);
        $infoFace = $this->db->getUserInfo(107, 12);
        $this->assertEquals('', $infoFace['record']);

        // 5. Delete user entirely
        $this->db->deleteUser(107);
        $this->assertFalse($this->db->checkUserId(107));
    }

    public function testPaginationAndBatch(): void
    {
        $list = $this->db->getUserList(1, 3);
        $this->assertCount(3, $list);
        $this->assertEquals([101, 102, 103], $list);

        $all = $this->db->getAllUsers(1, 2);
        $this->assertCount(2, $all);
        $this->assertEquals('Alice Johnson', $all[0]['name']);
    }

    public function testAttendanceLogsLifecycle(): void
    {
        // Insert log
        $log = $this->db->insertLog(
            enrollId: 101,
            name: 'Alice Johnson',
            mode: 3,
            inout: 0,
            aliasid: 'EMP101',
            note: 'Test Scan'
        );

        $this->assertGreaterThan(0, $log['logindex']);
        $this->assertEquals(101, $log['enrollid']);

        // Query new logs
        $newLogs = $this->db->getNewLogs();
        $this->assertNotEmpty($newLogs);

        // Mark log
        $marked = $this->db->markLogs([$log['logindex']]);
        $this->assertGreaterThanOrEqual(1, $marked);

        // Clean logs
        $this->assertTrue($this->db->cleanLog());
        $this->assertEmpty($this->db->getNewLogs());
    }

    public function testFactoryReset(): void
    {
        $this->db->cleanUser();
        $this->assertEmpty($this->db->getUserIds());

        $this->db->resetFactory();
        $this->assertCount(5, $this->db->getUserIds());
    }
}
