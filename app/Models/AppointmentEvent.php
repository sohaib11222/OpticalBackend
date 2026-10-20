<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class AppointmentEvent extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return ['previous' => 'array', 'current' => 'array', 'overlap_confirmed' => 'boolean', 'created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
