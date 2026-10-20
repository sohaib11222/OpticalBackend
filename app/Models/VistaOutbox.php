<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class VistaOutbox extends Model
{
    use BelongsToShop;

    protected $table = 'vista_outbox';

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['request_hash', 'payload', 'image_map'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'image_map' => 'array', 'allocated_quantity' => 'integer', 'attempts' => 'integer', 'next_attempt_at' => 'datetime'];
    }
}
