<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToShop;

    public const FIELDS = ['first_name', 'last_name', 'date_of_birth', 'phone', 'email', 'street_address', 'city', 'postal_code', 'sex'];

    protected $fillable = self::FIELDS;

    protected $hidden = ['creation_key', 'creation_hash', 'phone_normalized'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'version' => 'integer'];
    }

    public function events()
    {
        return $this->hasMany(CustomerEvent::class);
    }
}
