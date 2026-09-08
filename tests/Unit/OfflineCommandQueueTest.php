<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\ConnectionRegistry;
use AiFace\WebSocket\Core\DeviceConnection;
use AiFace\WebSocket\Core\WebSocketServer;
use AiFace\WebSocket\Events\CommandQueued;
use AiFace\WebSocket\Events\UserDeleted;
use AiFace\WebSocket\Listeners\DispatchAiFaceWebhook;
use AiFace\WebSocket\Services\AiFaceDeviceClient;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use AiFace\WebSocket\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class OfflineCommandQueueTest extends TestCase
{
    private StorageService $storageService;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('aiface.storage.enabled', true);
        $app['config']->set('aiface.server.auto_queue_offline', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageService = new StorageService(['storage' => ['enabled' => true]]);
    }

    public function testCommandQueuedEvent(): void
    {
        $event = new CommandQueued(
            sn: 'LF00000001',
            command: 'reboot',
            payload: ['sn' => 'LF00000001', 'cmd' => 'reboot'],
            taskId: 'task_queue_123',
            reason: 'offline'
        );

        $this->assertEquals('LF00000001', $event->sn);
        $this->assertEquals('reboot', $event->command);
        $this->assertEquals('task_queue_123', $event->taskId);
        $this->assertEquals('offline', $event->reason);
        $this->assertEquals('reboot', $event->payload['cmd']);
    }

    public function testWebhookDispatchForCommandQueued(): void
    {
        Http::fake([
            'https://api.example.com/aiface/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $config = [
            'webhooks' => [
                'enabled' => true,
                'url'     => 'https://api.example.com/aiface/webhook',
                'secret'  => 'secret_key',
                'timeout' => 5,
                'events'  => ['command.queued'],
            ],
        ];

        $forwarder = new WebhookForwarder($config);
        $listener = new DispatchAiFaceWebhook($forwarder);

        $event = new CommandQueued(
            sn: 'LF00000001',
            command: 'setuserinfo',
            payload: ['sn' => 'LF00000001', 'cmd' => 'setuserinfo', 'enrollid' => 99, 'name' => 'Alice'],
            taskId: 'task_queue_abc',
            reason: 'offline'
        );

        $listener->handleCommandQueued($event);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['event'] === 'command.queued'
                && $data['data']['sn'] === 'LF00000001'
                && $data['data']['command'] === 'setuserinfo'
                && $data['data']['task_id'] === 'task_queue_abc'
                && $data['data']['reason'] === 'offline';
        });
    }

    public function testQueueCommandMethodOnWebSocketServer(): void
    {
        Event::fake([CommandQueued::class]);

        $registry = new ConnectionRegistry();
        $server = new WebSocketServer(
            ['server' => ['port' => 7799, 'auto_queue_offline' => true]],
            $registry,
            $this->storageService,
            new WebhookForwarder([])
        );

        $taskId = $server->queueCommand(
            sn: 'LF00000002',
            cmd: 'setuserinfo',
            payload: ['enrollid' => 101, 'name' => 'Bob'],
            reason: 'offline'
        );

        $this->assertNotEmpty($taskId);
        $this->assertStringStartsWith('task_queue_', $taskId);

        // Verify task stored in persistent storage
        $pending = $this->storageService->getPendingScheduledCommands('LF00000002');
        $this->assertArrayHasKey($taskId, $pending);
        $this->assertEquals('setuserinfo', $pending[$taskId]['cmd']);
        $this->assertEquals('101', $pending[$taskId]['enrollid']);
        $this->assertEquals('Bob', $pending[$taskId]['payload']['name'] ?? null);

        // Verify CommandQueued event dispatched
        Event::assertDispatched(CommandQueued::class, function ($e) use ($taskId) {
            return $e->sn === 'LF00000002'
                && $e->command === 'setuserinfo'
                && $e->taskId === $taskId
                && $e->reason === 'offline';
        });
    }

    public function testProcessScheduledTasksFlushesQueuedCommandsWhenDeviceConnects(): void
    {
        Event::fake([UserDeleted::class]);

        $registry = new ConnectionRegistry();
        $server = new WebSocketServer(
            ['server' => ['port' => 7799]],
            $registry,
            $this->storageService,
            new WebhookForwarder([])
        );

        // 1. Queue a general command and a deleteuser command while device is offline
        $rebootTaskId = $server->queueCommand('LF00000003', 'reboot', [], 'offline');
        $deleteTaskId = $server->queueCommand('LF00000003', 'deleteuser', ['enrollid' => 205, 'backupnum' => 13], 'command_timeout');

        $pending = $this->storageService->getPendingScheduledCommands('LF00000003');
        $this->assertCount(2, $pending);

        // 2. Call processScheduledTasks while still offline -> should not execute
        $server->processScheduledTasks();
        $pendingStill = $this->storageService->getPendingScheduledCommands('LF00000003');
        $this->assertCount(2, $pendingStill);

        // 3. Connect device with mock socket stream
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $conn = new DeviceConnection($pair[0], '127.0.0.1', 55555);
        $registry->add($conn);
        $registry->bindSn('LF00000003', $conn);

        // 4. Process tasks with device now online
        $server->processScheduledTasks();

        // 5. Verify tasks were executed and flushed
        $pendingAfter = $this->storageService->getPendingScheduledCommands('LF00000003');
        $this->assertCount(0, $pendingAfter);

        // Read frames sent down the socket pair
        $receivedData = fread($pair[1], 8192);
        $this->assertNotEmpty($receivedData);
        $this->assertStringContainsString('reboot', $receivedData);
        $this->assertStringContainsString('deleteuser', $receivedData);

        // Verify UserDeleted event was fired on execution
        Event::assertDispatched(UserDeleted::class, function ($e) {
            return $e->sn === 'LF00000003' && (string) $e->enrollId === '205' && $e->backupNum === 13;
        });

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testRegistrationTriggersScheduledTaskExecution(): void
    {
        $registry = new ConnectionRegistry();
        $server = new WebSocketServer(
            [
                'server' => ['port' => 7799],
                'registration' => ['tryseconds' => 60],
            ],
            $registry,
            $this->storageService,
            new WebhookForwarder([])
        );

        // Queue command for offline device
        $taskId = $server->queueCommand('LF00000004', 'opendoor', ['door' => 1], 'offline');

        // Device connects and sends "reg" handshake
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $conn = new DeviceConnection($pair[0], '127.0.0.1', 55555);
        $registry->add($conn);

        $server->processMessage($conn, [
            'cmd'       => 'reg',
            'sn'        => 'LF00000004',
            'devinfo'   => ['modelname' => 'AiFace-Pro', 'firmware' => '1.2.3'],
            'cloudtime' => '2026-09-08 12:00:00',
        ]);

        // Verify task was immediately executed upon registration
        $pending = $this->storageService->getPendingScheduledCommands('LF00000004');
        $this->assertCount(0, $pending);

        // Verify open door frame was sent down the socket
        $received = fread($pair[1], 8192);
        $this->assertStringContainsString('opendoor', $received);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testIpcCommandToOfflineDeviceReturnsQueuedResponse(): void
    {
        $registry = new ConnectionRegistry();
        $server = new WebSocketServer(
            ['server' => ['port' => 7799, 'auto_queue_offline' => true]],
            $registry,
            $this->storageService,
            new WebhookForwarder([])
        );

        $ipcServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $addr = stream_socket_get_name($ipcServer, false);
        $client = stream_socket_client('tcp://' . $addr, $errno, $errstr);

        fwrite($client, json_encode([
            'action'  => 'command',
            'sn'      => 'LF00000005',
            'cmd'     => 'reboot',
            'payload' => [],
            'timeout' => 1.0,
        ]));

        $server->handleIpcClient($ipcServer);

        $rawResponse = stream_get_contents($client);
        $response = json_decode($rawResponse, true);

        $this->assertIsArray($response);
        $this->assertTrue($response['result']);
        $this->assertTrue($response['queued']);
        $this->assertEquals('LF00000005', $response['sn']);
        $this->assertEquals('reboot', $response['cmd']);
        $this->assertArrayHasKey('task_id', $response);
        $this->assertStringContainsString('queued', $response['message']);

        fclose($client);
        fclose($ipcServer);
    }

    public function testIpcCommandTimeoutReturnsQueuedResponseInsteadOfError(): void
    {
        $registry = new ConnectionRegistry();
        $server = new WebSocketServer(
            ['server' => ['port' => 7799, 'auto_queue_offline' => true]],
            $registry,
            $this->storageService,
            new WebhookForwarder([])
        );

        // Register device with stream pair that does not reply to command
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $conn = new DeviceConnection($pair[0], '127.0.0.1', 55556);
        $registry->add($conn);
        $registry->bindSn('LF00000006', $conn);

        $ipcServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $addr = stream_socket_get_name($ipcServer, false);
        $client = stream_socket_client('tcp://' . $addr, $errno, $errstr);

        fwrite($client, json_encode([
            'action'  => 'command',
            'sn'      => 'LF00000006',
            'cmd'     => 'reboot',
            'payload' => [],
            'timeout' => 0.05, // 50ms timeout to trigger fast timeout in test
        ]));

        $server->handleIpcClient($ipcServer);

        $rawResponse = stream_get_contents($client);
        $response = json_decode($rawResponse, true);

        // Verify it did NOT return {"result":false,"error":"Command [reboot] timed out waiting for device response"}
        $this->assertIsArray($response);
        $this->assertTrue($response['result']);
        $this->assertTrue($response['queued']);
        $this->assertEquals('LF00000006', $response['sn']);
        $this->assertEquals('reboot', $response['cmd']);
        $this->assertArrayHasKey('task_id', $response);
        $this->assertStringContainsString('timed out', $response['message']);
        $this->assertStringContainsString('queued', $response['message']);

        // Verify device was disconnected from active registry
        $this->assertNull($registry->getBySn('LF00000006'));

        // Verify task exists in pending queue in storage
        $pending = $this->storageService->getPendingScheduledCommands('LF00000006');
        $this->assertCount(1, $pending);
        $this->assertEquals('reboot', reset($pending)['cmd']);

        fclose($client);
        fclose($ipcServer);
        if (is_resource($pair[0])) fclose($pair[0]);
        if (is_resource($pair[1])) fclose($pair[1]);
    }
}
