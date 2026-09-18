<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\ConnectionRegistry;
use AiFace\WebSocket\Core\WebSocketServer;
use AiFace\WebSocket\Events\AttendanceLogReceived;
use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use AiFace\WebSocket\Models\AiFaceCommandHistory;
use AiFace\WebSocket\Services\AiFaceDeviceClient;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use AiFace\WebSocket\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class CommandHistoryLoggingTest extends TestCase
{
    private StorageService $storageService;
    private WebSocketServer $server;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('aiface.storage.enabled', true);
        $app['config']->set('aiface.storage.table_prefix', 'aiface_');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'server' => ['port' => 48879],
            'reports' => ['sendlog' => ['auto_mark' => true]],
            'storage' => [
                'enabled' => true,
                'table_prefix' => 'aiface_',
                'store_photos' => false,
            ],
            'webhooks' => ['enabled' => false],
        ];

        $registry = new ConnectionRegistry();
        $this->storageService = new StorageService($config);
        $webhooks = new WebhookForwarder($config);
        $this->server = new WebSocketServer($config, $registry, $this->storageService, $webhooks);
    }

    public function testLogCommandStoresMetadataAndUser(): void
    {
        $sn = 'LF00000001';
        $cmd = 'setuserinfo';
        $request = [
            'cmd' => 'setuserinfo',
            'sn' => $sn,
            'enrollid' => 83923,
            'name' => 'John Doe',
            'backupnum' => 10,
            'record' => 123456,
            'admin' => 0,
        ];
        $response = [
            'ret' => 'setuserinfo',
            'result' => true,
            'enrollid' => 83923,
        ];

        $this->storageService->logCommand($sn, $cmd, $request, $response, 'success');

        // Check command_history table
        $entry = DB::table('aiface_command_history')->where('sn', $sn)->where('cmd', $cmd)->first();
        $this->assertNotNull($entry);
        $this->assertEquals('83923', $entry->enroll_id);
        $this->assertEquals('John Doe', $entry->name);
        $this->assertEquals(10, $entry->backupnum);
        $this->assertEquals('success', $entry->status);
        $this->assertEquals(1, $entry->result);

        // Check that aiface_users table was synchronized
        $user = DB::table('aiface_users')->where('sn', $sn)->where('enrollid', '83923')->first();
        $this->assertNotNull($user);
        $this->assertEquals('John Doe', $user->name);
    }

    public function testLogCommandWithBatchUsers(): void
    {
        $sn = 'LF00000002';
        $cmd = 'setusername';
        $request = [
            'cmd' => 'setusername',
            'sn' => $sn,
            'count' => 2,
            'record' => [
                ['enrollid' => 101, 'name' => 'Alice Walker'],
                ['enrollid' => 102, 'name' => 'Bob Builder'],
            ],
        ];
        $response = [
            'ret' => 'setusername',
            'result' => true,
            'count' => 2,
        ];

        $this->storageService->logCommand($sn, $cmd, $request, $response, 'success');

        // Both users should be present in aiface_users table
        $alice = DB::table('aiface_users')->where('sn', $sn)->where('enrollid', '101')->first();
        $bob = DB::table('aiface_users')->where('sn', $sn)->where('enrollid', '102')->first();

        $this->assertNotNull($alice);
        $this->assertEquals('Alice Walker', $alice->name);

        $this->assertNotNull($bob);
        $this->assertEquals('Bob Builder', $bob->name);
    }

    public function testGetUserNameResolver(): void
    {
        $sn = 'LF00000003';

        // Log a command with user info
        $this->storageService->logCommand($sn, 'setuserinfo', [
            'enrollid' => 5500,
            'name' => 'Sarah Connor',
        ], ['result' => true]);

        // Resolving by same SN
        $name = $this->storageService->getUserName($sn, 5500);
        $this->assertEquals('Sarah Connor', $name);

        // Resolving across another SN (fallback)
        $nameCrossSn = $this->storageService->getUserName('LF00009999', 5500);
        $this->assertEquals('Sarah Connor', $nameCrossSn);
    }

    public function testRealDeviceAttendanceLogAutoResolvesNameOnEvents(): void
    {
        Event::fake([UserClockedIn::class, UserClockedOut::class, AttendanceLogReceived::class]);

        $sn = 'LF00000004';
        $enrollId = 83923;

        // 1. First, register/log user via command (e.g. setUserInfo)
        $this->storageService->logCommand($sn, 'setuserinfo', [
            'enrollid' => $enrollId,
            'name' => 'Michael Scott',
            'backupnum' => 2,
        ], ['result' => true]);

        // 2. Real physical device sends punch log with NO name in packet
        $punchDataFromRealHardware = [
            'cmd' => 'sendlog',
            'sn' => $sn,
            'count' => 1,
            'logindex' => 10,
            'record' => [
                [
                    'enrollid' => $enrollId,
                    'time' => '2026-09-18 15:45:50',
                    'mode' => 2, // Card
                    'inout' => 0, // In
                    'event' => 0,
                    // Note: 'name' is intentionally omitted as real hardware does not send it
                ]
            ]
        ];

        // 3. Server processes attendance records
        $normalized = $this->server->processAttendanceRecords($sn, $punchDataFromRealHardware, saveToDb: true);

        // Assert normalized record has name populated
        $this->assertCount(1, $normalized);
        $this->assertEquals('Michael Scott', $normalized[0]['name']);
        $this->assertEquals($enrollId, $normalized[0]['enrollid']);

        // Assert UserClockedIn event received resolved employee name
        Event::assertDispatched(UserClockedIn::class, function (UserClockedIn $e) use ($sn, $enrollId) {
            return $e->sn === $sn
                && $e->enrollId == $enrollId
                && $e->name === 'Michael Scott'
                && $e->inout === 0;
        });

        // Assert database record has name populated
        $logRow = DB::table('aiface_attendance_logs')
            ->where('sn', $sn)
            ->where('enrollid', (string) $enrollId)
            ->first();

        $this->assertNotNull($logRow);
        $this->assertEquals('Michael Scott', $logRow->name);
    }

    public function testClockOutEventWithResolvedName(): void
    {
        Event::fake([UserClockedOut::class]);

        $sn = 'LF00000005';
        $enrollId = 7700;

        $this->storageService->logCommand($sn, 'setuserinfo', [
            'enrollid' => $enrollId,
            'name' => 'Dwight Schrute',
        ], ['result' => true]);

        $punchData = [
            'cmd' => 'sendlog',
            'sn' => $sn,
            'count' => 1,
            'logindex' => 11,
            'record' => [
                [
                    'enrollid' => $enrollId,
                    'time' => '2026-09-18 17:30:00',
                    'mode' => 3, // Face
                    'inout' => 1, // Out
                    'event' => 0,
                ]
            ]
        ];

        $normalized = $this->server->processAttendanceRecords($sn, $punchData, saveToDb: true);

        $this->assertEquals('Dwight Schrute', $normalized[0]['name']);

        Event::assertDispatched(UserClockedOut::class, function (UserClockedOut $e) use ($sn, $enrollId) {
            return $e->sn === $sn
                && $e->enrollId == $enrollId
                && $e->name === 'Dwight Schrute'
                && $e->inout === 1;
        });
    }

    public function testAiFaceDeviceClientDispatchAttendanceEventsAutoResolvesName(): void
    {
        Event::fake([UserClockedIn::class]);

        $sn = 'LF00000006';
        $enrollId = 9988;

        $this->storageService->logCommand($sn, 'setuserinfo', [
            'enrollid' => $enrollId,
            'name' => 'Pam Beesly',
        ], ['result' => true]);

        $config = [
            'storage' => [
                'enabled' => true,
                'table_prefix' => 'aiface_',
            ],
            'server' => ['port' => 48879],
        ];

        $client = new AiFaceDeviceClient($sn, $config);

        $records = [
            [
                'enrollid' => $enrollId,
                'time' => '2026-09-18 09:00:00',
                'mode' => 1,
                'inout' => 0,
            ]
        ];

        $dispatched = $client->dispatchAttendanceEvents($records);

        $this->assertCount(1, $dispatched);
        $this->assertEquals('Pam Beesly', $dispatched[0]['name']);

        Event::assertDispatched(UserClockedIn::class, function (UserClockedIn $e) use ($sn, $enrollId) {
            return $e->sn === $sn && $e->enrollId == $enrollId && $e->name === 'Pam Beesly';
        });
    }

    public function testEloquentModelAiFaceCommandHistory(): void
    {
        $sn = 'LF00000007';

        AiFaceCommandHistory::create([
            'sn' => $sn,
            'cmd' => 'opendoor',
            'enroll_id' => '1001',
            'name' => 'Jim Halpert',
            'backupnum' => null,
            'status' => 'success',
            'request_payload' => ['door' => 1],
            'response_payload' => ['result' => true],
            'result' => true,
        ]);

        $model = AiFaceCommandHistory::bySn($sn)->byEnrollId(1001)->successful()->first();

        $this->assertNotNull($model);
        $this->assertEquals('opendoor', $model->cmd);
        $this->assertEquals('Jim Halpert', $model->name);
        $this->assertTrue($model->result);
    }
}
