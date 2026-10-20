<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['name_key'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
