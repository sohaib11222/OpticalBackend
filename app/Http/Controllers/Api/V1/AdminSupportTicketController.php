<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrivateFile;
use App\Services\SupportTickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminSupportTicketController extends Controller
{
    private function permit(Request $request): void
    {
        abort_unless($request->user()->platformCan('shops.support.manage'), 403);
    }

    public function index(Request $request)
    {
        $this->permit($request);
        $data = $request->validate(['status' => 'nullable|in:open,in_progress,waiting_for_response,resolved,closed', 'priority' => 'nullable|in:low,normal,high,urgent', 'category' => 'nullable|in:general,account,technical,billing,integration,data', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'search' => 'nullable|string|max:180', 'page' => 'nullable|integer|min:1']);
        $query = DB::table('support_tickets as ticket')
            ->join('shops as shop', 'shop.id', '=', 'ticket.shop_id')
            ->join('users as creator', 'creator.id', '=', 'ticket.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ticket.assigned_to')
            ->select('ticket.*', 'shop.name as shop_name', 'creator.name as creator_name', 'creator.email as creator_email', 'assignee.name as assignee_name')
            ->when($data['status'] ?? null, fn ($q, $value) => $q->where('ticket.status', $value))
            ->when($data['priority'] ?? null, fn ($q, $value) => $q->where('ticket.priority', $value))
            ->when($data['category'] ?? null, fn ($q, $value) => $q->where('ticket.category', $value))
            ->when($data['from'] ?? null, fn ($q, $value) => $q->whereDate('ticket.created_at', '>=', $value))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->whereDate('ticket.created_at', '<=', $value));
        if (! empty($data['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("ticket.subject LIKE ? ESCAPE '!'", [$term])->orWhereRaw("shop.name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("creator.name LIKE ? ESCAPE '!'", [$term]));
        }
        return $query->orderByDesc('ticket.updated_at')->paginate(30);
    }

    public function show(Request $request, int $id)
    {
        $this->permit($request);
        $ticket = DB::table('support_tickets as ticket')->join('shops as shop', 'shop.id', '=', 'ticket.shop_id')->join('users as creator', 'creator.id', '=', 'ticket.created_by')->leftJoin('users as assignee', 'assignee.id', '=', 'ticket.assigned_to')->where('ticket.id', $id)->select('ticket.*', 'shop.name as shop_name', 'creator.name as creator_name', 'creator.email as creator_email', 'assignee.name as assignee_name')->first();
        abort_unless($ticket, 404);
        $messages = DB::table('support_ticket_messages as message')->join('users as sender', 'sender.id', '=', 'message.sender_id')->where('message.ticket_id', $id)->orderBy('message.id')->get(['message.*', 'sender.name as sender_name', 'sender.account_type as sender_account_type']);
        $attachments = DB::table('support_ticket_attachments as attachment')->join('private_files as file', 'file.id', '=', 'attachment.private_file_id')->where('attachment.ticket_id', $id)->orderBy('attachment.id')->get(['attachment.id', 'attachment.message_id', 'file.id as file_id', 'file.filename', 'file.title', 'file.mime', 'file.size', 'file.scan_status']);
        return ['data' => $ticket, 'messages' => $messages, 'attachments' => $attachments];
    }

    public function update(Request $request, SupportTickets $tickets, int $id)
    {
        $this->permit($request);
        $data = $request->validate(['version' => 'required|integer|min:1', 'status' => 'sometimes|in:open,in_progress,waiting_for_response,resolved,closed', 'priority' => 'sometimes|in:low,normal,high,urgent', 'assigned_to' => 'present|nullable|integer|min:1']);
        return ['data' => $tickets->updateFromAdmin($id, $data, $request->user()->id)];
    }

    public function reply(Request $request, SupportTickets $tickets, int $id)
    {
        $this->permit($request);
        $data = $request->validate(['body' => 'required|string|min:1|max:8000']);
        return ['data' => $tickets->replyFromAdmin($id, $data['body'], $request->user()->id)];
    }

    public function administrators(Request $request)
    {
        $this->permit($request);
        return ['data' => DB::table('users')->where('account_type', 'admin')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email'])];
    }

    public function download(Request $request, int $id, int $attachment)
    {
        $this->permit($request);
        $link = DB::table('support_ticket_attachments')->where('ticket_id', $id)->where('id', $attachment)->first();
        abort_unless($link, 404);
        $file = PrivateFile::withoutGlobalScope('shop')->findOrFail($link->private_file_id);
        abort_unless($file->scan_status === 'clean' && ! $file->archived_at && Storage::disk('private')->exists($file->storage_key), 409, 'This attachment is not available.');
        return Storage::disk('private')->download($file->storage_key, basename($file->filename), ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
