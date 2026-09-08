<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\ConnectionRegistry;
use AiFace\WebSocket\Core\WebSocketServer;
use AiFace\WebSocket\Events\AttendanceLogReceived;
use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use AiFace\WebSocket\Services\AiFaceDeviceClient;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use AiFace\WebSocket\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class AttendanceEventTest extends TestCase
{
    private WebSocketServer $server;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('aiface.storage.enabled', false);
        $app['config']->set('aiface.webhooks.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'server' => ['port' => 48877],
            'reports' => ['sendlog' => ['auto_mark' => true]],
            'storage' => ['enabled' => false],
            'webhooks' => ['enabled' => false],
        ];

        $registry = new ConnectionRegistry();
        $storage = new StorageService($config);
        $webhooks = new WebhookForwarder($config);
        $this->server = new WebSocketServer($config, $registry, $storage, $webhooks);
    }

    public function testProcessAttendanceRecordsFiresClockInAndClockOut(): void
    {
        Event::fake([UserClockedIn::class, UserClockedOut::class, AttendanceLogReceived::class]);

        $payload = [
            'count' => 2,
            'record' => [
                [
                    'enrollid' => 101,
                    'name'     => 'Alice Johnson',
                    'time'     => '2026-09-08 08:30:00',
                    'mode'     => 3, // Face
                    'inout'    => 0, // Clock In
                ],
                [
                    'enrollid' => 102,
                    'name'     => 'Bob Smith',
                    'time'     => '2026-09-08 17:00:00',
                    'mode'     => 1, // Fingerprint
                    'inout'    => 1, // Clock Out
                ]
            ]
        ];

        $processed = $this->server->processAttendanceRecords('LF00000001', $payload, false);

        $this->assertCount(2, $processed);

        Event::assertDispatched(UserClockedIn::class, function (UserClockedIn $e) {
            return $e->sn === 'LF00000001'
                && $e->enrollId == 101
                && $e->name === 'Alice Johnson'
                && $e->inout === 0;
        });

        Event::assertDispatched(UserClockedOut::class, function (UserClockedOut $e) {
            return $e->sn === 'LF00000001'
                && $e->enrollId == 102
                && $e->name === 'Bob Smith'
                && $e->inout === 1;
        });

        Event::assertDispatched(AttendanceLogReceived::class, function (AttendanceLogReceived $e) {
            return $e->sn === 'LF00000001' && $e->count === 2;
        });
    }

    public function testProcessAttendanceRecordsHandlesSingleAssociativeRecord(): void
    {
        Event::fake([UserClockedIn::class]);

        // Single record where 'record' is an associative array instead of list of arrays
        $payload = [
            'count' => 1,
            'record' => [
                'enrollid' => 105,
                'name'     => 'Charlie Brown',
                'punch_time' => '2026-09-08 09:15:00',
                'inout'    => 0,
            ]
        ];

        $processed = $this->server->processAttendanceRecords('LF00000001', $payload, false);

        $this->assertCount(1, $processed);

        Event::assertDispatched(UserClockedIn::class, function (UserClockedIn $e) {
            return $e->enrollId == 105 && $e->name === 'Charlie Brown' && $e->time === '2026-09-08 09:15:00';
        });
    }

    public function testProcessAttendanceRecordsHandlesDirectionByActionAndEvent(): void
    {
        Event::fake([UserClockedOut::class]);

        $payload = [
            'record' => [
                [
                    'user_id' => 200,
                    'name'    => 'David',
                    'action'  => 'clock_out',
                ]
            ]
        ];

        $processed = $this->server->processAttendanceRecords('LF00000001', $payload, false);

        $this->assertCount(1, $processed);

        Event::assertDispatched(UserClockedOut::class, function (UserClockedOut $e) {
            return $e->enrollId == 200 && $e->inout === 1;
        });
    }

    public function testAiFaceDeviceClientDispatchAttendanceEvents(): void
    {
        Event::fake([UserClockedIn::class, UserClockedOut::class]);

        $client = new AiFaceDeviceClient('LF00000002', [
            'server' => ['port' => 49991, 'ipc_port' => 49992],
        ]);

        $records = [
            ['enrollid' => 301, 'name' => 'Eve', 'inout' => 0],
            ['enrollid' => 302, 'name' => 'Frank', 'inout' => 1],
        ];

        $dispatched = $client->dispatchAttendanceEvents($records);

        $this->assertCount(2, $dispatched);

        Event::assertDispatched(UserClockedIn::class, function (UserClockedIn $e) {
            return $e->sn === 'LF00000002' && $e->enrollId == 301 && $e->name === 'Eve';
        });

        Event::assertDispatched(UserClockedOut::class, function (UserClockedOut $e) {
            return $e->sn === 'LF00000002' && $e->enrollId == 302 && $e->name === 'Frank';
        });
    }
}
