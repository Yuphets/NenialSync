<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetTicket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
