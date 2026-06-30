<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'finalized_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PayrollItem::class);
    }
}
