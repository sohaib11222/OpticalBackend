<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['creation_key', 'creation_hash'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'version' => 'integer', 'total_minor' => 'integer', 'price_decimals' => 'integer', 'confirmed_at' => 'datetime', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function ($o) {
            if ($o->getOriginal('status') !== 'draft' && array_diff(array_keys($o->getDirty()), ['status', 'version', 'confirmed_at', 'fulfilled_at', 'cancelled_at', 'reversed_at', 'updated_at'])) {
                throw new \LogicException('Confirmed order details are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Orders must be retained; cancel or reverse them.'));
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function prescription()
    {
        return $this->belongsTo(OpticalRecord::class, 'prescription_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events()
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function sales()
    {
        return $this->hasMany(SaleEntry::class);
    }
}
