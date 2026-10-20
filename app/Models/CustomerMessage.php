<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
class CustomerMessage extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'creation_hash', 'request_hash', 'dedupe_key'];
    protected function casts(): array { return ['scheduled_at'=>'datetime','next_attempt_at'=>'datetime','submitted_at'=>'datetime','delivered_at'=>'datetime','lease_until'=>'datetime','content_variables'=>'array','version'=>'integer']; }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function reminder() { return $this->belongsTo(Reminder::class); }
    public function events() { return $this->hasMany(MessageEvent::class, 'message_id'); }
}
