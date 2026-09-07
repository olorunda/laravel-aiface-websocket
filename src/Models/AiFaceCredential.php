<?php

namespace AiFace\WebSocket\Models;

use AiFace\WebSocket\Core\Protocol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFaceCredential extends Model
{
    protected $guarded = [];

    public function getTable()
    {
        return config('aiface.storage.table_prefix', 'aiface_') . 'user_credentials';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AiFaceDevice::class, 'sn', 'sn');
    }

    public function getTypeAttribute(): string
    {
        return Protocol::getCredentialType((int) $this->backupnum);
    }

    public function getDescriptionAttribute(): string
    {
        return Protocol::getBackupNumDescription((int) $this->backupnum);
    }
}
