<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
class Reminder extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'creation_hash', 'request_hash', 'dedupe_key'];
    protected function casts(): array { return ['due_on'=>'date:Y-m-d','version'=>'integer','lead_days'=>'integer','completed_at'=>'datetime']; }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function rule() { return $this->belongsTo(FollowupRule::class); }
    public function opticalRecord() { return $this->belongsTo(OpticalRecord::class); }
    public function appointment() { return $this->belongsTo(Appointment::class); }
    public function template() { return $this->belongsTo(MessageTemplate::class); }
}
