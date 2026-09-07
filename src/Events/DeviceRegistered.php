<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public array $devinfo,
        public string $ip
    ) {}
}
