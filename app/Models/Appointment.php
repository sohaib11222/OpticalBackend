<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['creation_key', 'creation_hash'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'version' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function events()
    {
        return $this->hasMany(AppointmentEvent::class);
    }
}
