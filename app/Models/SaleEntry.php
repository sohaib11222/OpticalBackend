<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class SaleEntry extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'stock_returned' => 'boolean', 'price_decimals' => 'integer', 'amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sales entries are immutable; create a reversal.'));
        static::deleting(fn () => throw new \LogicException('Sales entries must be retained.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
