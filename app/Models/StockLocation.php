<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class StockLocation extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];
}
