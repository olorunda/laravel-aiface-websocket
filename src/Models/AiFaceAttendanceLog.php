<?php

namespace AiFace\WebSocket\Models;

use AiFace\WebSocket\Core\Protocol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFaceAttendanceLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw_data' => 'array',
        'punch_time' => 'datetime',
    ];

    public function getTable()
    {
        return config('aiface.storage.table_prefix', 'aiface_') . 'attendance_logs';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AiFaceDevice::class, 'sn', 'sn');
    }

    public function getModeNameAttribute(): string
    {
        return Protocol::getLogModeDescription((int) $this->mode);
    }
}
