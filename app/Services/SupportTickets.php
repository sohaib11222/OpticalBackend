<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\SupportTicketUpdated;
use App\Services\Commerce\ShopLock;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Domain rules shared by shop and platform ticket controllers. */
class SupportTickets
{
    public const CATEGORIES = ['general', 'account', 'technical', 'billing', 'integration', 'data'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'waiting_for_response', 'resolved', 'closed'];

    public function create(array $data, int $actorId): SupportTicket
    {
        return DB::transaction(function () use ($data, $actorId) {
            $shop = ShopLock::acquire();
            $ticket = SupportTicket::create([
                'created_by' => $actorId,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'subject' => $data['subject'],
                'description' => $data['description'],
                'status' => 'open',
                'last_message_at' => now(),
            ]);
            SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $actorId,
                'body' => $data['description'],
            ]);
            $this->notifyAdmins($shop->id, $ticket->id, 'ticket_created');
            Audit::record('support_ticket.created', $shop->id, $actorId, 'support_ticket', $ticket->id);

            return $ticket->refresh();
        }, 3);
    }

    public function replyFromShop(SupportTicket $ticket, string $body, int $actorId): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $body, $actorId) {
            ShopLock::acquire();
            $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket->id);
            abort_if($ticket->status === 'closed', 409, 'Reopen this ticket before replying.');
            SupportTicketMessage::create(['ticket_id' => $ticket->id, 'sender_id' => $actorId, 'body' => $body]);
            $ticket->forceFill([
                'status' => in_array($ticket->status, ['resolved', 'waiting_for_response'], true) ? 'in_progress' : $ticket->status,
                'last_message_at' => now(),
                'version' => $ticket->version + 1,
            ])->save();
            $this->notifyAdmins($ticket->shop_id, $ticket->id, 'shop_reply', $ticket->assigned_to);
            Audit::record('support_ticket.shop_reply', $ticket->shop_id, $actorId, 'support_ticket', $ticket->id);

            return $ticket->refresh();
        }, 3);
    }

    public function updateFromShop(SupportTicket $ticket, array $data, int $actorId): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $data, $actorId) {
            ShopLock::acquire();
            $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket->id);
            abort_unless($ticket->version === $data['version'], 409, 'This ticket changed. Refresh before updating it.');
            $next = $data['status'];
            abort_unless(in_array($next, ['open', 'closed'], true), 422, 'Shop users can only close or reopen a ticket.');
            $ticket->forceFill([
                'status' => $next,
                'closed_at' => $next === 'closed' ? now() : null,
                'resolved_at' => $next === 'open' ? null : $ticket->resolved_at,
                'version' => $ticket->version + 1,
            ])->save();
            $this->notifyAdmins($ticket->shop_id, $ticket->id, $next === 'closed' ? 'ticket_closed' : 'ticket_reopened', $ticket->assigned_to);
            Audit::record('support_ticket.'.$next, $ticket->shop_id, $actorId, 'support_ticket', $ticket->id);

            return $ticket->refresh();
        }, 3);
    }

    public function replyFromAdmin(int $ticketId, string $body, int $actorId): array
    {
        return DB::transaction(function () use ($ticketId, $body, $actorId) {
            $ticket = DB::table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first();
            abort_unless($ticket, 404);
            abort_if($ticket->status === 'closed', 409, 'Reopen this ticket before replying.');
            DB::table('support_ticket_messages')->insert([
                'shop_id' => $ticket->shop_id,
                'ticket_id' => $ticketId,
                'sender_id' => $actorId,
                'body' => $body,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('id', $ticketId)->update([
                'status' => 'waiting_for_response',
                'last_message_at' => now(),
                'last_reply_at' => now(),
                'version' => $ticket->version + 1,
                'updated_at' => now(),
            ]);
            $this->notifyUsers($ticket->shop_id, $ticketId, [$ticket->created_by], 'admin_reply');
            $this->notifyCreatorByEmail($ticket, 'admin_reply', $body);
            Audit::record('support_ticket.admin_reply', $ticket->shop_id, $actorId, 'support_ticket', $ticketId);

            return (array) DB::table('support_tickets')->where('id', $ticketId)->first();
        }, 3);
    }

    public function updateFromAdmin(int $ticketId, array $data, int $actorId): array
    {
        return DB::transaction(function () use ($ticketId, $data, $actorId) {
            $ticket = DB::table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first();
            abort_unless($ticket, 404);
            abort_unless((int) $ticket->version === (int) $data['version'], 409, 'This ticket changed. Refresh before updating it.');
            if (array_key_exists('assigned_to', $data) && $data['assigned_to']) {
                abort_unless(User::whereKey($data['assigned_to'])->where('account_type', 'admin')->where('status', 'active')->exists(), 422, 'Assign the ticket to an active platform administrator.');
            }
            $status = $data['status'] ?? $ticket->status;
            $changes = [
                'priority' => $data['priority'] ?? $ticket->priority,
                'status' => $status,
                'assigned_to' => array_key_exists('assigned_to', $data) ? $data['assigned_to'] : $ticket->assigned_to,
                'resolved_at' => $status === 'resolved' ? now() : ($status === 'open' || $status === 'in_progress' ? null : $ticket->resolved_at),
                'closed_at' => $status === 'closed' ? now() : ($status !== 'closed' ? null : $ticket->closed_at),
                'version' => $ticket->version + 1,
                'updated_at' => now(),
            ];
            DB::table('support_tickets')->where('id', $ticketId)->update($changes);
            $recipients = array_filter([$ticket->created_by, $changes['assigned_to']]);
            $this->notifyUsers($ticket->shop_id, $ticketId, $recipients, 'ticket_updated');
            if ($status !== $ticket->status) {
                $this->notifyCreatorByEmail($ticket, 'ticket_status_changed');
            }
            Audit::record('support_ticket.admin_updated', $ticket->shop_id, $actorId, 'support_ticket', $ticketId);

            return (array) DB::table('support_tickets')->where('id', $ticketId)->first();
        }, 3);
    }

    private function notifyAdmins(int $shopId, int $ticketId, string $kind, ?int $assigned = null): void
    {
        $ids = $assigned ? [$assigned] : User::where('account_type', 'admin')->where('status', 'active')->pluck('id')->all();
        $this->notifyUsers($shopId, $ticketId, $ids, $kind);
    }

    private function notifyUsers(int $shopId, int $ticketId, array $userIds, string $kind): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $ids) return;
        DB::table('support_ticket_notifications')->insert(array_map(fn (int $id) => [
            'shop_id' => $shopId,
            'ticket_id' => $ticketId,
            'user_id' => $id,
            'kind' => $kind,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids));
    }

    /** @param object $ticket A locked support_tickets record from the current transaction. */
    private function notifyCreatorByEmail(object $ticket, string $kind, ?string $reply = null): void
    {
        $creator = User::whereKey($ticket->created_by)
            ->where('account_type', 'shop')
            ->where('status', 'active')
            ->first();

        if (! $creator || ! filter_var($creator->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $creator->notify(new SupportTicketUpdated(
            (int) $ticket->id,
            (string) $ticket->public_id,
            (string) $ticket->subject,
            $kind,
            $reply,
        ));
    }
}
