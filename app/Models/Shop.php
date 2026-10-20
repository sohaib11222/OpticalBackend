<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    public const BUSINESS_FIELDS = ['phone', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'timezone'];

    public const PROFILE_FIELDS = ['id', 'name', 'status', 'phone', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'timezone', 'currency', 'settings_version'];

    protected $fillable = ['name', ...self::BUSINESS_FIELDS];

    protected function casts(): array
    {
        return ['settings_version' => 'integer', 'notification_preferences' => 'array'];
    }

    public function memberships()
    {
        return $this->hasMany(ShopMembership::class);
    }
}
