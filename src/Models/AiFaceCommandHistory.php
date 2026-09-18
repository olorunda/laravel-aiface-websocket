<?php

namespace AiFace\WebSocket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFaceCommandHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'result' => 'boolean',
    ];

    public function getTable()
    {
        return config('aiface.storage.table_prefix', 'aiface_') . 'command_history';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AiFaceDevice::class, 'sn', 'sn');
    }

    public function scopeBySn($query, string $sn)
    {
        return $query->where('sn', $sn);
    }

    public function scopeByEnrollId($query, int|string $enrollId)
    {
        return $query->where('enroll_id', (string) $enrollId);
    }

    public function scopeByCmd($query, string $cmd)
    {
        return $query->where('cmd', $cmd);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('result', true);
    }
}
