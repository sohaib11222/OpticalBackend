<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use BelongsToShop;

    protected $guarded = ['id', 'shop_id', 'public_id'];

    /**
     * A public ticket reference must never depend on a controller passing a
     * guarded attribute through mass assignment.  This also protects tickets
     * created by future jobs or admin tools.
     */
    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $ticket->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_reply_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer'];
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function messages() { return $this->hasMany(SupportTicketMessage::class, 'ticket_id'); }
    public function attachments() { return $this->hasMany(SupportTicketAttachment::class, 'ticket_id'); }
}
