<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceDisconnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?string $sn,
        public string $connectionId,
        public string $reason = ''
    ) {}
}
