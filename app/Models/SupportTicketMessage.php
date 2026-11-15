<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    public function ticket() { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function attachments() { return $this->hasMany(SupportTicketAttachment::class, 'message_id'); }
}
