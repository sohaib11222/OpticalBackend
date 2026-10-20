<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class VistaRecord extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'unavailable' => 'boolean', 'observed_at' => 'datetime'];
    }
}
