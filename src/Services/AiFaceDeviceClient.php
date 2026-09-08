<?php

namespace AiFace\WebSocket\Services;

use AiFace\WebSocket\Commands\AiFaceCommandBuilder;
use AiFace\WebSocket\Core\Protocol;

/**
 * Fluent interaction client for a specific AiFace device serial number.
 */
class AiFaceDeviceClient
{
    protected string $sn;
    protected array $config;
    protected string $ipcPath;

    public function __construct(string $sn, array $config)
    {
        $this->sn = $sn;
        $this->config = $config;
        $port = (int) ($config['server']['port'] ?? 7788);
        $this->ipcPath = sys_get_temp_dir() . '/aiface_ipc_' . $port . '.sock';
    }

    public function getSn(): string
    {
        return $this->sn;
    }

    /**
     * Establish IPC connection to running WebSocket daemon via local TCP or Unix socket.
     */
    protected function connectIpc(float $timeout = 2.0)
    {
        $ipcHost = $this->config['server']['ipc_host'] ?? '127.0.0.1';
        $port = (int) ($this->config['server']['port'] ?? 7788);
        $ipcPort = (int) ($this->config['server']['ipc_port'] ?? ($port + 1));

        // 1. Try TCP loopback bridge (works 100% reliably between CLI and PHP-FPM / Laravel Herd)
        $fp = @stream_socket_client("tcp://{$ipcHost}:{$ipcPort}", $errno, $errstr, $timeout);
        if ($fp) {
            return $fp;
        }

        // 2. Try standard /tmp Unix socket
        $tmpPath = "/tmp/aiface_ipc_{$port}.sock";
        if (file_exists($tmpPath)) {
            $fp = @stream_socket_client("unix://{$tmpPath}", $errno, $errstr, $timeout);
            if ($fp) {
                return $fp;
            }
        }

        // 3. Try sys_get_temp_dir() Unix socket
        $sysPath = sys_get_temp_dir() . "/aiface_ipc_{$port}.sock";
        if (file_exists($sysPath)) {
            $fp = @stream_socket_client("unix://{$sysPath}", $errno, $errstr, $timeout);
            if ($fp) {
                return $fp;
            }
        }

        return null;
    }

    /**
     * Send arbitrary command to device via WebSocket daemon IPC bridge.
     */
    public function send(string $cmd, array $params = [], ?float $timeout = null): array
    {
        $timeout = $timeout ?? (float) ($this->config['server']['command_timeout'] ?? 10.0);

        $fp = $this->connectIpc(2.0);
        if (!$fp) {
            $port = (int) ($this->config['server']['port'] ?? 7788);
            $ipcPort = (int) ($this->config['server']['ipc_port'] ?? ($port + 1));
            return [
                'result' => false,
                'error' => "AiFace WebSocket daemon is not running (could not connect to IPC on 127.0.0.1:{$ipcPort} or /tmp/aiface_ipc_{$port}.sock). Please start the daemon using 'php artisan aiface:serve'.",
            ];
        }

        $ipcPayload = [
            'action' => 'command',
            'sn' => $this->sn,
            'cmd' => $cmd,
            'payload' => $params,
            'timeout' => $timeout,
        ];

        @fwrite($fp, json_encode($ipcPayload));
        stream_set_timeout($fp, (int) ceil($timeout) + 2);

        $responseRaw = '';
        while (!feof($fp)) {
            $chunk = @fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $responseRaw .= $chunk;
        }
        @fclose($fp);

        $response = json_decode($responseRaw, true);
        if (!is_array($response)) {
            return [
                'result' => false,
                'error' => 'Invalid or empty response from AiFace daemon: ' . $responseRaw,
            ];
        }

        if (isset($response['result']) && $response['result'] === true && isset($response['data'])) {
            return $response['data'];
        }

        return $response;
    }

    // ==========================================
    // 5. USER MANAGEMENT
    // ==========================================

    public function setUserInfo(
        int|string $enrollId,
        string $name,
        int $backupNum = 0,
        mixed $record = '',
        int $admin = 0,
        int $enable = 1,
        array $extra = []
    ): array {
        $data = AiFaceCommandBuilder::setUserInfo($enrollId, $name, $backupNum, $record, $admin, $enable, $extra);
        $cmd = array_shift($data);
        return $this->send($cmd, $data);
    }

