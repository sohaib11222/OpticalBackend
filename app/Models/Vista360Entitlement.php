<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Vista360Entitlement extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'features' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'checked_at' => 'datetime',
            'last_remote_at' => 'datetime',
            'entitlement_version' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(VistaConnection::class, 'vista_connection_id');
    }
}
