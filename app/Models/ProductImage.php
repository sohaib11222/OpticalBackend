<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['storage_key', 'display_key', 'sha256', 'upload_key'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
