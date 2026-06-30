<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array', 'last_synced_at' => 'datetime'];
    }
}
