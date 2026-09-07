<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Events\UserDeleted;
use AiFace\WebSocket\Events\UserDeleteScheduled;
use AiFace\WebSocket\Jobs\DelayedDeleteUserJob;
use AiFace\WebSocket\Listeners\DispatchAiFaceWebhook;
use AiFace\WebSocket\Services\AiFaceDeviceClient;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use AiFace\WebSocket\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class DelayedDeleteTest extends TestCase
{
    private StorageService $storageService;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('aiface.storage.enabled', true);
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

    public function testUserDeleteScheduledEvent(): void
    {
        $event = new UserDeleteScheduled(
            sn: 'LF00000001',
            enrollId: 107,
            delaySeconds: 120,
            executeAt: time() + 120,
            taskId: 'task_del_12345',
            backupNum: null
        );

        $this->assertEquals('LF00000001', $event->sn);
        $this->assertEquals(107, $event->enrollId);
        $this->assertEquals(120, $event->delaySeconds);
        $this->assertEquals('task_del_12345', $event->taskId);
        $this->assertNull($event->backupNum);
        $this->assertGreaterThan(time(), $event->executeAt);
    }

    public function testUserDeletedEvent(): void
    {
        $event = new UserDeleted(
            sn: 'LF00000001',
            enrollId: 107,
            backupNum: 10,
            rawResponse: ['result' => true, 'cmd' => 'deleteuser']
        );

        $this->assertEquals('LF00000001', $event->sn);
        $this->assertEquals(107, $event->enrollId);
        $this->assertEquals(10, $event->backupNum);
        $this->assertTrue($event->rawResponse['result']);
    }

    public function testWebhookDispatchForScheduledAndExecutedDeletion(): void
    {
        Http::fake([
            'https://api.example.com/aiface/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $config = [
            'webhooks' => [
                'enabled' => true,
                'url' => 'https://api.example.com/aiface/webhook',
                'secret' => 'test_secret',
                'timeout' => 5,
                'events' => [
                    'user.delete_scheduled',
                    'user.deleted',
                ],
            ],
        ];

        $forwarder = new WebhookForwarder($config);
        $listener = new DispatchAiFaceWebhook($forwarder);

        // 1. Scheduled event
        $scheduledEvent = new UserDeleteScheduled(
            sn: 'LF00000001',
            enrollId: 107,
            delaySeconds: 60,
            executeAt: time() + 60,
            taskId: 'task_del_abc',
            backupNum: 12
        );
        $listener->handleUserDeleteScheduled($scheduledEvent);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['event'] === 'user.delete_scheduled'
                && $data['data']['sn'] === 'LF00000001'
                && $data['data']['enrollid'] == 107
                && $data['data']['delay_seconds'] == 60;
        });

        // 2. Executed event
        $deletedEvent = new UserDeleted(
            sn: 'LF00000001',
            enrollId: 107,
            backupNum: 12,
            rawResponse: ['result' => true]
        );
        $listener->handleUserDeleted($deletedEvent);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['event'] === 'user.deleted'
                && $data['data']['sn'] === 'LF00000001'
                && $data['data']['enrollid'] == 107;
        });
    }

    public function testStorageServiceScheduledCommandsLifecycle(): void
    {
        $taskId = 'task_test_' . uniqid();
        $executeAt = time() + 60;

        // Save scheduled command
        $this->storageService->saveScheduledCommand([
            'task_id'       => $taskId,
            'sn'            => 'LF00000001',
            'cmd'           => 'deleteuser',
            'enrollid'      => '107',
            'backupnum'     => null,
            'delay_seconds' => 60,
            'execute_at'    => $executeAt,
            'status'        => 'pending',
            'payload'       => ['cmd' => 'deleteuser', 'enrollid' => 107],
        ]);

        // Retrieve pending commands
        $pending = $this->storageService->getPendingScheduledCommands('LF00000001');
        $this->assertCount(1, $pending);
        $this->assertArrayHasKey($taskId, $pending);
        $this->assertEquals($taskId, $pending[$taskId]['id']);
        $this->assertEquals('107', $pending[$taskId]['enrollid']);

        // Mark executed
        $this->storageService->markScheduledCommandExecuted($taskId, ['result' => true]);

        // Verify no pending commands left
        $pendingAfter = $this->storageService->getPendingScheduledCommands('LF00000001');
        $this->assertCount(0, $pendingAfter);

        // Save another command and cancel it
        $cancelTaskId = 'task_cancel_' . uniqid();
        $this->storageService->saveScheduledCommand([
            'task_id'       => $cancelTaskId,
            'sn'            => 'LF00000001',
            'cmd'           => 'deleteuser',
            'enrollid'      => '108',
            'backupnum'     => null,
            'delay_seconds' => 300,
            'execute_at'    => time() + 300,
            'status'        => 'pending',
        ]);

        $cancelledCount = $this->storageService->cancelScheduledCommand('LF00000001', '108');
        $this->assertEquals(1, $cancelledCount);

        $pendingAfterCancel = $this->storageService->getPendingScheduledCommands('LF00000001');
        $this->assertCount(0, $pendingAfterCancel);
    }

    public function testDelayedDeleteJobQueueing(): void
    {
        Queue::fake();

        DelayedDeleteUserJob::dispatch('LF00000001', 107, null)->delay(now()->addMinutes(5));

        Queue::assertPushed(DelayedDeleteUserJob::class, function ($job) {
            return $job->sn === 'LF00000001' && $job->enrollId === 107;
        });
    }

    public function testAiFaceDeviceClientDelayedDeleteRouting(): void
    {
        // Use an unused port and non-existent IPC so connectIpc fails deterministically
        $client = new AiFaceDeviceClient('LF00000001', [
            'server' => [
                'port' => 49991,
                'ipc_host' => '127.0.0.1',
                'ipc_port' => 49992,
            ],
            'ipc' => ['socket_path' => '/tmp/non_existent_ipc_' . uniqid() . '.sock', 'timeout' => 1],
        ]);

        // When IPC daemon is not running on that port, returns expected error structure gracefully
        $response = $client->deleteUser(107, delay: 60);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertFalse($response['result']);
        $this->assertStringContainsString('AiFace WebSocket daemon is not running', $response['error']);

        // Cancel pending delete gracefully
        $cancelResp = $client->cancelDelayedDelete(107);
        $this->assertIsArray($cancelResp);
        $this->assertFalse($cancelResp['result']);

        // Get pending delayed deletes gracefully
        $pendingResp = $client->getPendingDelayedDeletes();
        $this->assertIsArray($pendingResp);
        $this->assertFalse($pendingResp['result']);
    }
}
