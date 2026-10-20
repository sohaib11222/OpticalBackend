<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ShopNotice extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
