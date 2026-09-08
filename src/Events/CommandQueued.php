<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a command is queued because the device is offline or unresponsive.
 */
class CommandQueued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public string $command,
        public array $payload,
        public string $taskId,
        public string $reason
    ) {}
}
