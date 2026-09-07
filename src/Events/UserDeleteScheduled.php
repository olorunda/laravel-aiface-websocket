<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a user deletion has been scheduled with a delay.
 */
class UserDeleteScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public int|string $enrollId,
        public ?int $backupNum,
        public int $delaySeconds,
        public string $executeAt,
        public string $taskId
    ) {}
}
