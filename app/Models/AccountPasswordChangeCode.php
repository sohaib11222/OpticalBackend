<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountPasswordChangeCode extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'session_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
