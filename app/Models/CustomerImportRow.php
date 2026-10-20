<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class CustomerImportRow extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['creation_key'];

    protected function casts(): array
    {
        return ['raw_values' => 'array', 'parse_errors' => 'array', 'payload' => 'array', 'errors' => 'array', 'matches' => 'array', 'file_duplicate' => 'boolean', 'overwrite_blanks' => 'boolean'];
    }
}
