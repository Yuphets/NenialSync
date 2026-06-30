<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }
}
