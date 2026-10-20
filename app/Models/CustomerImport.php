<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class CustomerImport extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['source_hash'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'sheets' => 'array', 'mapping' => 'array', 'version' => 'integer', 'processed_rows' => 'integer', 'total_rows' => 'integer', 'date_system' => 'integer'];
    }

    public function rows()
    {
        return $this->hasMany(CustomerImportRow::class);
    }

    public function file()
    {
        return $this->belongsTo(PrivateFile::class, 'file_id');
    }
}
