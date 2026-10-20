<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        $check = function ($item) {
            if (Order::findOrFail($item->order_id)->status !== 'draft') {
                throw new \LogicException('Confirmed order items cannot change.');
            }
        };
        static::saving($check);
        static::deleting($check);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
