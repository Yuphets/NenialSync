<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['local_payload' => 'array', 'remote_response' => 'array', 'resolved_at' => 'datetime'];
    }
}
