<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class CustomerEvent extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