    public function setUserFacePhoto(int|string $enrollId, string $name, string $base64Jpg, int $admin = 0): array
    {
        return $this->setUserInfo($enrollId, $name, Protocol::BACKUP_FACE_PHOTO, $base64Jpg, $admin);
    }

    public function setUserPassword(int|string $enrollId, string $name, int|string $pwd, int $admin = 0): array
    {
        $record = (is_numeric($pwd) && $pwd !== '') ? (int) $pwd : $pwd;
        return $this->setUserInfo($enrollId, $name, Protocol::BACKUP_PASSWORD, $record, $admin);
    }

    public function setUserCard(int|string $enrollId, string $name, int|string $cardNumber, int $admin = 0): array
    {
        return $this->setUserInfo($enrollId, $name, Protocol::BACKUP_CARD, (int) $cardNumber, $admin);
    }

    public function setUserFingerprint(int|string $enrollId, string $name, int $slot, string $compressedFpHex, int $admin = 0): array
    {
        return $this->setUserInfo($enrollId, $name, $slot, $compressedFpHex, $admin);
    }

    public function deleteUser(
        int|string $enrollId,
        ?int $backupNum = null,
        int|\DateTimeInterface|null $delay = null
    ): array {
        if ($delay !== null) {
            return $this->delayedDeleteUser($enrollId, $delay, $backupNum);
        }

        $data = AiFaceCommandBuilder::deleteUser($enrollId, $backupNum);
        $cmd = array_shift($data);
        return $this->send($cmd, $data);
    }

    /**
     * Schedule a delayed user deletion on the device.
     * The delete command will be effected on the hardware terminal only after $delay has elapsed.
     *
     * @param int|string $enrollId User ID to delete
     * @param int|\DateTimeInterface $delay Delay in seconds or target execution DateTime
     * @param int|null $backupNum Optional credential type (null = delete entire user)
     */
    public function delayedDeleteUser(
        int|string $enrollId,
        int|\DateTimeInterface $delay,
        ?int $backupNum = null
    ): array {
        $now = time();
        if ($delay instanceof \DateTimeInterface) {
            $executeAt = $delay->getTimestamp();
            $delaySeconds = max(0, $executeAt - $now);
        } else {
            $delaySeconds = max(0, (int) $delay);
            $executeAt = $now + $delaySeconds;
        }

        // If delay is 0 or negative, delete immediately
        if ($delaySeconds <= 0) {
            $data = AiFaceCommandBuilder::deleteUser($enrollId, $backupNum);
            $cmd = array_shift($data);
            return $this->send($cmd, $data);
        }

        return $this->send('delayeddelete', [
            'enrollid'      => $enrollId,
            'backupnum'     => $backupNum,
            'delay'         => $delaySeconds,
            'execute_at'    => $executeAt,
        ]);
    }

    /**
     * Cancel a pending delayed deletion for a user on this device.
     */
    public function cancelDelayedDelete(int|string $enrollId): array
    {
        return $this->send('canceldelayeddelete', [
            'enrollid' => $enrollId,
        ]);
    }

    /**
     * Get all pending delayed deletions scheduled for this device.
     */
    public function getPendingDelayedDeletes(): array
    {
        return $this->send('getpendingdelayeddeletes');
    }

    public function cleanUser(): array
    {
        return $this->send('cleanuser');
    }

    public function setUserName(array $users): array
    {
        $data = AiFaceCommandBuilder::setUserName($users);
        $cmd = array_shift($data);
        return $this->send($cmd, $data);
    }

    public function getUserName(int|string $enrollId): array
    {
        return $this->send('getusername', ['enrollid' => $enrollId]);
    }

    public function getUserList(int $page = 1, int $pageSize = 50): array
    {
        return $this->send('getuserlist', ['page' => $page, 'pagesize' => $pageSize]);
    }

    public function getUserIds(): array
    {
        return $this->send('getuserids');
    }

    public function getUnusedUserId(): array
    {
        return $this->send('getunuserdid');
    }

    public function checkUserId(int|string $enrollId): array
    {
        return $this->send('checkuserid', ['enrollid' => $enrollId]);
    }

    public function getUserInfo(int|string $enrollId, int $backupNum = 0): array
    {
        return $this->send('getuserinfo', ['enrollid' => $enrollId, 'backupnum' => $backupNum]);
    }

    public function getAllUsers(int $page = 1, int $pageSize = 10, ?int $backupNum = null): array
    {
        $params = ['page' => $page, 'pagesize' => $pageSize];
        if ($backupNum !== null) $params['backupnum'] = $backupNum;
        return $this->send('getallusers', $params);
    }

