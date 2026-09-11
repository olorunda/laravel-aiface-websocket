<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\ConnectionRegistry;
use AiFace\WebSocket\Core\WebSocketServer;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use AiFace\WebSocket\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class LoggingTest extends TestCase
{
    public function testOfflineQueuedCommandDoesNotSpamWarningEveryTick(): void
    {
        Log::spy();

        $storage = new StorageService(['storage' => ['enabled' => false]]);
        $server = new WebSocketServer(
            ['server' => ['port' => 7799], 'logging' => ['enabled' => true, 'level' => 'debug']],
            new ConnectionRegistry(),
            $storage,
            new WebhookForwarder([])
        );

        // Queue command for offline device
        $server->queueCommand('LF999999', 'reboot', [], 'offline');

        // Simulate 10 iterations of the event loop ticking
        for ($i = 0; $i < 10; $i++) {
            $server->processScheduledTasks();
        }

        // Must never log warning for offline device on every tick
        Log::shouldNotHaveReceived('warning');
    }

    public function testLoggingCanBeDisabledViaConfig(): void
    {
        Log::spy();

        $server = new WebSocketServer(
            ['server' => ['port' => 7799], 'logging' => ['enabled' => false]],
            new ConnectionRegistry(),
            new StorageService(['storage' => ['enabled' => false]]),
            new WebhookForwarder([])
        );

        $server->queueCommand('LF999999', 'reboot', [], 'offline');

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('debug');
    }

    public function testLogLevelThresholdFiltersLowerLevels(): void
    {
        Log::spy();

        $server = new WebSocketServer(
            ['server' => ['port' => 7799], 'logging' => ['enabled' => true, 'level' => 'error']],
            new ConnectionRegistry(),
            new StorageService(['storage' => ['enabled' => false]]),
            new WebhookForwarder([])
        );

        $server->queueCommand('LF999999', 'reboot', [], 'offline');

        // info message from queueCommand should be suppressed because level is set to error
        Log::shouldNotHaveReceived('info');
    }

    public function testChannelConfigurationRoutesLogsToCustomChannel(): void
    {
        $mockChannel = \Mockery::mock();
        $mockChannel->shouldReceive('info')->once();

        Log::shouldReceive('channel')
            ->with('aiface_custom')
            ->andReturn($mockChannel);

        $server = new WebSocketServer(
            ['server' => ['port' => 7799], 'logging' => ['enabled' => true, 'channel' => 'aiface_custom', 'level' => 'info']],
            new ConnectionRegistry(),
            new StorageService(['storage' => ['enabled' => false]]),
            new WebhookForwarder([])
        );

        $server->queueCommand('LF999999', 'reboot', [], 'offline');
    }
}
