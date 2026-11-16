<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommunicationConsent;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\MessageTemplate;
use App\Models\Reminder;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Services\Communications\Messages;
use App\Services\Communications\OperationsHealth;
use App\Services\Communications\Provider;
use App\Services\Operations\Access;
use App\Services\Operations\ReminderPlanner;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommunicationController extends Controller
{
    private function bulkData(Request $r): array
    {
        Access::require('communications.send');
        Access::require('customers.read');
        return $r->validate([
            'customer_ids' => 'required|array|min:1|max:1000', 'customer_ids.*' => 'required|integer|min:1|distinct',
            'channel' => 'required|in:email', 'purpose' => 'required|in:service,marketing',
            'subject' => 'required_if:channel,email|nullable|string|max:200', 'body' => 'required|string|max:4000',
            'scheduled_at' => 'nullable|date|after_or_equal:now', 'delay_minutes' => 'nullable|integer|min:0|max:525600',
            'creation_key' => 'required|uuid',
        ]);
    }

    public function bulkPreview(Request $r, \App\Services\Communications\BulkMessages $bulk)
    {
        return ['data' => $bulk->preview($this->bulkData($r))];
    }

    public function bulkSend(Request $r, \App\Services\Communications\BulkMessages $bulk)
    {
        $batch = $bulk->send($this->bulkData($r), $r->user()->id);
        $messages = CustomerMessage::whereIn('id', $batch->message_ids)->get();
        // A small group receives the same immediate experience as an
        // individual email. Larger groups are already safely queued by the
        // durable message service so the web request never times out.
        if ($messages->count() <= 25) {
            $messages = $messages->map(fn (CustomerMessage $message) => app(Messages::class)->deliverImmediately($message));
        }
        return response()->json(['data' => $batch, 'messages' => $messages], 201);
    }

    public function readiness(Provider $provider)
    {
        Access::require('communications.read');

        return ['data' => ['email' => $provider->ready('email'), 'email_issues' => $provider->emailIssues(), 'immediate_email' => (bool) config('communications.email_sync'), 'variables' => Messages::VARIABLES, 'processing' => OperationsHealth::status()]];
    }

    /** Resolve a tenant-scoped selection before opening the bulk composer. */
    public function recipients(Request $r)
    {
        Access::require('communications.send');
        Access::require('customers.read');
        $d = $r->validate([
            'mode' => ['required', Rule::in(['ids', 'filtered', 'active_filtered', 'range'])],
            'ids' => 'required_if:mode,ids|array|min:1|max:1000',
            'ids.*' => 'integer|min:1|distinct',
            'filters' => 'nullable|array',
            'filters.search' => 'nullable|string|max:150',
            'filters.status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'filters.city' => 'nullable|string|max:100',
            'filters.sort' => ['nullable', Rule::in(['last_name', 'first_name', 'created_at'])],
            'filters.direction' => 'nullable|in:asc,desc',
            'start_row' => 'required_if:mode,range|integer|min:1|max:1000000',
            'end_row' => 'required_if:mode,range|integer|min:1|max:1000000|gte:start_row',
        ]);

        if ($d['mode'] === 'ids') {
            $ids = array_values(array_unique(array_map('intval', $d['ids'])));
            abort_unless(Customer::whereIn('id', $ids)->count() === count($ids), 404, 'One or more selected customers are unavailable.');

            return ['data' => ['ids' => $ids, 'count' => count($ids)]];
        }

        $q = $this->customerQuery($d['filters'] ?? []);
        if ($d['mode'] === 'active_filtered') {
            $q->where('status', 'active');
        }
        $q->orderBy(($d['filters']['sort'] ?? 'last_name'), ($d['filters']['direction'] ?? 'asc'))->orderBy('id');
        if ($d['mode'] === 'range') {
            $rangeLength = $d['end_row'] - $d['start_row'] + 1;
            abort_if($rangeLength > 1000, 422, 'A row range can contain at most 1,000 customers.');
            $ids = $q->skip($d['start_row'] - 1)->take($rangeLength)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $ids = $q->limit(1001)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        abort_if(count($ids) > 1000, 422, 'Choose a narrower filter or a row range of at most 1,000 customers.');

        return ['data' => ['ids' => $ids, 'count' => count($ids)]];
    }

    private function customerQuery(array $filters)
    {
        $q = Customer::query();
        if (($filters['status'] ?? 'active') !== 'all') {
            $q->where('status', $filters['status'] ?? 'active');
        }
        if (! empty($filters['search'])) {
            $v = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $fullName = DB::connection()->getDriverName() === 'sqlite' ? "first_name || ' ' || last_name" : "CONCAT(first_name, ' ', last_name)";
            $q->where(function ($nested) use ($v, $fullName) {
                foreach (['first_name', 'last_name', 'email', 'phone', 'postal_code'] as $field) {
                    $nested->orWhereRaw("$field LIKE ? ESCAPE '!'", [$v]);
                }
                $nested->orWhereRaw("$fullName LIKE ? ESCAPE '!'", [$v]);
            });
        }
        if (! empty($filters['city'])) {
            $q->where('city', $filters['city']);
        }

        return $q;
    }

    public function templates()
    {
        Access::require('communications.read');

        return ['data' => MessageTemplate::orderBy('name')->get()];
    }

    public function saveTemplate(Request $r, ?int $id = null)
    {
        Access::require('communications.manage');
        $d = $r->validate(['name' => 'required|string|max:120', 'channel' => 'required|in:email', 'purpose' => 'required|in:service,marketing', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:4000', 'active' => 'required|boolean', 'version' => $id ? 'required|integer|min:1' : 'prohibited']);
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', ($d['subject'] ?? '').$d['body'], $matches);
        abort_if(array_diff($matches[1], Messages::VARIABLES), 422, 'Unknown template variable.');

        return DB::transaction(function () use ($r, $id, $d) {
            ShopLock::acquire();
            $row = $id ? MessageTemplate::lockForUpdate()->findOrFail($id) : new MessageTemplate;
            if ($id) {
                abort_unless($row->version === $d['version'], 409, 'This template changed. Refresh before saving.');
                abort_unless($row->channel === $d['channel'] && $row->purpose === $d['purpose'], 422, 'Create another template to change channel or consent purpose.');
            }
            $row->fill($d);
            $row->version = $id ? $d['version'] + 1 : 1;
            $row->save();
            Audit::record('message_template.saved', $row->shop_id, $r->user()->id, 'message_template', $row->id);

            return ['data' => $row->refresh()];
        });
    }

    public function consents(int $customer)
    {
        Access::require('communications.read');
        Customer::findOrFail($customer);

        return ['data' => CommunicationConsent::where('customer_id', $customer)->get()->makeHidden('recipient_hash'), 'history' => DB::table('consent_events')->where('shop_id', app(TenantContext::class)->id())->where('customer_id', $customer)->orderByDesc('id')->limit(100)->get(['id', 'channel', 'purpose', 'granted', 'evidence', 'actor_id', 'created_at'])];
    }

    public function consent(Request $r, int $customer)
    {
        Access::require('communications.manage');
        $d = $r->validate(['channel' => 'required|in:email', 'purpose' => 'required|in:service,marketing', 'granted' => 'required|boolean', 'evidence' => 'required|string|min:3|max:2000']);

        return DB::transaction(function () use ($r, $customer, $d) {
            ShopLock::acquire();
            $c = Customer::findOrFail($customer);
            $recipient = Messages::recipient($c, $d['channel']);
            $row = CommunicationConsent::updateOrCreate(['customer_id' => $customer, 'channel' => $d['channel'], 'purpose' => $d['purpose']], ['granted' => $d['granted'], 'evidence' => $d['evidence'], 'recipient_hash' => Messages::recipientHash($recipient), 'actor_id' => $r->user()->id, 'recorded_at' => now()]);
            DB::table('consent_events')->insert(['shop_id' => $c->shop_id, 'customer_id' => $customer, 'channel' => $d['channel'], 'purpose' => $d['purpose'], 'granted' => (bool) $d['granted'], 'evidence' => $d['evidence'], 'recipient_hash' => Messages::recipientHash($recipient), 'actor_id' => $r->user()->id, 'created_at' => now()]);
            if (! $d['granted']) {
                CustomerMessage::where('customer_id', $customer)->where('channel', $d['channel'])->where('purpose', $d['purpose'])->whereIn('status', Messages::PENDING)->update(['status' => 'cancelled', 'error_code' => 'consent_revoked']);
            }
            Audit::record($d['granted'] ? 'consent.granted' : 'consent.revoked', $c->shop_id, $r->user()->id, 'customer', $customer);

            return ['data' => $row->makeHidden('recipient_hash')];
        });
    }

    public function index(Request $r)
    {
        Access::require('communications.read');
        $d = $r->validate(['customer_id' => 'nullable|integer', 'channel' => 'nullable|in:email', 'purpose' => 'nullable|in:service,marketing', 'status' => 'nullable|string|max:30', 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from', 'page' => 'nullable|integer|min:1']);

        return CustomerMessage::with('customer:id,first_name,last_name')->when($d['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->when($d['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))->when($d['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($d['date_from'] ?? null, fn ($q, $v) => $q->whereDate('scheduled_at', '>=', $v))->when($d['date_to'] ?? null, fn ($q, $v) => $q->whereDate('scheduled_at', '<=', $v))->orderByDesc('id')->paginate(25);
    }

    public function show(int $id)
    {
        Access::require('communications.read');

        return ['data' => CustomerMessage::with(['events', 'customer:id,first_name,last_name'])->findOrFail($id)];
    }

    public function send(Request $r, Messages $messages)
    {
        Access::require('communications.send');
        Access::require('customers.read');
        $d = $r->validate(['customer_id' => 'required|integer', 'template_id' => 'nullable|integer', 'reminder_id' => 'nullable|integer', 'channel' => 'required_without:template_id|nullable|in:email', 'purpose' => 'required_without:template_id|nullable|in:service,marketing', 'subject' => 'nullable|string|max:200', 'body' => 'required_without:template_id|nullable|string|max:4000', 'scheduled_at' => 'nullable|date|after_or_equal:now', 'delay_minutes' => 'nullable|integer|min:0|max:525600', 'creation_key' => 'required|uuid']);
        $reminder = empty($d['reminder_id']) ? null : Reminder::where('customer_id', $d['customer_id'])->findOrFail($d['reminder_id']);
        if ($reminder) {
            abort_unless(Access::canReadReminder($reminder), 403);
            abort_unless(app(ReminderPlanner::class)->valid($reminder), 409, 'This reminder is no longer open or eligible.');
        }

        $message = $messages->queue($d, $r->user()->id, $reminder);
        return response()->json(['data' => $messages->deliverImmediately($message)], 201);
    }

    public function action(Request $r, Messages $messages, int $id, string $action)
    {
        Access::require('communications.send');
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']);

        $message = DB::transaction(function () use ($r, $d, $id, $action, $messages) {
            ShopLock::acquire();
            $m = CustomerMessage::lockForUpdate()->findOrFail($id);
            abort_unless($m->version === $d['version'], 409, 'Message changed. Refresh before continuing.');
            if ($action === 'cancel') {
                abort_unless(in_array($m->status, Messages::PENDING), 409, 'Only messages not yet submitted can be cancelled.');
                $m->status = 'cancelled';
            } elseif ($action === 'resolve') {
                Access::require('communications.manage');
                abort_unless($m->status === 'uncertain', 409, 'Only uncertain submissions require a provider review.');
                // Resolution records the review without resending or claiming delivery.
                $m->status = 'reviewed';
            } else {
                // Failed/undelivered provider IDs may represent partial delivery; never recycle them.
                abort_unless(in_array($m->status, ['blocked', 'retry_wait']) || ($m->status === 'failed' && ! $m->provider_id), 409, 'This message cannot be safely retried. Reconcile with the provider and create a reviewed new message if needed.');
                abort_if($messages->eligible($m), 422, 'Recipient opt-outs, source or permissions no longer allow sending.');
                $m->status = 'scheduled';
                $m->next_attempt_at = null;
                $m->attempts = 0;
            }
            $m->version++;
            $m->save();
            $messages->event($m, $m->status, 'manual_'.$action, $d['reason'], $r->user()->id);
            Audit::record('message.'.$action, $m->shop_id, $r->user()->id, 'message', $id);
            return $m->fresh();
        });

        // The transaction must commit before the synchronous sender takes a
        // lease. This makes a manual retry just as immediate as a new
        // "Send now" request, without allowing duplicate submissions.
        if ($action === 'retry') {
            $message = $messages->deliverImmediately($message);
        }

        return ['data' => $message];
    }
}
