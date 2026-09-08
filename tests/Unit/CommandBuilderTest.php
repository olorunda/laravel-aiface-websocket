<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Commands\AiFaceCommandBuilder;
use AiFace\WebSocket\Core\Protocol;
use PHPUnit\Framework\TestCase;

class CommandBuilderTest extends TestCase
{
    public function test_user_management_commands(): void
    {
        // 5.1 setUserInfo
        $cmd = AiFaceCommandBuilder::setUserInfo(101, 'Alice Smith', Protocol::BACKUP_PASSWORD, 123456, 1, 1);
        $this->assertEquals('setuserinfo', $cmd['cmd']);
        $this->assertEquals(101, $cmd['enrollid']);
        $this->assertEquals('Alice Smith', $cmd['name']);
        $this->assertEquals(Protocol::BACKUP_PASSWORD, $cmd['backupnum']);
        $this->assertEquals(123456, $cmd['record']);

        // 5.2 deleteUser
        $cmdDefault = AiFaceCommandBuilder::deleteUser(101);
        $this->assertEquals('deleteuser', $cmdDefault['cmd']);
        $this->assertEquals(101, $cmdDefault['enrollid']);
        $this->assertEquals(13, $cmdDefault['backupnum']); // 13 for default full user deletion
        $this->assertEquals(Protocol::BACKUP_DELETE_USER, $cmdDefault['backupnum']);

        $cmd = AiFaceCommandBuilder::deleteUser(101, Protocol::BACKUP_PASSWORD);
        $this->assertEquals('deleteuser', $cmd['cmd']);
        $this->assertEquals(101, $cmd['enrollid']);
        $this->assertEquals(Protocol::BACKUP_PASSWORD, $cmd['backupnum']);

        // 5.3 cleanUser
        $this->assertEquals(['cmd' => 'cleanuser'], AiFaceCommandBuilder::cleanUser());

        // 5.4 setUserName
        $cmd = AiFaceCommandBuilder::setUserName([
            ['enrollid' => 1, 'name' => 'User One'],
            ['enrollid' => 2, 'name' => 'User Two'],
        ]);
        $this->assertEquals('setusername', $cmd['cmd']);
        $this->assertEquals(2, $cmd['count']);
        $this->assertCount(2, $cmd['record']);

        // 5.5 getusername
        $cmd = AiFaceCommandBuilder::getUserName(101);
        $this->assertEquals('getusername', $cmd['cmd']);
        $this->assertEquals(101, $cmd['enrollid']);

        // 5.6 getuserlist
        $cmd = AiFaceCommandBuilder::getUserList(2, 25);
        $this->assertEquals('getuserlist', $cmd['cmd']);
        $this->assertEquals(2, $cmd['page']);
        $this->assertEquals(25, $cmd['pagesize']);

        // 5.7 getuserids
        $this->assertEquals(['cmd' => 'getuserids'], AiFaceCommandBuilder::getUserIds());

        // 5.8 getunuserdid
        $this->assertEquals(['cmd' => 'getunuserdid'], AiFaceCommandBuilder::getUnusedUserId());

        // 5.9 checkuserid
        $this->assertEquals(['cmd' => 'checkuserid', 'enrollid' => 5], AiFaceCommandBuilder::checkUserId(5));

        // 5.12 adduser
        $cmd = AiFaceCommandBuilder::addUser(101, Protocol::BACKUP_FACE_PHOTO, 0);
        $this->assertEquals('adduser', $cmd['cmd']);
        $this->assertEquals(Protocol::BACKUP_FACE_PHOTO, $cmd['backupnum']);

        // 5.15 enableuser
        $cmd = AiFaceCommandBuilder::enableUser(101, false);
        $this->assertEquals('enableuser', $cmd['cmd']);
        $this->assertEquals(0, $cmd['enable']);
    }

