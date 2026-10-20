<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
class FollowupRule extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'creation_hash', 'request_hash', 'dedupe_key'];
    protected function casts(): array { return ['active'=>'boolean','offset'=>'integer','version'=>'integer','lead_days'=>'integer','starts_on'=>'date:Y-m-d']; }
    public function template() { return $this->belongsTo(MessageTemplate::class); }
}
