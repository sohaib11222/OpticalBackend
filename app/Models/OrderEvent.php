<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['request_key', 'request_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'stock_returned' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Order history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Order history must be retained.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
