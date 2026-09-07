<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GpsReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public int $satellites,
        public string $location,
        public ?int $timestamp
    ) {}
}
