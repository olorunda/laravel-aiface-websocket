<?php

/**
 * TimyTeco AiFace Mock Device Simulator (Pure PHP WebSocket Client)
 * 
 * Simulates physical biometric hardware connecting to the Laravel AiFace WebSocket daemon.
 * Powered by a local persistent SQLite database ("tynyteko database") to maintain
 * realistic device configuration, enrolled users, biometric credentials, and punch logs.
 *
 * Usage:
 *   php examples/mock_device.php [host] [port] [path] [serial_number] [database_file]
 * 
 * Example:
 *   php examples/mock_device.php 127.0.0.1 7788 /pub/chat LF00000001
 */

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/TimyTecoDatabase.php';

use AiFace\WebSocket\Core\WebSocketFrame;
use AiFace\WebSocket\Mock\TimyTecoDatabase;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 7788);
$path = $argv[3] ?? '/pub/chat';
$sn = $argv[4] ?? 'LF00000001';
$dbFile = $argv[5] ?? (__DIR__ . "/tynyteko_{$sn}.sqlite");

echo "========================================================\n";
echo "  TimyTeco AiFace Mock Hardware Simulator               \n";
echo "========================================================\n";
echo "[Mock Device {$sn}] Database: {$dbFile}\n";

// 1. Initialize SQLite Database
$db = new TimyTecoDatabase($dbFile, $sn);
$cap = $db->getDeviceCap();
$userIds = $db->getUserIds();

echo "[Mock Device {$sn}] Enrolled Users: " . count($userIds) . " (IDs: " . implode(',', array_slice($userIds, 0, 10)) . ")\n";
echo "[Mock Device {$sn}] Capacity Status: Users: {$cap['useduser']}/{$cap['usersize']}, Faces: {$cap['usedface']}/{$cap['facesize']}, Logs: {$cap['usedlog']}/{$cap['logsize']}\n";
echo "[Mock Device {$sn}] Connecting to ws://{$host}:{$port}{$path}...\n";

// 2. Open TCP connection to server
$sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
if (!$sock) {
    die("[Mock Device {$sn}] Connection failed: [{$errno}] {$errstr}\n");
}

// 3. Perform client-side WebSocket handshake
$key = base64_encode(random_bytes(16));
$req = "GET {$path} HTTP/1.1\r\n" .
       "Host: {$host}:{$port}\r\n" .
       "Upgrade: websocket\r\n" .
       "Connection: Upgrade\r\n" .
       "Sec-WebSocket-Key: {$key}\r\n" .
       "Sec-WebSocket-Version: 13\r\n\r\n";

fwrite($sock, $req);
$resp = fread($sock, 2048);

if (!str_contains($resp, '101 Switching Protocols')) {
    die("[Mock Device {$sn}] WebSocket handshake rejected:\n{$resp}\n");
}

echo "[Mock Device {$sn}] Handshake established (101 Switching Protocols)!\n";
stream_set_blocking($sock, false);

function sendClientFrame($socket, array $data): void
{
    $json = json_encode($data);
    // Client to server frames MUST be masked per RFC 6455
    $frame = WebSocketFrame::encode($json, WebSocketFrame::OPCODE_TEXT, true);
    fwrite($socket, $frame);
    echo ">> [SENT] {$json}\n";
}

// 4. Immediately send registration packet (cmd: "reg") from SQLite database
$devInfo = $db->getDeviceInfo();
$cap = $db->getDeviceCap();

sendClientFrame($sock, [
    'cmd' => 'reg',
    'sn' => $sn,
    'devinfo' => array_merge($devInfo, [
        'usersize' => $cap['usersize'],
        'facesize' => $cap['facesize'],
        'fpsize'   => $cap['fpsize'],
        'logsize'  => $cap['logsize'],
        'useduser' => $cap['useduser'],
        'usedface' => $cap['usedface'],
        'usedfp'   => $cap['usedfp'],
        'usedlog'  => $cap['usedlog'],
        'time'     => date('Y-m-d H:i:s'),
    ]),
]);

// 5. Main client loop: receive server commands, query/update SQLite, and simulate punches
$buffer = '';
$lastPunch = time();

