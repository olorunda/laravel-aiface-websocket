<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a user (or user credential) deletion is effected on a device.
 */
class UserDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public int|string $enrollId,
        public ?int $backupNum = null,
        public array $rawResponse = []
    ) {}
}
