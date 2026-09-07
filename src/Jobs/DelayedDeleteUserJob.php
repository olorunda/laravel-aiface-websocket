<?php

namespace AiFace\WebSocket\Jobs;

use AiFace\WebSocket\Facades\AiFace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queueable Job for executing delayed user deletion across Laravel workers.
 */
class DelayedDeleteUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $sn,
        public int|string $enrollId,
        public ?int $backupNum = null
    ) {}

    public function handle(): array
    {
        Log::info(sprintf('Executing delayed user deletion on device [%s] for user [%s]', $this->sn, $this->enrollId));
        return AiFace::device($this->sn)->deleteUser($this->enrollId, $this->backupNum);
    }
}
