<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class SupportTicketNotification extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array { return ['read_at' => 'datetime']; }

    public function ticket() { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
