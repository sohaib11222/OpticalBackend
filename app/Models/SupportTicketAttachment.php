<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class SupportTicketAttachment extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id'];

    public function ticket() { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function message() { return $this->belongsTo(SupportTicketMessage::class, 'message_id'); }
    public function file() { return $this->belongsTo(PrivateFile::class, 'private_file_id'); }
}
