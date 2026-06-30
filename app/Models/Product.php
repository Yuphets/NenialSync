<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $appends = ['available_quantity', 'is_low_stock'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'discount_percent' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->stock_quantity - $this->reserved_quantity - $this->safety_stock);
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->available_quantity <= $this->reorder_level;
    }
}
