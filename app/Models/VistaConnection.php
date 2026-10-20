<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class VistaConnection extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['access_token', 'provider', 'checkpoint'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'expires_at' => 'datetime', 'synced_at' => 'datetime', 'next_sync_at' => 'datetime', 'checkpoint' => 'array'];
    }
}
