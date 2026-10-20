<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class OpticalRecord extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['creation_key', 'creation_hash'];

    protected function casts(): array
    {
        return ['data' => 'array', 'recorded_on' => 'date:Y-m-d', 'finalized_at' => 'datetime', 'version' => 'integer', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function ($r) {
            if ($r->getOriginal('status') === 'finalized') {
                throw new LogicException('Finalized records are immutable; create an amendment.');
            }if ($r->isDirty(['customer_id', 'kind', 'amends_id', 'revision', 'creation_key'])) {
                throw new LogicException('Record identity cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Optical records must be retained.'));
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function amendment()
    {
        return $this->hasOne(self::class, 'amends_id');
    }

    public function prescription()
    {
        return $this->belongsTo(self::class, 'prescription_id');
    }
}
