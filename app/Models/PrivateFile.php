<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class PrivateFile extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected $hidden = ['storage_key', 'upload_key'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime', 'scanned_at' => 'datetime'];
    }
}
