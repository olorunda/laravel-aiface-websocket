<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Events\AttendanceLogReceived;
use AiFace\WebSocket\Events\DeviceRegistered;
use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use AiFace\WebSocket\Listeners\DispatchAiFaceWebhook;
use AiFace\WebSocket\Services\WebhookForwarder;
use AiFace\WebSocket\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class WebhookListenerTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'webhooks' => [
                'enabled' => true,
                'url' => 'https://api.example.com/aiface/webhook',
                'secret' => 'super_secret_webhook_key_12345',
                'timeout' => 5,
                'events' => [
                    'device.registered',
                    'attendance.logged',
                    'attendance.clockin',
                    'attendance.clockout',
                ],
            ],
        ];
    }

    public function testUserClockedInEventAndListener(): void
    {
        Http::fake([
            'https://api.example.com/aiface/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $forwarder = new WebhookForwarder($this->config);
        $listener = new DispatchAiFaceWebhook($forwarder);

        $punchRecord = [
            'enrollid' => 101,
            'name'     => 'Alice Johnson',
            'time'     => '2026-09-07 08:30:00',
            'mode'     => 3, // Face
            'inout'    => 0, // Clock In
            'event'    => 0,
            'aliasid'  => 'EMP101',
            'image'    => 'data:image/jpeg;base64,/9j/4AAQSkZJRg...',
        ];

        $event = new UserClockedIn('LF00000001', $punchRecord);

        $this->assertEquals('LF00000001', $event->sn);
        $this->assertEquals(101, $event->enrollId);
        $this->assertEquals('Alice Johnson', $event->name);
        $this->assertEquals('Face Recognition', $event->modeDesc);
        $this->assertEquals(0, $event->inout);

        // Process through listener
        $listener->handleUserClockedIn($event);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $headers = $request->headers();

            return $request->url() === 'https://api.example.com/aiface/webhook'
                && ($headers['X-AiFace-Event'][0] ?? null) === 'attendance.clockin'
                && isset($headers['X-AiFace-Signature'][0])
                && str_starts_with($headers['X-AiFace-Signature'][0], 'sha256=')
                && $data['event'] === 'attendance.clockin'
                && $data['data']['action'] === 'clock_in'
                && $data['data']['enrollid'] === 101
                && $data['data']['mode_desc'] === 'Face Recognition';
        });
    }

    public function testUserClockedOutEventAndListener(): void
    {
        Http::fake([
            'https://api.example.com/aiface/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $forwarder = new WebhookForwarder($this->config);
        $listener = new DispatchAiFaceWebhook($forwarder);

        $punchRecord = [
            'enrollid' => 102,
            'name'     => 'Bob Smith',
            'time'     => '2026-09-07 17:05:00',
            'mode'     => 1, // Fingerprint
            'inout'    => 1, // Clock Out
            'aliasid'  => 'EMP102',
        ];

        $event = new UserClockedOut('LF00000001', $punchRecord);

        $this->assertEquals('Fingerprint', $event->modeDesc);
        $this->assertEquals(1, $event->inout);

        $listener->handleUserClockedOut($event);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $headers = $request->headers();

            return ($headers['X-AiFace-Event'][0] ?? null) === 'attendance.clockout'
                && $data['event'] === 'attendance.clockout'
                && $data['data']['action'] === 'clock_out'
                && $data['data']['enrollid'] === 102
                && $data['data']['mode_desc'] === 'Fingerprint';
        });
    }

    public function testAttendanceLogReceivedHelpersAndWebhook(): void
    {
        Http::fake([
            'https://api.example.com/aiface/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $forwarder = new WebhookForwarder($this->config);
        $listener = new DispatchAiFaceWebhook($forwarder);

        $records = [
            ['enrollid' => 101, 'name' => 'Alice', 'inout' => 0, 'time' => '2026-09-07 09:00:00'],
            ['enrollid' => 102, 'name' => 'Bob',   'inout' => 1, 'time' => '2026-09-07 17:00:00'],
        ];

        $event = new AttendanceLogReceived('LF00000001', $records, 2);

        $this->assertTrue($event->hasClockIns());
        $this->assertTrue($event->hasClockOuts());
        $this->assertCount(1, $event->getClockIns());
        $this->assertCount(1, $event->getClockOuts());
        $this->assertEquals(101, $event->getClockIns()[0]['enrollid']);
        $this->assertEquals(102, $event->getClockOuts()[0]['enrollid']);

        $listener->handleAttendanceLog($event);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['event'] === 'attendance.logged'
                && count($data['data']['clock_ins']) === 1
                && count($data['data']['clock_outs']) === 1;
        });
    }

    public function testHmacSha256SignatureVerification(): void
    {
        $secret = 'test_secret_key_xyz';
        $config = $this->config;
        $config['webhooks']['secret'] = $secret;

        Http::fake([
            'https://api.example.com/aiface/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $forwarder = new WebhookForwarder($config);
        $forwarder->dispatch('attendance.clockin', ['test' => 123]);

        Http::assertSent(function ($request) use ($secret) {
            $headers = $request->headers();
            $sigHeader = $headers['X-AiFace-Signature'][0] ?? '';
            $timestamp = $headers['X-AiFace-Timestamp'][0] ?? '';

            $this->assertStringStartsWith('sha256=', $sigHeader);
            $expectedSignature = hash_hmac('sha256', "{$timestamp}." . json_encode($request->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);

            return $sigHeader === 'sha256=' . $expectedSignature;
        });
    }

    public function testFilteredEventsAreNotDispatched(): void
    {
        Http::fake();

        $forwarder = new WebhookForwarder($this->config);
        // 'unknown.event' is not in allowed events
        $forwarder->dispatch('unknown.event', ['key' => 'val']);

        Http::assertNothingSent();
    }
}
