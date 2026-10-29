<?php
namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class CommunicationBatch extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'request_hash'];
    protected function casts(): array { return ['message_ids' => 'array']; }
}
