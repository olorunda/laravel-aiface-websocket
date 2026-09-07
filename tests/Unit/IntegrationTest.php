<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\ConnectionRegistry;
use AiFace\WebSocket\Core\WebSocketFrame;
use AiFace\WebSocket\Core\WebSocketServer;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    protected ?WebSocketServer $server = null;
    protected int $port = 7799;

    public function test_full_websocket_handshake_registration_and_command_exchange(): void
    {
        $config = [
            'server' => [
                'host' => '127.0.0.1',
                'port' => $this->port,
                'path' => '/pub/chat',
                'timeout' => 5,
                'ping_interval' => 10,
            ],
            'registration' => [
                'tryseconds' => 300,
                'nosenduser' => false,
                'nosendlog' => false,
            ],
            'reports' => [
                'sendlog' => ['auto_mark' => true, 'default_access' => 1],
            ],
            'storage' => ['enabled' => false],
            'webhooks' => ['enabled' => false],
        ];

        $registry = new ConnectionRegistry();
        $this->server = new WebSocketServer($config, $registry);
        $this->server->start();

        // 1. Connect test client socket
        $clientSock = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 2.0);
        $this->assertIsResource($clientSock);

        // Run server tick to accept connection
        $this->server->tick();
        $this->assertEquals(1, $registry->count(), 'Server should have 1 active connection');

        // 2. Perform WebSocket handshake
        $secKey = base64_encode(random_bytes(16));
        $handshake = "GET /pub/chat HTTP/1.1\r\n" .
                     "Host: 127.0.0.1:{$this->port}\r\n" .
                     "Upgrade: websocket\r\n" .
                     "Connection: Upgrade\r\n" .
                     "Sec-WebSocket-Key: {$secKey}\r\n" .
                     "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($clientSock, $handshake);

        // Server tick to process handshake
        $this->server->tick();

        // Client read handshake response
        $response = fread($clientSock, 2048);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept', $response);

        // 3. Device sends registration packet ("reg")
        $regPacket = [
            'cmd' => 'reg',
            'sn' => 'TEST_DEVICE_001',
            'devinfo' => [
                'modelname' => 'AiFace Model X',
                'firmware' => '2.0.0',
                'usersize' => 5000,
            ],
        ];
        $maskedFrame = WebSocketFrame::encode(json_encode($regPacket), WebSocketFrame::OPCODE_TEXT, true);
        fwrite($clientSock, $maskedFrame);

        // Server tick to process registration
        $this->server->tick();

        // Check device is bound in registry
        $conn = $registry->getBySn('TEST_DEVICE_001');
        $this->assertNotNull($conn);
        $this->assertTrue($conn->isRegistered());
        $this->assertEquals('TEST_DEVICE_001', $conn->getSn());

        // Client reads server response to "reg"
        $clientBuffer = fread($clientSock, 4096);
        $decoded = WebSocketFrame::decode($clientBuffer);
        $this->assertNotNull($decoded);
        $regResponse = json_decode($decoded['payload'], true);
        $this->assertEquals('reg', $regResponse['ret']);
        $this->assertTrue($regResponse['result']);
        $this->assertArrayHasKey('cloudtime', $regResponse);

        // 4. Server dispatches command "opendoor" to device
        $commandResolved = false;
        $resolvedData = null;

        $conn->registerPendingCommand('opendoor', 2.0, function ($data) use (&$commandResolved, &$resolvedData) {
            $commandResolved = true;
            $resolvedData = $data;
        });

        // Send command to client
        $conn->sendJson(['cmd' => 'opendoor', 'sn' => 'TEST_DEVICE_001', 'doornum' => 1]);

        // Client reads command from server
        $clientBuffer = fread($clientSock, 4096);
        $cmdFrame = WebSocketFrame::decode($clientBuffer);
        $this->assertNotNull($cmdFrame);
        $cmdData = json_decode($cmdFrame['payload'], true);
        $this->assertEquals('opendoor', $cmdData['cmd']);

        // Client sends back response {"ret": "opendoor", "sn": "TEST_DEVICE_001", "result": true}
        $clientReply = WebSocketFrame::encode(json_encode([
            'ret' => 'opendoor',
            'sn' => 'TEST_DEVICE_001',
            'result' => true,
        ]), WebSocketFrame::OPCODE_TEXT, true);
        fwrite($clientSock, $clientReply);

        // Server tick to process reply
        $this->server->tick();

        $this->assertTrue($commandResolved, 'Pending command should be resolved');
        $this->assertTrue($resolvedData['result']);
        $this->assertEquals('opendoor', $resolvedData['ret']);

        // 5. Device sends active attendance log ("sendlog")
        $logPacket = [
            'cmd' => 'sendlog',
            'sn' => 'TEST_DEVICE_001',
            'count' => 1,
            'logindex' => 10,
            'record' => [
                [
                    'enrollid' => 99,
                    'name' => 'Test Employee',
                    'time' => '2026-09-07 12:00:00',
                    'mode' => 3, // Face
                    'inout' => 0,
                    'event' => 0,
                ]
            ],
        ];
        $logFrame = WebSocketFrame::encode(json_encode($logPacket), WebSocketFrame::OPCODE_TEXT, true);
        fwrite($clientSock, $logFrame);

        // Server tick
        $this->server->tick();

        // Client reads server response to sendlog
        $clientBuffer = fread($clientSock, 4096);
        $respFrame = WebSocketFrame::decode($clientBuffer);
        $this->assertNotNull($respFrame);
        $logResp = json_decode($respFrame['payload'], true);

        $this->assertEquals('sendlog', $logResp['ret']);
        $this->assertTrue($logResp['result']);
        $this->assertTrue($logResp['mark']);
        $this->assertEquals(1, $logResp['access']);

        // Clean up
        fclose($clientSock);
        $this->server->stop();
    }
}