    public function test_log_management_commands(): void
    {
        // 6.1 getnewlog
        $this->assertEquals(['cmd' => 'getnewlog'], AiFaceCommandBuilder::getNewLog());

        // 6.2 getalllog
        $cmd = AiFaceCommandBuilder::getAllLog(1, 50, '2026-01-01 00:00:00', '2026-01-02 23:59:59');
        $this->assertEquals('getalllog', $cmd['cmd']);
        $this->assertEquals(1, $cmd['page']);
        $this->assertEquals(50, $cmd['pagesize']);
        $this->assertEquals('2026-01-01 00:00:00', $cmd['starttime']);

        // 6.3 cleanlog
        $this->assertEquals(['cmd' => 'cleanlog'], AiFaceCommandBuilder::cleanLog());
    }

    public function test_device_management_commands(): void
    {
        // 7.1 getdevinfo
        $this->assertEquals(['cmd' => 'getdevinfo'], AiFaceCommandBuilder::getDevInfo());

        // 7.2 setdevinfo
        $cmd = AiFaceCommandBuilder::setDevInfo(['volume' => 90, 'screensaver' => 60]);
        $this->assertEquals('setdevinfo', $cmd['cmd']);
        $this->assertEquals(90, $cmd['devinfo']['volume']);

        // 7.3 getdevcap
        $this->assertEquals(['cmd' => 'getdevcap'], AiFaceCommandBuilder::getDevCap());

        // 7.4 gettime
        $this->assertEquals(['cmd' => 'gettime'], AiFaceCommandBuilder::getTime());

        // 7.5 settime
        $cmd = AiFaceCommandBuilder::setTime('2026-09-07 12:00:00');
        $this->assertEquals('settime', $cmd['cmd']);
        $this->assertEquals('2026-09-07 12:00:00', $cmd['time']);

        // 7.11 reboot
        $this->assertEquals(['cmd' => 'reboot'], AiFaceCommandBuilder::reboot());

        // 7.14 checklive
        $this->assertEquals(['cmd' => 'checklive'], AiFaceCommandBuilder::checkLive());
    }

    public function test_access_control_commands(): void
    {
        // 8.1 opendoor
        $cmd = AiFaceCommandBuilder::openDoor(1);
        $this->assertEquals('opendoor', $cmd['cmd']);
        $this->assertEquals(1, $cmd['doornum']);

        // 8.2 lockctrl
        $cmd = AiFaceCommandBuilder::lockCtrl(1, 2);
        $this->assertEquals('lockctrl', $cmd['cmd']);
        $this->assertEquals(1, $cmd['ctrl']);
        $this->assertEquals(2, $cmd['doornum']);

        // 8.3 getdoorstatus
        $cmd = AiFaceCommandBuilder::getDoorStatus(1);
        $this->assertEquals('getdoorstatus', $cmd['cmd']);
    }

    public function test_attendance_and_file_commands(): void
    {
        // 9.1 getshift
        $cmd = AiFaceCommandBuilder::getShift(1);
        $this->assertEquals('getshift', $cmd['cmd']);
        $this->assertEquals(1, $cmd['shiftid']);

        // 11.1 getdir
        $cmd = AiFaceCommandBuilder::getDir('/etc');
        $this->assertEquals('getdir', $cmd['cmd']);
        $this->assertEquals('/etc', $cmd['path']);

        // 11.2 getfile
        $cmd = AiFaceCommandBuilder::getFile('test.cfg', 0, 1024);
        $this->assertEquals('getfile', $cmd['cmd']);
        $this->assertEquals('test.cfg', $cmd['filename']);
    }

    public function test_video_intercom_commands(): void
    {
        // 13.1 enablewebrtc
        $cmd = AiFaceCommandBuilder::enableWebRtc(true, true, 45, 'stun:stun.l.google.com:19302');
        $this->assertEquals('enablewebrtc', $cmd['cmd']);
        $this->assertTrue($cmd['intercom']);
        $this->assertEquals(45, $cmd['waitseconds']);
        $this->assertEquals('stun:stun.l.google.com:19302', $cmd['stunserver']);

        // 13.2 callaccept
        $cmd = AiFaceCommandBuilder::callAccept('sess_123', true);
        $this->assertEquals('callaccept', $cmd['cmd']);
        $this->assertTrue($cmd['result']);
        $this->assertEquals('sess_123', $cmd['sessionid']);
    }
}
