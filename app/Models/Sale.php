<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'vat_rate' => 'decimal:4',
            'vatable_sales' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
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
