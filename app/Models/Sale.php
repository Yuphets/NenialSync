<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
