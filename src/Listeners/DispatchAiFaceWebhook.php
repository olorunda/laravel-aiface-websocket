<?php

namespace AiFace\WebSocket\Listeners;

use AiFace\WebSocket\Events\AttendanceLogReceived;
use AiFace\WebSocket\Events\CommandResponseReceived;
use AiFace\WebSocket\Events\DeviceConnected;
use AiFace\WebSocket\Events\DeviceDisconnected;
use AiFace\WebSocket\Events\DeviceRegistered;
use AiFace\WebSocket\Events\GpsReceived;
use AiFace\WebSocket\Events\IntercomCallReceived;
use AiFace\WebSocket\Events\PinReceived;
use AiFace\WebSocket\Events\QrCodeScanned;
use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use AiFace\WebSocket\Events\UserPushed;
use AiFace\WebSocket\Services\WebhookForwarder;
use Illuminate\Events\Dispatcher;

/**
 * Listens to all AiFace hardware events and automatically forwards them
 * as structured HTTP webhooks with HMAC signatures.
 */
class DispatchAiFaceWebhook
{
    public function __construct(
        protected WebhookForwarder $webhooks
    ) {}

    /**
     * User Clock-In (Check-In) event.
     */
    public function handleUserClockedIn(UserClockedIn $event): void
    {
        $this->webhooks->dispatch('attendance.clockin', [
            'sn'        => $event->sn,
            'enrollid'  => $event->enrollId,
            'name'      => $event->name,
            'time'      => $event->time,
            'action'    => 'clock_in',
            'inout'     => $event->inout,
            'mode'      => $event->mode,
            'mode_desc' => $event->modeDesc,
            'aliasid'   => $event->aliasId,
            'image'     => $event->image,
            'record'    => $event->record,
        ]);
    }

    /**
     * User Clock-Out (Check-Out) event.
     */
    public function handleUserClockedOut(UserClockedOut $event): void
    {
        $this->webhooks->dispatch('attendance.clockout', [
            'sn'        => $event->sn,
            'enrollid'  => $event->enrollId,
            'name'      => $event->name,
            'time'      => $event->time,
            'action'    => 'clock_out',
            'inout'     => $event->inout,
            'mode'      => $event->mode,
            'mode_desc' => $event->modeDesc,
            'aliasid'   => $event->aliasId,
            'image'     => $event->image,
            'record'    => $event->record,
        ]);
    }

    /**
     * Batch Attendance Log event.
     */
    public function handleAttendanceLog(AttendanceLogReceived $event): void
    {
        $this->webhooks->dispatch('attendance.logged', [
            'sn'          => $event->sn,
            'count'       => $event->count,
            'records'     => $event->records,
            'clock_ins'   => $event->getClockIns(),
            'clock_outs'  => $event->getClockOuts(),
            'received_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Device Registered & Handshake completed.
     */
    public function handleDeviceRegistered(DeviceRegistered $event): void
    {
        $this->webhooks->dispatch('device.registered', [
            'sn'            => $event->sn,
            'ip'            => $event->ip,
            'devinfo'       => $event->devinfo,
            'registered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Device Socket Connected.
     */
    public function handleDeviceConnected(DeviceConnected $event): void
    {
        $this->webhooks->dispatch('device.connected', [
            'connection_id' => $event->connectionId,
            'ip'            => $event->ip,
            'port'          => $event->port,
            'connected_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Device Disconnected.
     */
    public function handleDeviceDisconnected(DeviceDisconnected $event): void
    {
        $this->webhooks->dispatch('device.disconnected', [
            'sn'              => $event->sn,
            'connection_id'   => $event->connectionId,
            'reason'          => $event->reason,
            'disconnected_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * User Enrolled or Modified on device.
     */
    public function handleUserPushed(UserPushed $event): void
    {
        $this->webhooks->dispatch('user.pushed', [
            'sn'        => $event->sn,
            'user_data' => $event->userData,
            'pushed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Access PIN Entered.
     */
    public function handlePinReceived(PinReceived $event): void
    {
        $this->webhooks->dispatch('pin.received', [
            'sn'   => $event->sn,
            'pin'  => $event->pin,
            'time' => $event->time,
        ]);
    }

    /**
     * QR Code Scanned.
     */
    public function handleQrCodeScanned(QrCodeScanned $event): void
    {
        $this->webhooks->dispatch('qrcode.scanned', [
            'sn'        => $event->sn,
            'qr_record' => $event->qrRecord,
        ]);
    }

    /**
     * GPS Location Reported.
     */
    public function handleGpsReceived(GpsReceived $event): void
    {
        $this->webhooks->dispatch('gps.received', [
            'sn'         => $event->sn,
            'location'   => $event->location,
            'satellites' => $event->satellites,
            'timestamp'  => $event->timeStamp,
        ]);
    }

    /**
     * Video Intercom Ring / Call.
     */
    public function handleIntercomCallReceived(IntercomCallReceived $event): void
    {
        $this->webhooks->dispatch('intercom.call', [
            'sn'         => $event->sn,
            'session_id' => $event->sessionId,
            'data'       => $event->data,
        ]);
    }

    /**
     * Command Response Received from device.
     */
    public function handleCommandResponseReceived(CommandResponseReceived $event): void
    {
        $this->webhooks->dispatch('command.response', [
            'sn'       => $event->sn,
            'command'  => $event->command,
            'response' => $event->response,
        ]);
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            UserClockedIn::class           => 'handleUserClockedIn',
            UserClockedOut::class          => 'handleUserClockedOut',
            AttendanceLogReceived::class   => 'handleAttendanceLog',
            DeviceRegistered::class        => 'handleDeviceRegistered',
            DeviceConnected::class         => 'handleDeviceConnected',
            DeviceDisconnected::class      => 'handleDeviceDisconnected',
            UserPushed::class              => 'handleUserPushed',
            PinReceived::class             => 'handlePinReceived',
            QrCodeScanned::class           => 'handleQrCodeScanned',
            GpsReceived::class             => 'handleGpsReceived',
            IntercomCallReceived::class    => 'handleIntercomCallReceived',
            CommandResponseReceived::class => 'handleCommandResponseReceived',
        ];
    }
}
