<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PinReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public string $pin,
        public string $time
    ) {}
}
