<?php

/**
 * TimyTeco AiFace Mock Device Simulator (Pure PHP WebSocket Client)
 * 
 * Simulates physical hardware connecting to the Laravel AiFace WebSocket daemon.
 * Sends registration, responds to server commands, and sends attendance punches.
 */

require_once __DIR__ . '/../tests/bootstrap.php';

use AiFace\WebSocket\Core\WebSocketFrame;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 7788);
$path = $argv[3] ?? '/pub/chat';
$sn = $argv[4] ?? 'LF00000001';

echo "[Mock Device {$sn}] Connecting to ws://{$host}:{$port}{$path}...\n";

$sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
if (!$sock) {
    die("Connection failed: [{$errno}] {$errstr}\n");
}

// 1. Perform client-side WebSocket handshake
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
    die("WebSocket handshake rejected:\n{$resp}\n");
}

echo "[Mock Device {$sn}] Handshake established (101 Switching Protocols)!\n";
stream_set_blocking($sock, false);

function sendClientFrame($socket, array $data) {
    $json = json_encode($data);
    // Client to server frames MUST be masked per RFC 6455
    $frame = WebSocketFrame::encode($json, WebSocketFrame::OPCODE_TEXT, true);
    fwrite($socket, $frame);
    echo ">> [SENT] " . json_encode($data) . "\n";
}

// 2. Immediately send registration packet (cmd: "reg")
sendClientFrame($sock, [
    'cmd' => 'reg',
    'sn' => $sn,
    'devinfo' => [
        'modelname' => 'AiFace Pro 7',
        'manufacturer' => 'TimyTeco',
        'usersize' => 10000,
        'facesize' => 5000,
        'fpsize' => 10000,
        'logsize' => 200000,
        'useduser' => 45,
        'usedface' => 30,
        'usedfp' => 15,
        'usedlog' => 120,
        'firmware' => '1.2.0',
        'time' => date('Y-m-d H:i:s'),
    ],
]);

// 3. Main client loop: receive server commands, reply, and occasionally send punch logs
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
            echo "[Mock Device] Server disconnected.\n";
            break;
        }
        $buffer .= $chunk;

        while (($frame = WebSocketFrame::decode($buffer)) !== null) {
            if ($frame['opcode'] === WebSocketFrame::OPCODE_PING) {
                // Reply with PONG
                fwrite($sock, WebSocketFrame::encodePong($frame['payload']));
                echo "<< [PING RECEIVED] Sent PONG\n";
                continue;
            }

            if ($frame['opcode'] === WebSocketFrame::OPCODE_TEXT) {
                $msg = json_decode($frame['payload'], true);
                echo "<< [RECV] " . $frame['payload'] . "\n";

                // If server sent a registration response
                if (isset($msg['ret']) && $msg['ret'] === 'reg') {
                    echo ">> Device registered successfully! Cloudtime: " . ($msg['cloudtime'] ?? 'N/A') . "\n";
                }

                // If server sent a command
                if (isset($msg['cmd'])) {
                    $cmd = $msg['cmd'];
                    echo ">> Processing server command: [{$cmd}]\n";

                    // Handle various commands
                    $reply = ['ret' => $cmd, 'sn' => $sn, 'result' => true];

                    if ($cmd === 'gettime') {
                        $reply['time'] = date('Y-m-d H:i:s');
                    } elseif ($cmd === 'getdevinfo') {
                        $reply['devinfo'] = ['model' => 'AiFace Pro 7', 'firmware' => '1.2.0'];
                    } elseif ($cmd === 'getdevcap') {
                        $reply['usersize'] = 10000;
                        $reply['useduser'] = 45;
                    } elseif ($cmd === 'getuserlist') {
                        $reply['count'] = 2;
                        $reply['record'] = [101, 102];
                    }

                    sendClientFrame($sock, $reply);
                }
            }
        }
    }

    // Every 15 seconds, simulate an attendance punch (sendlog)
    if (time() - $lastPunch >= 15) {
        $lastPunch = time();
        $punchId = rand(100, 110);
        echo ">> Simulating face scan punch for user {$punchId}...\n";
        sendClientFrame($sock, [
            'cmd' => 'sendlog',
            'sn' => $sn,
            'count' => 1,
            'logindex' => rand(1, 1000),
            'record' => [
                [
                    'enrollid' => $punchId,
                    'name' => 'Demo User ' . $punchId,
                    'time' => date('Y-m-d H:i:s'),
                    'mode' => 3, // Face
                    'inout' => 0, // Check-in
                    'event' => 0,
                    'aliasid' => 'EMP' . $punchId,
                    'note' => 'Simulation',
                ]
            ],
        ]);
    }

    usleep(10000); // 10ms
}

fclose($sock);
