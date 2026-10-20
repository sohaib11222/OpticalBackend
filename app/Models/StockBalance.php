<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $appends = ['available'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer', 'version' => 'integer', 'low_notified' => 'boolean'];
    }

    public function getAvailableAttribute(): int
    {
        return $this->on_hand - $this->reserved;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
