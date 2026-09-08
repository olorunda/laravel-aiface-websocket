<?php

namespace AiFace\WebSocket\Commands;

use AiFace\WebSocket\Core\Protocol;

/**
 * Exhaustive Command Builder for all 80+ TimyTeco AiFace WebSocket Commands.
 */
class AiFaceCommandBuilder
{
    // ==========================================
    // 5. USER MANAGEMENT COMMANDS (17 Commands)
    // ==========================================

    /**
     * 5.1 setuserinfo — Set User Information & Credentials
     */
    public static function setUserInfo(
        int|string $enrollId,
        string $name,
        int $backupNum = 0,
        mixed $record = '',
        int $admin = 0,
        int $enable = 1,
        array $extra = []
    ): array {
        return array_merge([
            'cmd' => 'setuserinfo',
            'enrollid' => $enrollId,
            'name' => $name,
            'backupnum' => $backupNum,
            'admin' => $admin,
            'record' => $record,
            'enable' => $enable,
        ], $extra);
    }

    /**
     * 5.2 deleteuser — Delete User or User Credential
     *
     * In TimyTeco protocol:
     * - backupnum = 12 (or 13): Delete entire user (all biometrics, card, password)
     * - backupnum = 10: Delete password only
     * - backupnum = 11: Delete card only
     * - backupnum = 50: Delete face photo
     * - backupnum = 0-9: Delete specific fingerprint
     */
    public static function deleteUser(int|string $enrollId, ?int $backupNum = null): array
    {
        return [
            'cmd' => 'deleteuser',
            'enrollid' => is_numeric($enrollId) ? (int) $enrollId : $enrollId,
            'backupnum' => $backupNum ?? Protocol::BACKUP_DELETE_USER,
        ];
    }

    /**
     * 5.3 cleanuser — Clear All Users from device
     */
    public static function cleanUser(): array
    {
        return ['cmd' => 'cleanuser'];
    }

    /**
     * 5.4 setusername — Batch Set User Names
     * @param array<array{enrollid: int|string, name: string}> $users
     */
    public static function setUserName(array $users): array
    {
        return [
            'cmd' => 'setusername',
            'count' => count($users),
            'record' => $users,
        ];
    }

    /**
     * 5.5 getusername — Get User Name
     */
    public static function getUserName(int|string $enrollId): array
    {
        return [
            'cmd' => 'getusername',
            'enrollid' => $enrollId,
        ];
    }

