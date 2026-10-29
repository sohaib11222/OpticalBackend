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
            'customer_ids' => 'required|array|min:1|max:100', 'customer_ids.*' => 'required|integer|min:1|distinct',
            'channel' => 'required|in:sms,email', 'purpose' => 'required|in:service,marketing',
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
        return response()->json(['data' => $batch, 'messages' => CustomerMessage::whereIn('id', $batch->message_ids)->get()], 201);
    }

    public function readiness(Provider $provider)
    {
        Access::require('communications.read');

        return ['data' => ['sms' => $provider->ready('sms'), 'whatsapp' => $provider->ready('whatsapp'), 'email' => $provider->ready('email'), 'variables' => Messages::VARIABLES, 'processing' => OperationsHealth::status()]];
    }

    public function templates()
    {
        Access::require('communications.read');

        return ['data' => MessageTemplate::orderBy('name')->get()];
    }

    public function saveTemplate(Request $r, ?int $id = null)
    {
        Access::require('communications.manage');
        $d = $r->validate(['name' => 'required|string|max:120', 'channel' => 'required|in:sms,whatsapp,email', 'purpose' => 'required|in:service,marketing', 'subject' => 'required_if:channel,email|nullable|string|max:200', 'body' => 'required|string|max:4000', 'content_sid' => ['required_if:channel,whatsapp', 'nullable', 'regex:/^HX[0-9a-fA-F]{32}$/D'], 'content_variables' => 'nullable|array|max:20', 'content_variables.*' => [Rule::in(Messages::VARIABLES)], 'active' => 'required|boolean', 'version' => $id ? 'required|integer|min:1' : 'prohibited']);
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', ($d['subject'] ?? '').$d['body'], $matches);
        abort_if(array_diff($matches[1], Messages::VARIABLES), 422, 'Unknown template variable.');
        foreach (array_keys($d['content_variables'] ?? []) as $key) {
            abort_unless(preg_match('/^[1-9][0-9]?$/D', (string) $key), 422, 'Provider variable keys must be numbers from 1 to 99.');
        }

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
        $d = $r->validate(['channel' => 'required|in:sms,whatsapp,email', 'purpose' => 'required|in:service,marketing', 'granted' => 'required|boolean', 'evidence' => 'required|string|min:3|max:2000']);

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
        $d = $r->validate(['customer_id' => 'nullable|integer', 'channel' => 'nullable|in:sms,whatsapp,email', 'status' => 'nullable|string|max:30', 'page' => 'nullable|integer|min:1']);

        return CustomerMessage::with('customer:id,first_name,last_name')->when($d['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->when($d['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->paginate(25);
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
        $d = $r->validate(['customer_id' => 'required|integer', 'template_id' => 'nullable|integer', 'reminder_id' => 'nullable|integer', 'channel' => 'required_without:template_id|nullable|in:email,sms,whatsapp', 'purpose' => 'required_without:template_id|nullable|in:service,marketing', 'subject' => 'nullable|string|max:200', 'body' => 'required_without:template_id|nullable|string|max:4000', 'scheduled_at' => 'nullable|date|after_or_equal:now', 'delay_minutes' => 'nullable|integer|min:0|max:525600', 'creation_key' => 'required|uuid']);
        $reminder = empty($d['reminder_id']) ? null : Reminder::where('customer_id', $d['customer_id'])->findOrFail($d['reminder_id']);
        if ($reminder) {
            abort_unless(Access::canReadReminder($reminder), 403);
            abort_unless(app(ReminderPlanner::class)->valid($reminder), 409, 'This reminder is no longer open or eligible.');
        }

        return response()->json(['data' => $messages->queue($d, $r->user()->id, $reminder)], 201);
    }

    public function action(Request $r, Messages $messages, int $id, string $action)
    {
        Access::require('communications.send');
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']);

        return DB::transaction(function () use ($r, $d, $id, $action, $messages) {
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
            if ($action === 'retry') $messages->dispatchIfDue($m);

            return ['data' => $m];
        });
    }
}
