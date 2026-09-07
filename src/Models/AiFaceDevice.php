<?php

namespace AiFace\WebSocket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiFaceDevice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'devinfo' => 'array',
        'last_seen_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('aiface.storage.table_prefix', 'aiface_') . 'devices';
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AiFaceAttendanceLog::class, 'sn', 'sn');
    }

    public function users(): HasMany
    {
        return $this->hasMany(AiFaceUser::class, 'sn', 'sn');
    }

    public function commandHistory(): HasMany
    {
        return $this->hasMany(AiFaceCommandHistory::class, 'sn', 'sn');
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