    public function addUser(int|string $enrollId, int $backupNum = 0, int $admin = 0): array
    {
        return $this->send('adduser', ['enrollid' => $enrollId, 'backupnum' => $backupNum, 'admin' => $admin]);
    }

    public function checkRegStatus(int|string $enrollId, int $backupNum = 0): array
    {
        return $this->send('checkregstatus', ['enrollid' => $enrollId, 'backupnum' => $backupNum]);
    }

    public function cancelAddUser(int|string $enrollId, int $backupNum = 0): array
    {
        return $this->send('canceladduser', ['enrollid' => $enrollId, 'backupnum' => $backupNum]);
    }

    public function enableUser(int|string $enrollId, bool $enable = true): array
    {
        return $this->send('enableuser', ['enrollid' => $enrollId, 'enable' => $enable ? 1 : 0]);
    }

    public function getUserProfile(int|string $enrollId): array
    {
        return $this->send('getuserprofile', ['enrollid' => $enrollId]);
    }

    public function setUserProfile(int|string $enrollId, array|string $profile): array
    {
        return $this->send('setuserprofile', [
            'enrollid' => $enrollId,
            'profile' => is_array($profile) ? json_encode($profile) : $profile,
        ]);
    }

    // ==========================================
    // 6. LOG MANAGEMENT
    // ==========================================

    public function getNewLog(bool $fireLocalEvents = true): array
    {
        $response = $this->send('getnewlog');
        if ($fireLocalEvents && isset($response['record']) && is_array($response['record'])) {
            $this->dispatchAttendanceEvents($response['record']);
        }
        return $response;
    }

    public function getAllLog(int $page = 1, int $pageSize = 100, ?string $startTime = null, ?string $endTime = null, bool $fireLocalEvents = true): array
    {
        $params = ['page' => $page, 'pagesize' => $pageSize];
        if ($startTime) $params['starttime'] = $startTime;
        if ($endTime)   $params['endtime'] = $endTime;
        $response = $this->send('getalllog', $params);
        if ($fireLocalEvents && isset($response['record']) && is_array($response['record'])) {
            $this->dispatchAttendanceEvents($response['record']);
        }
        return $response;
    }

    /**
     * Dispatch UserClockedIn and UserClockedOut events in the current Laravel application process.
     * Useful when logs are retrieved via API/SDK or pulled from database.
     *
     * @param array $records Single record or array of attendance records
     * @return array Normalized records for which events were fired
     */
    public function dispatchAttendanceEvents(array $records): array
    {
        if (empty($records)) {
            return [];
        }

        // Normalize if single record (associative array)
        if (isset($records['enrollid']) || isset($records['time']) || isset($records['punch_time'])) {
            $records = [$records];
        }

        $dispatched = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }

            $inoutVal = $rec['inout'] ?? $rec['direction'] ?? null;
            $eventVal = $rec['event'] ?? null;
            $actionVal = strtolower((string) ($rec['action'] ?? ''));

            $isClockOut = false;
            if ($inoutVal !== null) {
                $iv = is_numeric($inoutVal) ? (int) $inoutVal : (in_array(strtolower((string) $inoutVal), ['out', 'clockout', 'clock_out'], true) ? 1 : 0);
                $isClockOut = in_array($iv, [1, 2, 5], true);
            } elseif ($actionVal === 'clock_out' || $actionVal === 'clockout' || $actionVal === 'out') {
                $isClockOut = true;
            } elseif ($eventVal !== null && in_array((int) $eventVal, [1, 2, 5], true)) {
                $isClockOut = true;
            }

            $rec['inout'] = $isClockOut ? 1 : 0;
            $dispatched[] = $rec;

