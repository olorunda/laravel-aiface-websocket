<?php

namespace AiFace\WebSocket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiFaceUser extends Model
{
    protected $guarded = [];

    public function getTable()
    {
        return config('aiface.storage.table_prefix', 'aiface_') . 'users';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AiFaceDevice::class, 'sn', 'sn');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AiFaceCredential::class, 'enrollid', 'enrollid')
            ->where('sn', $this->sn);
    }
}
