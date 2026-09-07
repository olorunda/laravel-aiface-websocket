<?php

namespace AiFace\WebSocket\Events;

use AiFace\WebSocket\Core\Protocol;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired specifically when a user clocks in (check-in) on any device.
 */
class UserClockedIn
{
    use Dispatchable, SerializesModels;

    public string $sn;
    public int|string $enrollId;
    public string $name;
    public string $time;
    public int $mode;
    public string $modeDesc;
    public int $inout;
    public ?string $aliasId;
    public ?string $image;
    public array $record;

    public function __construct(string $sn, array $record)
    {
        $this->sn = $sn;
        $this->record = $record;
        $this->enrollId = $record['enrollid'] ?? 0;
        $this->name = (string) ($record['name'] ?? '');
        $this->time = (string) ($record['time'] ?? date('Y-m-d H:i:s'));
        $this->mode = (int) ($record['mode'] ?? 3);
        $this->inout = (int) ($record['inout'] ?? 0);
        $this->aliasId = isset($record['aliasid']) ? (string) $record['aliasid'] : null;
        $this->image = isset($record['image']) ? (string) $record['image'] : null;
        $this->modeDesc = Protocol::getLogModeDesc($this->mode);
    }
}