            if (class_exists(\Illuminate\Support\Facades\Event::class) && \Illuminate\Support\Facades\Facade::getFacadeApplication()) {
                if ($isClockOut) {
                    \Illuminate\Support\Facades\Event::dispatch(new \AiFace\WebSocket\Events\UserClockedOut($this->sn, $rec));
                } else {
                    \Illuminate\Support\Facades\Event::dispatch(new \AiFace\WebSocket\Events\UserClockedIn($this->sn, $rec));
                }
            }
        }

        if (!empty($dispatched) && class_exists(\Illuminate\Support\Facades\Event::class) && \Illuminate\Support\Facades\Facade::getFacadeApplication()) {
            \Illuminate\Support\Facades\Event::dispatch(new \AiFace\WebSocket\Events\AttendanceLogReceived($this->sn, $dispatched, count($dispatched)));
        }

        return $dispatched;
    }

    public function cleanLog(): array
    {
        return $this->send('cleanlog');
    }

    public function cleanLogPhoto(): array
    {
        return $this->send('cleanlogphoto');
    }

    // ==========================================
    // 7. DEVICE MANAGEMENT
    // ==========================================

    public function getDevInfo(): array
    {
        return $this->send('getdevinfo');
    }

    public function setDevInfo(array $devInfo): array
    {
        return $this->send('setdevinfo', ['devinfo' => $devInfo]);
    }

    public function getDevCap(): array
    {
        return $this->send('getdevcap');
    }

    public function getTime(): array
    {
        return $this->send('gettime');
    }

    public function setTime(?string $time = null): array
    {
        return $this->send('settime', ['time' => $time ?? date('Y-m-d H:i:s')]);
    }

    public function syncTime(): array
    {
        return $this->setTime(date('Y-m-d H:i:s'));
    }

    public function initSys(): array
    {
        return $this->send('initsys');
    }

    public function initMenu(): array
    {
        return $this->send('initmenu');
    }

    public function cleanDatabase(): array
    {
        return $this->send('cleandatebase');
    }

    public function cleanAdmin(): array
    {
        return $this->send('cleanadmin');
    }

    public function cleanInactiveUser(int $days = 30): array
    {
        return $this->send('cleaninactiveuser', ['days' => $days]);
    }

    public function reboot(): array
    {
        return $this->send('reboot');
    }

    public function disableDevice(): array
    {
        return $this->send('disabledevice');
    }

    public function enableDevice(): array
    {
        return $this->send('enabledevice');
    }

    public function checkLive(): array
    {
        return $this->send('checklive');
    }

    public function getReg(): array
    {
        return $this->send('getreg');
    }

    // ==========================================
    // 8. ACCESS CONTROL
    // ==========================================

    public function openDoor(int $doorNum = 1): array
    {
        return $this->send('opendoor', ['doornum' => $doorNum]);
    }

    public function lockCtrl(int $ctrl = 0, int $doorNum = 1): array
    {
        return $this->send('lockctrl', ['ctrl' => $ctrl, 'doornum' => $doorNum]);
    }

    public function getDoorStatus(int $doorNum = 1): array
    {
        return $this->send('getdoorstatus', ['doornum' => $doorNum]);
    }

    public function setDevLock(array $lockParams): array
    {
        return $this->send('setdevlock', $lockParams);
    }

    public function getDevLock(): array
    {
        return $this->send('getdevlock');
    }

    public function getUserLock(int|string $enrollId): array
    {
        return $this->send('getuserlock', ['enrollid' => $enrollId]);
    }

    public function setUserLock(array $records): array
    {
        return $this->send('setuserlock', ['count' => count($records), 'record' => $records]);
    }

    public function deleteUserLock(int|string $enrollId): array
    {
        return $this->send('deleteuserlock', ['enrollid' => $enrollId]);
    }

    public function cleanUserLock(): array
    {
        return $this->send('cleanuserlock');
    }

    // ==========================================
    // 9. ATTENDANCE MANAGEMENT
    // ==========================================

    public function getShift(int $shiftId = 0): array
    {
        return $this->send('getshift', ['shiftid' => $shiftId]);
    }

    public function setShift(int $shiftId, array $records): array
    {
        return $this->send('setshift', ['shiftid' => $shiftId, 'count' => count($records), 'record' => $records]);
    }

    public function getBellTime(): array
    {
        return $this->send('getbelltime');
    }

    public function setBellTime(array $bells): array
    {
        return $this->send('setbelltime', ['count' => count($bells), 'record' => $bells]);
    }

    public function setHoliday(array $holidays): array
    {
        return $this->send('setholiday', ['count' => count($holidays), 'record' => $holidays]);
    }

    public function getHoliday(): array
    {
        return $this->send('getholiday');
    }

    // ==========================================
    // 11. FILE MANAGEMENT
    // ==========================================

    public function getDir(string $path = '/'): array
    {
        return $this->send('getdir', ['path' => $path]);
    }

    public function getFile(string $filename, int $index = 0, int $count = 102400): array
    {
        return $this->send('getfile', ['filename' => $filename, 'index' => $index, 'count' => $count]);
    }

    public function writeFile(string $filename, string $base64Record, int $index = 0, ?int $count = null): array
    {
        return $this->send('writefile', [
            'filename' => $filename,
            'index' => $index,
            'count' => $count ?? strlen(base64_decode($base64Record)),
            'record' => $base64Record,
        ]);
    }

    // ==========================================
    // 12. OTHER SYSTEM COMMANDS
    // ==========================================

    public function upgrade(string $url, string $md5 = ''): array
    {
        return $this->send('upgrade', ['url' => $url, 'md5' => $md5]);
    }

    public function forceOta(): array
    {
        return $this->send('forceota');
    }

    public function setOtaServer(string $url): array
    {
        return $this->send('setotaserver', ['url' => $url]);
    }

    public function getOtaServer(): array
    {
        return $this->send('getotaserver');
    }

    public function setScreensaver(string $base64Data): array
    {
        return $this->send('setscreensaver', ['record' => $base64Data]);
    }

    public function setVoice(int $index, string $base64Audio): array
    {
        return $this->send('setvoice', ['voiceindex' => $index, 'record' => $base64Audio]);
    }

    public function keypad(bool $show = true): array
    {
        return $this->send('keypad', ['show' => $show ? 1 : 0]);
    }

    public function verify(int|string $enrollId, int $verifyMode = 255): array
    {
        return $this->send('verify', ['enrollid' => $enrollId, 'verifymode' => $verifyMode]);
    }

    public function setDepartment(array $departments): array
    {
        return $this->send('setdepartment', ['count' => count($departments), 'record' => $departments]);
    }

    public function getDepartment(): array
    {
        return $this->send('getdepartment');
    }

    public function setCompanyName(string $name): array
    {
        return $this->send('setcompanyname', ['companyname' => $name]);
    }

    public function getCompanyName(): array
    {
        return $this->send('getcompanyname');
    }

    public function setQuestionnaire(array $questions): array
    {
        return $this->send('setquestionnaire', ['count' => count($questions), 'record' => $questions]);
    }

    public function getQuestionnaire(): array
    {
        return $this->send('getquestionnaire');
    }

    public function cleanQuestionnaire(): array
    {
        return $this->send('cleanquestionnaire');
    }

    // ==========================================
    // 13. VIDEO INTERCOM COMMANDS
    // ==========================================

    public function enableWebRtc(
        bool $intercom = true,
        bool $monitor = true,
        int $waitSeconds = 30,
        string $stunServer = '',
        string $turnServer = '',
        string $turnUser = '',
        string $turnPassword = ''
    ): array {
        return $this->send('enablewebrtc', [
            'intercom' => $intercom,
            'monitor' => $monitor,
            'waitseconds' => $waitSeconds,
            'stunserver' => $stunServer,
            'turnserver' => $turnServer,
            'turnusername' => $turnUser,
            'turnpassword' => $turnPassword,
        ]);
    }

    public function callAccept(string $sessionId, bool $accept = true): array
    {
        return $this->send('callaccept', ['result' => $accept, 'sessionid' => $sessionId]);
    }

    public function viewReady(string $sessionId, bool $ready = true): array
    {
        return $this->send('viewready', ['result' => $ready, 'sessionid' => $sessionId]);
    }

    public function answer(string $sessionId, string $sdp): array
    {
        return $this->send('answer', ['sessionid' => $sessionId, 'type' => 'answer', 'sdp' => $sdp]);
    }

    public function iceCandidate(string $sessionId, string $candidate, ?string $sdpMid = null, ?int $sdpMLineIndex = null): array
    {
        return $this->send('icecandidate', [
            'sessionid' => $sessionId,
            'candidate' => $candidate,
            'sdpmid' => $sdpMid,
            'sdpmlineindex' => $sdpMLineIndex,
        ]);
    }

    public function callCancel(string $sessionId): array
    {
        return $this->send('callcancel', ['sessionid' => $sessionId]);
    }

    public function talkLock(string $sessionId, int $doorNum = 1): array
    {
        return $this->send('talklock', ['sessionid' => $sessionId, 'doornum' => $doorNum]);
    }

    public function monitorCall(string $sessionId): array
    {
        return $this->send('monitorcall', ['sessionid' => $sessionId]);
    }

    public function setViSelection(array $selections): array
    {
        return $this->send('setviselection', ['count' => count($selections), 'record' => $selections]);
    }
}
