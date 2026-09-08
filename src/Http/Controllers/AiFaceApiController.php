<?php

namespace AiFace\WebSocket\Http\Controllers;

use AiFace\WebSocket\Facades\AiFace;
use AiFace\WebSocket\Models\AiFaceAttendanceLog;
use AiFace\WebSocket\Models\AiFaceDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AiFaceApiController extends Controller
{
    /**
     * List all devices (both registered in DB and live online).
     */
    public function listDevices(): JsonResponse
    {
        $onlineDevices = AiFace::getOnlineDevices();
        $dbDevices = AiFaceDevice::all()->keyBy('sn');

        $result = [];
        // Merge DB records and live connections
        foreach ($dbDevices as $sn => $dev) {
            $isOnline = isset($onlineDevices[$sn]);
            $result[$sn] = [
                'sn' => $sn,
                'model' => $dev->model,
                'ip' => $isOnline ? $onlineDevices[$sn]['ip'] : $dev->ip,
                'status' => $isOnline ? 'online' : 'offline',
                'users' => $dev->useduser,
                'faces' => $dev->usedface,
                'fps' => $dev->usedfp,
                'logs' => $dev->usedlog,
                'last_seen' => $dev->last_seen_at?->toDateTimeString(),
            ];
        }

        foreach ($onlineDevices as $sn => $live) {
            if (!isset($result[$sn])) {
                $result[$sn] = [
                    'sn' => $sn,
                    'model' => $live['devinfo']['modelname'] ?? 'AiFace',
                    'ip' => $live['ip'],
                    'status' => 'online',
                    'users' => $live['devinfo']['useduser'] ?? 0,
                    'faces' => $live['devinfo']['usedface'] ?? 0,
                    'fps' => $live['devinfo']['usedfp'] ?? 0,
                    'logs' => $live['devinfo']['usedlog'] ?? 0,
                    'last_seen' => $live['last_seen'],
                ];
            }
        }

        return response()->json([
            'result' => true,
            'count' => count($result),
            'devices' => array_values($result),
        ]);
    }

    /**
     * Get specific device info and connection status.
     */
    public function getDevice(string $sn): JsonResponse
    {
        $device = AiFaceDevice::where('sn', $sn)->first();
        $isOnline = AiFace::isOnline($sn);

        if (!$device && !$isOnline) {
            return response()->json([
                'result' => false,
                'error' => "Device [{$sn}] not found",
            ], 404);
        }

        return response()->json([
            'result' => true,
            'sn' => $sn,
            'is_online' => $isOnline,
            'device' => $device,
        ]);
    }

    /**
     * Execute any arbitrary command on a connected device.
     */
    public function executeCommand(Request $request, string $sn): JsonResponse
    {
        $cmd = $request->input('cmd');
        if (empty($cmd)) {
            return response()->json(['result' => false, 'error' => 'Missing "cmd" parameter'], 422);
        }

        $params = $request->input('params', []);
        if (!is_array($params)) {
            $params = [];
        }

        $timeout = $request->input('timeout', null);
        $response = AiFace::device($sn)->send($cmd, $params, $timeout ? (float) $timeout : null);

        $status = isset($response['result']) && $response['result'] === true ? 200 : 400;
        return response()->json($response, $status);
    }

    /**
     * Remote unlock door.
     */
    public function openDoor(Request $request, string $sn): JsonResponse
    {
        $doorNum = (int) $request->input('doornum', 1);
        $response = AiFace::device($sn)->openDoor($doorNum);
        return response()->json($response);
    }

    /**
     * Reboot device.
     */
    public function reboot(string $sn): JsonResponse
    {
        $response = AiFace::device($sn)->reboot();
        return response()->json($response);
    }

    /**
     * Synchronize device clock with server time.
     */
    public function syncTime(string $sn): JsonResponse
    {
        $response = AiFace::device($sn)->syncTime();
        return response()->json($response);
    }

    /**
     * Pull new unread attendance logs from device.
     */
    public function getNewLogs(string $sn): JsonResponse
    {
        $response = AiFace::device($sn)->getNewLog();
        return response()->json($response);
    }

    /**
     * Query user IDs on device.
     */
    public function getUsers(Request $request, string $sn): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pagesize', 50);
        $response = AiFace::device($sn)->getUserList($page, $pageSize);
        return response()->json($response);
    }

    /**
     * Create or update user on device.
     */
    public function saveUser(Request $request, string $sn): JsonResponse
    {
        $enrollId = $request->input('enrollid');
        $name = $request->input('name');

        if (!$enrollId || !$name) {
            return response()->json(['result' => false, 'error' => 'Missing enrollid or name'], 422);
        }

        $backupNum = (int) $request->input('backupnum', 0);
        $record = $request->input('record', '');
        $admin = (int) $request->input('admin', 0);
        $enable = (int) $request->input('enable', 1);

        $response = AiFace::device($sn)->setUserInfo($enrollId, $name, $backupNum, $record, $admin, $enable);
        return response()->json($response);
    }

    /**
     * Delete user from device (supports immediate or delayed deletion).
     */
    public function deleteUser(Request $request, string $sn, string $enrollId): JsonResponse
    {
        $backupNum = $request->has('backupnum') ? (int) $request->input('backupnum') : null;
        $delay = $request->input('delay');

        if ($delay !== null && is_numeric($delay)) {
            $delay = (int) $delay;
        }

        $response = AiFace::device($sn)->deleteUser($enrollId, $backupNum, $delay);
        return response()->json($response);
    }

    /**
     * Get list of pending scheduled delayed delete commands for a device.
     */
    public function getPendingDelayedDeletes(string $sn): JsonResponse
    {
        $response = AiFace::device($sn)->getPendingDelayedDeletes();
        return response()->json($response);
    }

    /**
     * Cancel a pending delayed delete command for an enrollment ID on a device.
     */
    public function cancelDelayedDelete(string $sn, string $enrollId): JsonResponse
    {
        $response = AiFace::device($sn)->cancelDelayedDelete($enrollId);
        return response()->json($response);
    }

    /**
     * Get list of all pending queued commands for a device.
     */
    public function getPendingCommands(string $sn): JsonResponse
    {
        $response = AiFace::device($sn)->getPendingCommands();
        return response()->json($response);
    }

    /**
     * Cancel a pending queued command by task ID.
     */
    public function cancelQueuedCommand(string $sn, string $taskId): JsonResponse
    {
        $response = AiFace::device($sn)->cancelQueuedCommand($taskId);
        return response()->json($response);
    }

    /**
     * Fetch recent attendance records from database.
     */
    public function listLogs(Request $request): JsonResponse
    {
        $query = AiFaceAttendanceLog::query()->orderBy('punch_time', 'desc');

        if ($request->has('sn')) {
            $query->where('sn', $request->input('sn'));
        }
        if ($request->has('enrollid')) {
            $query->where('enrollid', $request->input('enrollid'));
        }

        $logs = $query->paginate((int) $request->input('per_page', 50));
        return response()->json([
            'result' => true,
            'logs' => $logs,
        ]);
    }

    /**
     * Return catalog of all supported commands with sample schemas.
     */
    public function commandCatalog(): JsonResponse
    {
        $catalog = [
            'User Management' => [
                'setuserinfo' => ['enrollid' => 123, 'name' => 'John', 'backupnum' => 0, 'record' => '', 'admin' => 0],
                'deleteuser' => ['enrollid' => 123],
                'cleanuser' => [],
                'setusername' => ['count' => 1, 'record' => [['enrollid' => 123, 'name' => 'John']]],
                'getusername' => ['enrollid' => 123],
                'getuserlist' => ['page' => 1, 'pagesize' => 50],
                'getuserids' => [],
                'getunuserdid' => [],
                'checkuserid' => ['enrollid' => 123],
                'getuserinfo' => ['enrollid' => 123, 'backupnum' => 0],
                'getallusers' => ['page' => 1, 'pagesize' => 10],
                'adduser' => ['enrollid' => 123, 'backupnum' => 0, 'admin' => 0],
                'checkregstatus' => ['enrollid' => 123, 'backupnum' => 0],
                'canceladduser' => ['enrollid' => 123, 'backupnum' => 0],
                'enableuser' => ['enrollid' => 123, 'enable' => 1],
                'getuserprofile' => ['enrollid' => 123],
                'setuserprofile' => ['enrollid' => 123, 'profile' => '{}'],
            ],
            'Log Management' => [
                'getnewlog' => [],
                'getalllog' => ['page' => 1, 'pagesize' => 100],
                'cleanlog' => [],
                'cleanlogphoto' => [],
            ],
            'Device Management' => [
                'getdevinfo' => [],
                'setdevinfo' => ['devinfo' => ['volume' => 80]],
                'getdevcap' => [],
                'gettime' => [],
                'settime' => ['time' => date('Y-m-d H:i:s')],
                'initsys' => [],
                'initmenu' => [],
                'cleandatebase' => [],
                'cleanadmin' => [],
                'cleaninactiveuser' => ['days' => 30],
                'reboot' => [],
                'disabledevice' => [],
                'enabledevice' => [],
                'checklive' => [],
                'getreg' => [],
            ],
            'Access Control' => [
                'opendoor' => ['doornum' => 1],
                'lockctrl' => ['ctrl' => 0, 'doornum' => 1],
                'getdoorstatus' => ['doornum' => 1],
                'setdevlock' => ['opendoordelay' => 5],
                'getdevlock' => [],
                'getuserlock' => ['enrollid' => 123],
                'setuserlock' => ['count' => 1, 'record' => [['enrollid' => 123]]],
                'deleteuserlock' => ['enrollid' => 123],
                'cleanuserlock' => [],
            ],
            'Attendance' => [
                'getshift' => ['shiftid' => 0],
                'setshift' => ['shiftid' => 0, 'count' => 1, 'record' => []],
                'getbelltime' => [],
                'setbelltime' => ['count' => 0, 'record' => []],
                'setholiday' => ['count' => 0, 'record' => []],
                'getholiday' => [],
            ],
            'File Management' => [
                'getdir' => ['path' => '/'],
                'getfile' => ['filename' => 'config.txt', 'index' => 0, 'count' => 102400],
                'writefile' => ['filename' => 'test.txt', 'index' => 0, 'count' => 4, 'record' => base64_encode('test')],
            ],
            'System & Other' => [
                'upgrade' => ['url' => 'http://example.com/update.pkg', 'md5' => ''],
                'forceota' => [],
                'setotaserver' => ['url' => 'http://example.com/ota'],
                'getotaserver' => [],
                'setscreensaver' => ['record' => 'base64_data'],
                'setvoice' => ['voiceindex' => 0, 'record' => 'base64_data'],
                'keypad' => ['show' => 1],
                'verify' => ['enrollid' => 123, 'verifymode' => 255],
                'setdepartment' => ['count' => 0, 'record' => []],
                'getdepartment' => [],
                'setcompanyname' => ['companyname' => 'Company Corp'],
                'getcompanyname' => [],
                'setquestionnaire' => ['count' => 0, 'record' => []],
                'getquestionnaire' => [],
                'cleanquestionnaire' => [],
            ],
            'Video Intercom' => [
                'enablewebrtc' => ['intercom' => true, 'monitor' => true, 'waitseconds' => 30],
                'callaccept' => ['result' => true, 'sessionid' => 'call_123'],
                'viewready' => ['result' => true, 'sessionid' => 'call_123'],
                'answer' => ['sessionid' => 'call_123', 'type' => 'answer', 'sdp' => ''],
                'icecandidate' => ['sessionid' => 'call_123', 'candidate' => ''],
                'callcancel' => ['sessionid' => 'call_123'],
                'talklock' => ['sessionid' => 'call_123', 'doornum' => 1],
                'monitorcall' => ['sessionid' => 'call_123'],
                'setviselection' => ['count' => 0, 'record' => []],
            ],
        ];

        return response()->json([
            'result' => true,
            'categories' => $catalog,
        ]);
    }
}
