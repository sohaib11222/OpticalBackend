<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['creation_key', 'creation_hash', 'variation_key'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'price_decimals' => 'integer', 'version' => 'integer', 'low_stock_threshold' => 'integer', 'variation_attributes' => 'array'];
    }

    public function parentProduct()
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    public function variations()
    {
        return $this->hasMany(self::class, 'parent_product_id');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function balance()
    {
        return $this->hasOne(StockBalance::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function primaryImage()
    {
        return $this->belongsTo(ProductImage::class, 'primary_image_id');
    }
}
