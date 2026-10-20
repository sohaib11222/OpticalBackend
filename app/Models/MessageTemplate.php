<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
class MessageTemplate extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'creation_hash', 'request_hash', 'dedupe_key'];
    protected function casts(): array { return ['content_variables'=>'array','active'=>'boolean','version'=>'integer']; }
}
