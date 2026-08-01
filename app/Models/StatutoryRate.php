<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatutoryRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'rules' => 'array',
            'published_at' => 'date',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
