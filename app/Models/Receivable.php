<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
class Receivable extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'creation_hash', 'request_hash', 'dedupe_key'];
    protected function casts(): array { return ['amount_minor'=>'integer','version'=>'integer','due_on'=>'date:Y-m-d','price_decimals'=>'integer']; }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function entries() { return $this->hasMany(PaymentEntry::class); }
}
