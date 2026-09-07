<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommandResponseReceived
{
    use Dispatchable, SerializesModels;

    public string $command;

    public function __construct(
        public string $sn,
        public string $cmd,
        public array $response
    ) {
        $this->command = $cmd;
    }
}
