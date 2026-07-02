<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime',
            'payment_expires_at' => 'datetime', 'paid_at' => 'datetime', 'payment_metadata' => 'array',
            'vat_rate' => 'decimal:4', 'vatable_sales' => 'decimal:2', 'vat_amount' => 'decimal:2',
        ];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
