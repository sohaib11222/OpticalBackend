<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
class PaymentEntry extends Model
{
    use BelongsToShop;
    protected $guarded = ['id', 'shop_id'];
    protected $hidden = ['creation_key', 'creation_hash', 'request_hash', 'dedupe_key'];
    protected function casts(): array { return ['amount_minor'=>'integer','paid_on'=>'date:Y-m-d','created_at'=>'datetime']; }
    public $timestamps = false;
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new \LogicException('Ledger entries must be retained.'));
    }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
