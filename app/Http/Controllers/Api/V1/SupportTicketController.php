<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrivateFile;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketNotification;
use App\Services\Files\DocumentUploads;
use App\Services\Operations\Access;
use App\Services\SupportTickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SupportTicketController extends Controller
{
    private function require(string $permission): void { Access::require('support.'.$permission); }

    private function ticket(int $id): SupportTicket
    {
        $ticket = SupportTicket::findOrFail($id);
        abort_unless(Access::can('support.manage') || $ticket->created_by === request()->user()->id, 403, 'You can only access your own support tickets.');
        return $ticket;
    }

    public function index(Request $request)
    {
        $this->require('read');
        $data = $request->validate(['status' => 'nullable|in:open,in_progress,waiting_for_response,resolved,closed', 'priority' => 'nullable|in:low,normal,high,urgent', 'category' => 'nullable|in:general,account,technical,billing,integration,data', 'page' => 'nullable|integer|min:1']);
        $query = SupportTicket::with(['creator:id,name,email', 'assignee:id,name,email'])
            ->when(! Access::can('support.manage'), fn ($q) => $q->where('created_by', $request->user()->id))
            ->when($data['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($data['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->when($data['category'] ?? null, fn ($q, $value) => $q->where('category', $value));

        return $query->orderByDesc('updated_at')->paginate(25);
    }

    public function store(Request $request, SupportTickets $tickets)
    {
        $this->require('create');
        $data = $request->validate([
            'subject' => 'required|string|min:3|max:180',
            'category' => 'required|in:general,account,technical,billing,integration,data',
            'priority' => 'required|in:low,normal,high,urgent',
            'description' => 'required|string|min:10|max:8000',
        ]);

        return response()->json(['data' => $tickets->create($data, $request->user()->id)->load(['creator:id,name,email', 'assignee:id,name,email', 'messages.sender:id,name,account_type', 'attachments.file'])], 201);
    }

    public function show(int $id)
    {
        $this->require('read');
        return ['data' => $this->ticket($id)->load(['creator:id,name,email', 'assignee:id,name,email', 'messages' => fn ($q) => $q->with('sender:id,name,account_type')->orderBy('id'), 'attachments.file'])];
    }

    public function reply(Request $request, SupportTickets $tickets, int $id)
    {
        $this->require('reply');
        $data = $request->validate(['body' => 'required|string|min:1|max:8000']);
        return ['data' => $tickets->replyFromShop($this->ticket($id), $data['body'], $request->user()->id)];
    }

    public function status(Request $request, SupportTickets $tickets, int $id)
    {
        $this->require('reply');
        $data = $request->validate(['status' => 'required|in:open,closed', 'version' => 'required|integer|min:1']);
        return ['data' => $tickets->updateFromShop($this->ticket($id), $data, $request->user()->id)];
    }

    public function attachment(Request $request, DocumentUploads $uploads, int $id)
    {
        $this->require('reply');
        $ticket = $this->ticket($id);
        $data = $request->validate([
            'file' => 'required|file|max:10240',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'upload_key' => 'required|uuid',
            'message_id' => 'nullable|integer|min:1',
        ]);
        if (! empty($data['message_id'])) {
            SupportTicketMessage::where('ticket_id', $ticket->id)->findOrFail($data['message_id']);
        }
        $file = $uploads->upload($request->file('file'), [
            'category' => 'support_ticket',
            'title' => $data['title'] ?? $request->file('file')->getClientOriginalName(),
            'description' => $data['description'] ?? null,
            'upload_key' => $data['upload_key'],
        ], $request->user()->id);
        $uploads->queue($file);
        $attachment = SupportTicketAttachment::firstOrCreate([
            'ticket_id' => $ticket->id,
            'private_file_id' => $file->id,
        ], ['message_id' => $data['message_id'] ?? null]);

        // Keep the persisted attachment visible if its scan fails so the
        // customer can see the exact state and retry rather than being left
        // with a misleading upload that appears to be pending forever.
        return response()->json(['data' => $attachment->fresh()->load('file')], 201);
    }

    public function download(int $id, int $attachment)
    {
        $this->require('read');
        $ticket = $this->ticket($id);
        $link = SupportTicketAttachment::where('ticket_id', $ticket->id)->findOrFail($attachment);
        $file = PrivateFile::findOrFail($link->private_file_id);
        abort_unless($file->scan_status === 'clean' && ! $file->archived_at && Storage::disk('private')->exists($file->storage_key), 409, 'This attachment is not available.');

        return Storage::disk('private')->download($file->storage_key, basename($file->filename), ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function notifications(Request $request)
    {
        $this->require('read');
        $rows = SupportTicketNotification::with('ticket:id,subject,status,public_id')->where('user_id', $request->user()->id)->orderByDesc('id')->limit(50)->get();
        return ['data' => $rows, 'unread' => $rows->whereNull('read_at')->count()];
    }

    public function readNotification(Request $request, int $notification)
    {
        $this->require('read');
        DB::transaction(function () use ($request, $notification) {
            $row = SupportTicketNotification::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($notification);
            $row->read_at ??= now();
            $row->save();
        });
        return response()->noContent();
    }
}
