<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $connectionId,
        public string $ip,
        public int $port
    ) {}
}
