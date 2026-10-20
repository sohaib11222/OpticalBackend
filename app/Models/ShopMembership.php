<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopMembership extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['invitation_hash'];

    protected function casts(): array
    {
        return ['permission_overrides' => 'array', 'version' => 'integer', 'invitation_expires_at' => 'datetime', 'invitation_accepted_at' => 'datetime'];
    }

    public function effectivePermissions(): array
    {
        $base = $this->role->permissions()->pluck('name')->all();
        if ($this->role->name === 'owner') {
            return $base;
        }
        foreach ($this->permission_overrides ?? [] as $name => $allowed) {
            $base = array_values(array_diff($base, [$name]));
            if ($allowed === true) {
                $base[] = $name;
            }
        }

        return array_values(array_unique($base));
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function can(string $permission): bool
    {
        return $this->status === 'active' && in_array($permission, $this->effectivePermissions(), true);
    }
}
