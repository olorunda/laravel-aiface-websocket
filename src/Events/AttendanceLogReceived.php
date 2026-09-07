<?php

namespace AiFace\WebSocket\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceLogReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sn,
        public array $records,
        public int $count
    ) {}

    /**
     * Get all clock-in records (inout == 0).
     */
    public function getClockIns(): array
    {
        return array_values(array_filter($this->records, function ($r) {
            return (int) ($r['inout'] ?? 0) === 0;
        }));
    }

    /**
     * Get all clock-out records (inout == 1).
     */
    public function getClockOuts(): array
    {
        return array_values(array_filter($this->records, function ($r) {
            return (int) ($r['inout'] ?? 0) === 1;
        }));
    }

    public function hasClockIns(): bool
    {
        return !empty($this->getClockIns());
    }

    public function hasClockOuts(): bool
    {
        return !empty($this->getClockOuts());
    }
}