while (true) {
    $read = [$sock];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 50000); // 50ms

    if ($ready > 0) {
        $chunk = fread($sock, 8192);
        if ($chunk === '' || $chunk === false) {
            echo "[Mock Device {$sn}] Server disconnected.\n";
            break;
        }
        $buffer .= $chunk;

        while (($frame = WebSocketFrame::decode($buffer)) !== null) {
            if ($frame['opcode'] === WebSocketFrame::OPCODE_PING) {
                fwrite($sock, WebSocketFrame::encodePong($frame['payload']));
                echo "<< [PING RECEIVED] Sent PONG\n";
                continue;
            }

            if ($frame['opcode'] === WebSocketFrame::OPCODE_TEXT) {
                $msg = json_decode($frame['payload'], true);
                if (!is_array($msg)) {
                    continue;
                }

                echo "<< [RECV] " . $frame['payload'] . "\n";

                // Server registration response
                if (isset($msg['ret']) && $msg['ret'] === 'reg') {
                    $cloudTime = $msg['cloudtime'] ?? date('Y-m-d H:i:s');
                    $db->updateCloudTime($cloudTime);
                    echo "[Mock Device {$sn}] Registration acknowledged! Synced cloudtime: {$cloudTime}\n";
                }

                // Server punch log acknowledgement
                if (isset($msg['ret']) && $msg['ret'] === 'sendlog') {
                    if (!empty($msg['mark'])) {
                        $markedIdx = isset($msg['logindex']) ? [(int)$msg['logindex']] : [];
                        $markedCount = $db->markLogs($markedIdx);
                        echo "[Mock Device {$sn}] Server marked {$markedCount} punch log(s) as confirmed in SQLite.\n";
                    }
                }

                // Handle server commands
                if (isset($msg['cmd'])) {
                    $cmd = $msg['cmd'];
                    echo "[Mock Device {$sn}] Executing command: [{$cmd}]\n";

                    $reply = ['ret' => $cmd, 'sn' => $sn, 'result' => true];

                    switch ($cmd) {
                        case 'gettime':
                            $reply['time'] = date('Y-m-d H:i:s');
                            break;

                        case 'settime':
                            $newTime = $msg['time'] ?? date('Y-m-d H:i:s');
                            $db->setDeviceTime($newTime);
                            $reply['time'] = $newTime;
                            break;

                        case 'getdevinfo':
                            $reply['devinfo'] = $db->getDeviceInfo();
                            break;

                        case 'getdevcap':
                            $reply = array_merge($reply, $db->getDeviceCap());
                            break;

                        case 'setuserinfo':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $name = (string) ($msg['name'] ?? "User {$enrollId}");
                            $backupNum = (int) ($msg['backupnum'] ?? 0);
                            $record = $msg['record'] ?? '';
                            $admin = (int) ($msg['admin'] ?? 0);
                            $enable = (int) ($msg['enable'] ?? 1);
                            $card = isset($msg['card']) ? (string)$msg['card'] : null;
                            $pwd = isset($msg['pwd']) ? (string)$msg['pwd'] : null;
                            $aliasid = isset($msg['aliasid']) ? (string)$msg['aliasid'] : null;

                            $db->setUserInfo(
                                enrollId: $enrollId,
                                name: $name,
                                backupNum: $backupNum,
                                record: $record,
                                admin: $admin,
                                enable: $enable,
                                card: $card,
                                pwd: $pwd,
                                aliasid: $aliasid
                            );

                            $reply['enrollid'] = $enrollId;
                            echo "[Mock Device {$sn}] Upserted user in SQLite: enrollid={$enrollId}, name={$name}, backupnum={$backupNum}\n";
                            break;

                        case 'deleteuser':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $backupNum = isset($msg['backupnum']) && $msg['backupnum'] !== '' ? (int)$msg['backupnum'] : null;
                            $db->deleteUser($enrollId, $backupNum);
                            $reply['enrollid'] = $enrollId;
                            echo "[Mock Device {$sn}] Deleted user from SQLite: enrollid={$enrollId}" . ($backupNum !== null ? " (backupnum={$backupNum})" : "") . "\n";
                            break;

                        case 'cleanuser':
                            $db->cleanUser();
                            echo "[Mock Device {$sn}] Cleared all users and credentials from SQLite.\n";
                            break;

                        case 'setusername':
                            $users = $msg['record'] ?? [];
                            $updatedCount = $db->setUserName($users);
                            $reply['count'] = $updatedCount;
                            echo "[Mock Device {$sn}] Batch updated {$updatedCount} username(s) in SQLite.\n";
                            break;

                        case 'getusername':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $name = $db->getUserName($enrollId);
                            $reply['result'] = ($name !== null);
                            $reply['enrollid'] = $enrollId;
                            $reply['name'] = $name ?? '';
                            break;

                        case 'getuserlist':
                            $page = max(1, (int) ($msg['page'] ?? 1));
                            $pageSize = max(1, (int) ($msg['pagesize'] ?? 50));
                            $ids = $db->getUserList($page, $pageSize);
                            $reply['page'] = $page;
                            $reply['pagesize'] = $pageSize;
                            $reply['count'] = count($ids);
                            $reply['record'] = $ids;
                            break;

                        case 'getuserids':
                            $ids = $db->getUserIds();
                            $reply['count'] = count($ids);
                            $reply['record'] = $ids;
                            break;

                        case 'getunuserdid':
                            $unusedId = $db->getUnusedUserId();
                            $reply['enrollid'] = $unusedId;
                            break;

                        case 'checkuserid':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $exists = $db->checkUserId($enrollId);
                            $reply['result'] = $exists;
                            $reply['enrollid'] = $enrollId;
                            break;

                        case 'getuserinfo':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $backupNum = (int) ($msg['backupnum'] ?? 0);
                            $userInfo = $db->getUserInfo($enrollId, $backupNum);
                            if ($userInfo) {
                                $reply = array_merge($reply, $userInfo);
                            } else {
                                $reply['result'] = false;
                                $reply['enrollid'] = $enrollId;
                            }
                            break;

                        case 'getallusers':
                            $page = max(1, (int) ($msg['page'] ?? 1));
                            $pageSize = max(1, (int) ($msg['pagesize'] ?? 10));
                            $backupNum = isset($msg['backupnum']) && $msg['backupnum'] !== '' ? (int)$msg['backupnum'] : null;
                            $records = $db->getAllUsers($page, $pageSize, $backupNum);
                            $reply['page'] = $page;
                            $reply['count'] = count($records);
                            $reply['record'] = $records;
                            break;

                        case 'enableuser':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $enable = (bool) ($msg['enable'] ?? true);
                            $db->enableUser($enrollId, $enable);
                            $reply['enrollid'] = $enrollId;
                            $reply['enable'] = $enable ? 1 : 0;
                            break;

                        case 'getuserprofile':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $profile = $db->getUserProfile($enrollId);
                            $reply['result'] = ($profile !== null);
                            $reply['enrollid'] = $enrollId;
                            $reply['profile'] = $profile ?? '';
                            break;

                        case 'setuserprofile':
                            $enrollId = (int) ($msg['enrollid'] ?? 0);
                            $profile = is_array($msg['profile'] ?? '') ? json_encode($msg['profile']) : (string)($msg['profile'] ?? '');
                            $db->setUserProfile($enrollId, $profile);
                            $reply['enrollid'] = $enrollId;
                            break;

                        case 'getnewlog':
                            $newLogs = $db->getNewLogs();
                            $reply['count'] = count($newLogs);
                            $reply['record'] = $newLogs;
                            break;

                        case 'getalllog':
                            $page = max(1, (int) ($msg['page'] ?? 1));
                            $pageSize = max(1, (int) ($msg['pagesize'] ?? 100));
                            $allLogs = $db->getAllLogs($page, $pageSize, $msg['starttime'] ?? null, $msg['endtime'] ?? null);
                            $reply['count'] = count($allLogs);
                            $reply['record'] = $allLogs;
                            break;

                        case 'cleanlog':
                            $db->cleanLog();
                            echo "[Mock Device {$sn}] Cleared all attendance logs in SQLite.\n";
                            break;

                        case 'initsys':
                            $db->resetFactory();
                            echo "[Mock Device {$sn}] Factory reset completed in SQLite.\n";
                            break;

                        default:
                            // Generic ACK for hardware control commands (reboot, opendoor, lockdevice, setrelay, etc.)
                            break;
                    }

                    sendClientFrame($sock, $reply);
                }
            }
        }
    }

    // Every 15 seconds, simulate an attendance punch (sendlog) for a random enrolled user from SQLite
    if (time() - $lastPunch >= 15) {
        $lastPunch = time();

        $randomUser = $db->getRandomEnrolledUser();
        if ($randomUser) {
            $enrollId = (int) $randomUser['enrollid'];
            $name = $randomUser['name'];
            $aliasId = $randomUser['aliasid'] ?? "EMP{$enrollId}";

            echo "[Mock Device {$sn}] Biometric scan recognized for user #{$enrollId} ({$name})\n";

            // Insert into SQLite attendance_logs
            $logEntry = $db->insertLog(
                enrollId: $enrollId,
                name: $name,
                mode: 3, // Face
                inout: rand(0, 1), // Check-in or check-out
                time: date('Y-m-d H:i:s'),
                aliasid: $aliasId,
                note: 'Biometric Face Verification'
            );

            // Transmit to WebSocket server
            sendClientFrame($sock, [
                'cmd' => 'sendlog',
                'sn' => $sn,
                'count' => 1,
                'logindex' => $logEntry['logindex'],
                'record' => [
                    [
                        'enrollid' => $enrollId,
                        'name'     => $name,
                        'time'     => $logEntry['time'],
                        'mode'     => $logEntry['mode'],
                        'inout'    => $logEntry['inout'],
                        'event'    => 0,
                        'aliasid'  => $aliasId,
                        'note'     => $logEntry['note'],
                    ]
                ],
            ]);
        }
    }

    usleep(10000); // 10ms
}

fclose($sock);
echo "[Mock Device {$sn}] Terminated.\n";