    /**
     * 5.6 getuserlist — Get User ID List (Paginated)
     */
    public static function getUserList(int $page = 1, int $pageSize = 50): array
    {
        return [
            'cmd' => 'getuserlist',
            'page' => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 5.7 getuserids — Get All User IDs
     */
    public static function getUserIds(): array
    {
        return ['cmd' => 'getuserids'];
    }

    /**
     * 5.8 getunuserdid — Get Next Available/Unused User ID
     */
    public static function getUnusedUserId(): array
    {
        return ['cmd' => 'getunuserdid'];
    }

    /**
     * 5.9 checkuserid — Check If User ID Exists
     */
    public static function checkUserId(int|string $enrollId): array
    {
        return [
            'cmd' => 'checkuserid',
            'enrollid' => $enrollId,
        ];
    }

    /**
     * 5.10 getuserinfo — Get Detailed User Information
     */
    public static function getUserInfo(int|string $enrollId, int $backupNum = 0): array
    {
        return [
            'cmd' => 'getuserinfo',
            'enrollid' => $enrollId,
            'backupnum' => $backupNum,
        ];
    }

    /**
     * 5.11 getallusers — Get All User Data (Paginated, Multi-Record)
     */
    public static function getAllUsers(int $page = 1, int $pageSize = 10, ?int $backupNum = null): array
    {
        $payload = [
            'cmd' => 'getallusers',
            'page' => $page,
            'pagesize' => $pageSize,
        ];
        if ($backupNum !== null) {
            $payload['backupnum'] = $backupNum;
        }
        return $payload;
    }

    /**
     * 5.12 adduser — Trigger Device-Side Enrollment Wizard
     */
    public static function addUser(int|string $enrollId, int $backupNum = 0, int $admin = 0): array
    {
        return [
            'cmd' => 'adduser',
            'enrollid' => $enrollId,
            'backupnum' => $backupNum,
            'admin' => $admin,
        ];
    }

    /**
     * 5.13 checkregstatus — Check Device-Side Registration Status
     */
    public static function checkRegStatus(int|string $enrollId, int $backupNum = 0): array
    {
        return [
            'cmd' => 'checkregstatus',
            'enrollid' => $enrollId,
            'backupnum' => $backupNum,
        ];
    }

    /**
     * 5.14 canceladduser — Cancel On-Device User Enrollment
     */
    public static function cancelAddUser(int|string $enrollId, int $backupNum = 0): array
    {
        return [
            'cmd' => 'canceladduser',
            'enrollid' => $enrollId,
            'backupnum' => $backupNum,
        ];
    }

    /**
     * 5.15 enableuser — Enable/Disable User
     */
    public static function enableUser(int|string $enrollId, bool $enable = true): array
    {
        return [
            'cmd' => 'enableuser',
            'enrollid' => $enrollId,
            'enable' => $enable ? 1 : 0,
        ];
    }

    /**
     * 5.16 getuserprofile — Get User Custom Profile JSON
     */
    public static function getUserProfile(int|string $enrollId): array
    {
        return [
            'cmd' => 'getuserprofile',
            'enrollid' => $enrollId,
        ];
    }

    /**
     * 5.17 setuserprofile — Set User Custom Profile JSON
     */
    public static function setUserProfile(int|string $enrollId, array|string $profile): array
    {
        return [
            'cmd' => 'setuserprofile',
            'enrollid' => $enrollId,
            'profile' => is_array($profile) ? json_encode($profile) : $profile,
        ];
    }

    // ==========================================
    // 6. LOG MANAGEMENT COMMANDS (4 Commands)
    // ==========================================

    /**
     * 6.1 getnewlog — Get New Unread Attendance Records
     */
    public static function getNewLog(): array
    {
        return ['cmd' => 'getnewlog'];
    }

    /**
     * 6.2 getalllog — Get All Records (Paginated / Date Range)
     */
    public static function getAllLog(
        int $page = 1,
        int $pageSize = 100,
        ?string $startTime = null,
        ?string $endTime = null
    ): array {
        $payload = [
            'cmd' => 'getalllog',
            'page' => $page,
            'pagesize' => $pageSize,
        ];
        if ($startTime) $payload['starttime'] = $startTime;
        if ($endTime)   $payload['endtime'] = $endTime;
        return $payload;
    }

    /**
     * 6.3 cleanlog — Clear All Attendance Logs
     */
    public static function cleanLog(): array
    {
        return ['cmd' => 'cleanlog'];
    }

    /**
     * 6.4 cleanlogphoto — Clear Log Photos
     */
    public static function cleanLogPhoto(): array
    {
        return ['cmd' => 'cleanlogphoto'];
    }

    // ==========================================
    // 7. DEVICE MANAGEMENT COMMANDS (15 Commands)
    // ==========================================

    /**
     * 7.1 getdevinfo — Get Device System Parameters
     */
    public static function getDevInfo(): array
    {
        return ['cmd' => 'getdevinfo'];
    }

    /**
     * 7.2 setdevinfo — Set Device System Parameters
     */
    public static function setDevInfo(array $devInfo): array
    {
        return [
            'cmd' => 'setdevinfo',
            'devinfo' => $devInfo,
        ];
    }

    /**
     * 7.3 getdevcap — Get Device Capacity
     */
    public static function getDevCap(): array
    {
        return ['cmd' => 'getdevcap'];
    }

    /**
     * 7.4 gettime — Get Device Current Time
     */
    public static function getTime(): array
    {
        return ['cmd' => 'gettime'];
    }

    /**
     * 7.5 settime — Set Device Time
     */
    public static function setTime(?string $time = null): array
    {
        return [
            'cmd' => 'settime',
            'time' => $time ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 7.6 initsys — Factory Reset
     */
    public static function initSys(): array
    {
        return ['cmd' => 'initsys'];
    }

    /**
     * 7.7 initmenu — Reset Menu Settings
     */
    public static function initMenu(): array
    {
        return ['cmd' => 'initmenu'];
    }

    /**
     * 7.8 cleandatebase — Clear Database
     */
    public static function cleanDatabase(): array
    {
        return ['cmd' => 'cleandatebase'];
    }

    /**
     * 7.9 cleanadmin — Clear All Administrators
     */
    public static function cleanAdmin(): array
    {
        return ['cmd' => 'cleanadmin'];
    }

    /**
     * 7.10 cleaninactiveuser — Clear Inactive Users
     */
    public static function cleanInactiveUser(int $days = 30): array
    {
        return [
            'cmd' => 'cleaninactiveuser',
            'days' => $days,
        ];
    }

    /**
     * 7.11 reboot — Reboot Device
     */
    public static function reboot(): array
    {
        return ['cmd' => 'reboot'];
    }

    /**
     * 7.12 disabledevice — Disable Device Recognition
     */
    public static function disableDevice(): array
    {
        return ['cmd' => 'disabledevice'];
    }

    /**
     * 7.13 enabledevice — Enable Device Recognition
     */
    public static function enableDevice(): array
    {
        return ['cmd' => 'enabledevice'];
    }

    /**
     * 7.14 checklive — Heartbeat Check (Server-Issued)
     */
    public static function checkLive(): array
    {
        return ['cmd' => 'checklive'];
    }

    /**
     * 7.15 getreg — Get Device Registration Information
     */
    public static function getReg(): array
    {
        return ['cmd' => 'getreg'];
    }

    // ==========================================
    // 8. ACCESS CONTROL COMMANDS (9 Commands)
    // ==========================================

    /**
     * 8.1 opendoor — Remote Door Open / Unlock Relay
     */
    public static function openDoor(int $doorNum = 1): array
    {
        return [
            'cmd' => 'opendoor',
            'doornum' => $doorNum,
        ];
    }

    /**
     * 8.2 lockctrl — Door Lock Control
     * @param int $ctrl 0 = Normal, 1 = Always Open, 2 = Always Closed
     */
    public static function lockCtrl(int $ctrl = 0, int $doorNum = 1): array
    {
        return [
            'cmd' => 'lockctrl',
            'ctrl' => $ctrl,
            'doornum' => $doorNum,
        ];
    }

    /**
     * 8.3 getdoorstatus — Get Magnetic Door Sensor Status
     */
    public static function getDoorStatus(int $doorNum = 1): array
    {
        return [
            'cmd' => 'getdoorstatus',
            'doornum' => $doorNum,
        ];
    }

    /**
     * 8.4 setdevlock — Set Door Access Parameters
     */
    public static function setDevLock(array $lockParams): array
    {
        return array_merge(['cmd' => 'setdevlock'], $lockParams);
    }

    /**
     * 8.5 getdevlock — Get Door Access Parameters
     */
    public static function getDevLock(): array
    {
        return ['cmd' => 'getdevlock'];
    }

    /**
     * 8.6 getuserlock — Get User Door Access Parameters
     */
    public static function getUserLock(int|string $enrollId): array
    {
        return [
            'cmd' => 'getuserlock',
            'enrollid' => $enrollId,
        ];
    }

    /**
     * 8.7 setuserlock — Batch Set User Door Access Parameters
     */
    public static function setUserLock(array $records): array
    {
        return [
            'cmd' => 'setuserlock',
            'count' => count($records),
            'record' => $records,
        ];
    }

    /**
     * 8.8 deleteuserlock — Delete User Door Access Parameters
     */
    public static function deleteUserLock(int|string $enrollId): array
    {
        return [
            'cmd' => 'deleteuserlock',
            'enrollid' => $enrollId,
        ];
    }

    /**
     * 8.9 cleanuserlock — Clear All User Door Access Parameters
     */
    public static function cleanUserLock(): array
    {
        return ['cmd' => 'cleanuserlock'];
    }

    // ==========================================
    // 9. ATTENDANCE MANAGEMENT COMMANDS (6 Commands)
    // ==========================================

    /**
     * 9.1 getshift — Get Shift Schedule
     */
    public static function getShift(int $shiftId = 0): array
    {
        return [
            'cmd' => 'getshift',
            'shiftid' => $shiftId,
        ];
    }

    /**
     * 9.2 setshift — Set Shift Schedule
     */
    public static function setShift(int $shiftId, array $records): array
    {
        return [
            'cmd' => 'setshift',
            'shiftid' => $shiftId,
            'count' => count($records),
            'record' => $records,
        ];
    }

    /**
     * 9.3 getbelltime — Get Bell Schedule
     */
    public static function getBellTime(): array
    {
        return ['cmd' => 'getbelltime'];
    }

    /**
     * 9.4 setbelltime — Set Bell Schedule
     */
    public static function setBellTime(array $bells): array
    {
        return [
            'cmd' => 'setbelltime',
            'count' => count($bells),
            'record' => $bells,
        ];
    }

    /**
     * 9.5 setholiday — Set Holiday Schedule
     */
    public static function setHoliday(array $holidays): array
    {
        return [
            'cmd' => 'setholiday',
            'count' => count($holidays),
            'record' => $holidays,
        ];
    }

    /**
     * 9.6 getholiday — Get Holiday Schedule
     */
    public static function getHoliday(): array
    {
        return ['cmd' => 'getholiday'];
    }

    // ==========================================
    // 11. FILE MANAGEMENT COMMANDS (3 Commands)
    // ==========================================

    /**
     * 11.1 getdir — List Directory Files on device
     */
    public static function getDir(string $path = '/'): array
    {
        return [
            'cmd' => 'getdir',
            'path' => $path,
        ];
    }

    /**
     * 11.2 getfile — Read File from device (base64)
     */
    public static function getFile(string $filename, int $index = 0, int $count = 102400): array
    {
        return [
            'cmd' => 'getfile',
            'filename' => $filename,
            'index' => $index,
            'count' => $count,
        ];
    }

    /**
     * 11.3 writefile — Write File to device (base64)
     */
    public static function writeFile(string $filename, string $base64Record, int $index = 0, ?int $count = null): array
    {
        return [
            'cmd' => 'writefile',
            'filename' => $filename,
            'index' => $index,
            'count' => $count ?? strlen(base64_decode($base64Record)),
            'record' => $base64Record,
        ];
    }

    // ==========================================
    // 12. OTHER SYSTEM COMMANDS (15 Commands)
    // ==========================================

    /**
     * 12.1 upgrade — Trigger Firmware Upgrade via Server URL
     */
    public static function upgrade(string $url, string $md5 = ''): array
    {
        return [
            'cmd' => 'upgrade',
            'url' => $url,
            'md5' => $md5,
        ];
    }

    /**
     * 12.2 forceota — Force Immediate OTA Update
     */
    public static function forceOta(): array
    {
        return ['cmd' => 'forceota'];
    }

    /**
     * 12.3 setotaserver — Set OTA Server Address
     */
    public static function setOtaServer(string $url): array
    {
        return [
            'cmd' => 'setotaserver',
            'url' => $url,
        ];
    }

    /**
     * 12.4 getotaserver — Get OTA Server Address
     */
    public static function getOtaServer(): array
    {
        return ['cmd' => 'getotaserver'];
    }

    /**
     * 12.5 setscreensaver — Set Screensaver Image/Video
     */
    public static function setScreensaver(string $base64Data): array
    {
        return [
            'cmd' => 'setscreensaver',
            'record' => $base64Data,
        ];
    }

    /**
     * 12.6 setvoice — Set Custom Voice Audio
     */
    public static function setVoice(int $index, string $base64Audio): array
    {
        return [
            'cmd' => 'setvoice',
            'voiceindex' => $index,
            'record' => $base64Audio,
        ];
    }

    /**
     * 12.7 keypad — Control On-Screen Keypad Display
     */
    public static function keypad(bool $show = true): array
    {
        return [
            'cmd' => 'keypad',
            'show' => $show ? 1 : 0,
        ];
    }

    /**
     * 12.8 verify — Trigger Biometric Verification on Device
     */
    public static function verify(int|string $enrollId, int $verifyMode = 255): array
    {
        return [
            'cmd' => 'verify',
            'enrollid' => $enrollId,
            'verifymode' => $verifyMode,
        ];
    }

    /**
     * 12.9 setdepartment — Set Department Hierarchy List
     */
    public static function setDepartment(array $departments): array
    {
        return [
            'cmd' => 'setdepartment',
            'count' => count($departments),
            'record' => $departments,
        ];
    }

    /**
     * 12.10 getdepartment — Get Department Hierarchy List
     */
    public static function getDepartment(): array
    {
        return ['cmd' => 'getdepartment'];
    }

    /**
     * 12.11 setcompanyname — Set Company Title on Screen
     */
    public static function setCompanyName(string $name): array
    {
        return [
            'cmd' => 'setcompanyname',
            'companyname' => $name,
        ];
    }

    /**
     * 12.12 getcompanyname — Get Company Title
     */
    public static function getCompanyName(): array
    {
        return ['cmd' => 'getcompanyname'];
    }

    /**
     * 12.13 setquestionnaire — Set Questionnaire / Health Survey
     */
    public static function setQuestionnaire(array $questions): array
    {
        return [
            'cmd' => 'setquestionnaire',
            'count' => count($questions),
            'record' => $questions,
        ];
    }

    /**
     * 12.14 getquestionnaire — Get Questionnaire
     */
    public static function getQuestionnaire(): array
    {
        return ['cmd' => 'getquestionnaire'];
    }

    /**
     * 12.15 cleanquestionnaire — Clear Questionnaire
     */
    public static function cleanQuestionnaire(): array
    {
        return ['cmd' => 'cleanquestionnaire'];
    }

    // ==========================================
    // 13. VIDEO INTERCOM COMMANDS (11 Commands)
    // ==========================================

    /**
     * 13.1 enablewebrtc — Enable WebRTC Video Intercom
     */
    public static function enableWebRtc(
        bool $intercom = true,
        bool $monitor = true,
        int $waitSeconds = 30,
        string $stunServer = '',
        string $turnServer = '',
        string $turnUser = '',
        string $turnPassword = ''
    ): array {
        return [
            'cmd' => 'enablewebrtc',
            'intercom' => $intercom,
            'monitor' => $monitor,
            'waitseconds' => $waitSeconds,
            'stunserver' => $stunServer,
            'turnserver' => $turnServer,
            'turnusername' => $turnUser,
            'turnpassword' => $turnPassword,
        ];
    }

    /**
     * 13.2 callaccept — Accept or Reject Incoming Intercom Call
     */
    public static function callAccept(string $sessionId, bool $accept = true): array
    {
        return [
            'cmd' => 'callaccept',
            'result' => $accept,
            'sessionid' => $sessionId,
        ];
    }

    /**
     * 13.3 viewready — Signal Monitor Ready
     */
    public static function viewReady(string $sessionId, bool $ready = true): array
    {
        return [
            'cmd' => 'viewready',
            'result' => $ready,
            'sessionid' => $sessionId,
        ];
    }

    /**
     * 13.4 answer — Process WebRTC Answer SDP
     */
    public static function answer(string $sessionId, string $sdp): array
    {
        return [
            'cmd' => 'answer',
            'sessionid' => $sessionId,
            'type' => 'answer',
            'sdp' => $sdp,
        ];
    }

    /**
     * 13.5 icecandidate — Process WebRTC ICE Candidate
     */
    public static function iceCandidate(string $sessionId, string $candidate, ?string $sdpMid = null, ?int $sdpMLineIndex = null): array
    {
        return [
            'cmd' => 'icecandidate',
            'sessionid' => $sessionId,
            'candidate' => $candidate,
            'sdpmid' => $sdpMid,
            'sdpmlineindex' => $sdpMLineIndex,
        ];
    }

    /**
     * 13.6 callcancel — Cancel Active Intercom Call
     */
    public static function callCancel(string $sessionId): array
    {
        return [
            'cmd' => 'callcancel',
            'sessionid' => $sessionId,
        ];
    }

    /**
     * 13.7 talklock — Door Control During Video Call
     */
    public static function talkLock(string $sessionId, int $doorNum = 1): array
    {
        return [
            'cmd' => 'talklock',
            'sessionid' => $sessionId,
            'doornum' => $doorNum,
        ];
    }

    /**
     * 13.8 monitorcall — Start Remote Video Monitoring
     */
    public static function monitorCall(string $sessionId): array
    {
        return [
            'cmd' => 'monitorcall',
            'sessionid' => $sessionId,
        ];
    }

    /**
     * 13.9 setviselection — Set Visual Selection Menu on Screen
     */
    public static function setViSelection(array $selections): array
    {
        return [
            'cmd' => 'setviselection',
            'count' => count($selections),
            'record' => $selections,
        ];
    }
}
