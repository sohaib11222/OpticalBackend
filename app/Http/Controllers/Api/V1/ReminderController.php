<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\FollowupRule;
use App\Models\MessageTemplate;
use App\Models\OpticalRecord;
use App\Models\Order;
use App\Models\ProductCategory;
use App\Models\Reminder;
use App\Models\Shop;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Services\Operations\Access;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function index(Request $r)
    {
        Access::require('reminders.read');
        $d = $r->validate(['customer_id' => 'nullable|integer', 'kind' => 'nullable|in:checkup,contact_lens,custom,order,followup', 'status' => 'nullable|in:open,completed,cancelled,all', 'bucket' => 'nullable|in:overdue,today,next7,next30', 'page' => 'nullable|integer|min:1', 'id' => 'nullable|integer']);
        $kinds = array_values(array_filter(['checkup', 'contact_lens', 'custom', 'order', 'followup'], fn ($kind) => Access::can(Access::reminderPermission($kind))));
        $q = Reminder::whereIn('kind', $kinds)->with('customer:id,first_name,last_name,email,phone,status');
        Access::scopeReminders($q);
        if (($d['status'] ?? 'open') !== 'all') {
            $q->where('status', $d['status'] ?? 'open');
        }
        foreach (['customer_id', 'kind', 'id'] as $f) {
            if (! empty($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }
        $this->bucket($q, $d['bucket'] ?? null);

        return $q->orderBy('due_on')->orderBy('id')->paginate(30);
    }

    public function bucket($q, ?string $bucket): void
    {
        $today = CarbonImmutable::now(Shop::findOrFail(app(TenantContext::class)->id())->timezone)->startOfDay();
        match ($bucket) {
            'overdue' => $q->where('due_on', '<', $today->toDateString()),
            'today' => $q->where('due_on', $today->toDateString()),
            'next7' => $q->whereBetween('due_on', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()]),
            'next30' => $q->whereBetween('due_on', [$today->addDays(8)->toDateString(), $today->addDays(30)->toDateString()]), default => null,
        };
    }

    public function save(Request $r, ?int $id = null)
    {
        Access::require('reminders.write');
        $d = $r->validate(['customer_id' => $id ? 'prohibited' : 'nullable|integer', 'kind' => $id ? 'prohibited' : 'required|in:checkup,contact_lens,custom,order', 'title' => 'required|string|max:150', 'due_on' => 'required_without:months|nullable|date_format:Y-m-d', 'months' => 'nullable|in:3,6,12', 'optical_record_id' => $id ? 'prohibited' : 'nullable|integer', 'order_id' => $id ? 'prohibited' : 'nullable|integer', 'notes' => 'nullable|string|max:2000', 'template_id' => 'nullable|integer', 'lead_days' => 'required|integer|min:0|max:365', 'creation_key' => $id ? 'prohibited' : 'required|uuid', 'version' => $id ? 'required|integer|min:1' : 'prohibited']);

        return DB::transaction(function () use ($d, $id, $r) {
            $shop = ShopLock::acquire();
            $row = $id ? Reminder::lockForUpdate()->findOrFail($id) : new Reminder;
            $kind = $id ? $row->kind : $d['kind'];
            Access::require(Access::reminderPermission($kind));
            if (in_array($kind, ['checkup', 'contact_lens'])) {
                Access::require('optical.write');
            }
            if ($id) {
                abort_unless(Access::canReadReminder($row), 403);
                abort_unless($row->version === $d['version'] && $row->status === 'open' && ! $row->rule_id, 409, 'Only the current open manual reminder can be edited.');
                $customer = $row->customer;
                $care = $row->opticalRecord;
            } else {
                $customer = empty($d['customer_id']) ? null : Customer::findOrFail($d['customer_id']);
                abort_if(in_array($kind, ['checkup', 'contact_lens']) && ! $customer, 422, 'Choose a customer for optical reminders.');
                $care = empty($d['optical_record_id']) ? null : OpticalRecord::where('customer_id', $customer?->id)->findOrFail($d['optical_record_id']);
                if ($kind === 'checkup') {
                    abort_unless($care && in_array($care->kind, ['optometry', 'examination']), 422, 'Select a finalized optometric examination.');
                }
                if ($kind === 'contact_lens' && $care) {
                    abort_unless($care->kind === 'contact_lens', 422, 'Select a contact-lens care record.');
                }
                $order = empty($d['order_id']) ? null : Order::where('customer_id', $customer?->id)->findOrFail($d['order_id']);
                abort_if($kind === 'order' && ! $order, 422, 'Select the related order.');
                if ($order) {
                    Access::require('orders.read');
                }
                if ($care) {
                    Access::require('optical.read');
                }
                if ($customer) {
                    Access::require('customers.read');
                }
                $row->fill(['customer_id' => $customer?->id, 'kind' => $kind, 'optical_record_id' => $care?->id, 'order_id' => $order?->id, 'source_key' => 'manual:'.$d['creation_key'], 'created_by' => $r->user()->id]);
                $existing = Reminder::where('source_key', $row->source_key)->first();
                if ($existing) {
                    abort(409, 'This reminder request was already saved. Refresh the reminder list.');
                }
            }
            abort_if($customer && $customer->status !== 'active', 409, 'Restore the customer before scheduling.');
            if ($care) {
                abort_unless($care->status === 'finalized' && ! $care->amendment()->where('status', 'finalized')->exists(), 409, 'Select the current finalized care record.');
            }
            $due = $d['due_on'] ?? null;
            if (! empty($d['months'])) {
                abort_unless($kind === 'checkup' && $care, 422, 'Month intervals require an examination.');
                $due = CarbonImmutable::parse($care->recorded_on)->addMonthsNoOverflow((int) $d['months'])->toDateString();
            }
            abort_if($care && $due <= $care->recorded_on->toDateString(), 422, 'The reminder date must be after the examination date.');
            if (! empty($d['template_id'])) {
                Access::require('communications.manage');
                abort_unless($customer, 422, 'Automatic messages require a customer.');
                MessageTemplate::where('active', true)->findOrFail($d['template_id']);
            }
            if ($id) {
                CustomerMessage::where('reminder_id', $id)->whereIn('status', ['scheduled', 'retry_wait', 'blocked'])->update(['status' => 'cancelled', 'error_code' => 'schedule_changed']);
                $row->version++;
            }
            $row->fill(['title' => $d['title'], 'due_on' => $due, 'notes' => $d['notes'] ?? null, 'template_id' => $d['template_id'] ?? null, 'lead_days' => $d['lead_days']]);
            $row->save();
            Audit::record($id ? 'reminder.updated' : 'reminder.created', $shop->id, $r->user()->id, 'reminder', $row->id);

            return ['data' => $row->refresh()];
        }, 3);
    }

    public function status(Request $r, int $id)
    {
        Access::require('reminders.write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:completed,cancelled', 'reason' => 'required|string|min:3|max:2000']);

        return DB::transaction(function () use ($r, $id, $d) {
            ShopLock::acquire();
            $row = Reminder::lockForUpdate()->findOrFail($id);
            abort_unless(Access::canReadReminder($row), 403);
            abort_unless($row->version === $d['version'] && $row->status === 'open', 409, 'This reminder changed. Refresh before continuing.');
            $row->forceFill(['status' => $d['status'], 'completion_note' => $d['reason'], 'completed_at' => now(), 'version' => $row->version + 1])->save();
            CustomerMessage::where('reminder_id', $id)->whereIn('status', ['scheduled', 'blocked', 'retry_wait'])->update(['status' => 'cancelled', 'error_code' => 'reminder_closed']);
            Audit::record('reminder.'.$d['status'], $row->shop_id, $r->user()->id, 'reminder', $id);

            return ['data' => $row];
        });
    }

    public function rules()
    {
        Access::require('followups.read');
        $triggers = array_values(array_filter(['delivery', 'purchase', 'visit', 'examination', 'inactive'], fn ($trigger) => Access::can(match ($trigger) {
            'visit' => 'agenda.read', 'examination' => 'optical.read', default => 'orders.read'
        })));

        return ['data' => FollowupRule::whereIn('trigger', $triggers)->with('template:id,name,channel,purpose')->orderBy('id')->get()];
    }

    public function saveRule(Request $r, ?int $id = null)
    {
        Access::require('followups.write');
        $d = $r->validate(['name' => 'required|string|max:120', 'trigger' => $id ? 'prohibited' : 'required|in:delivery,purchase,visit,examination,inactive', 'offset' => 'required|integer|min:0|max:3650', 'unit' => 'required|in:days,months', 'starts_on' => 'required|date_format:Y-m-d', 'active' => 'required|boolean', 'template_id' => 'nullable|integer', 'lead_days' => 'required|integer|min:0|max:365', 'version' => $id ? 'required|integer|min:1' : 'prohibited', 'creation_key' => $id ? 'prohibited' : 'required|uuid', 'category_id' => 'nullable|integer|min:1']);

        return DB::transaction(function () use ($r, $d, $id) {
            ShopLock::acquire();
            $hash = hash('sha256', json_encode($d));
            if (! $id && ($existing = FollowupRule::where('creation_key', $d['creation_key'])->first())) {
                abort_unless(hash_equals($existing->creation_hash, $hash), 409, 'This rule request key was used for different details.');

                return ['data' => $existing];
            }
            $rule = $id ? FollowupRule::lockForUpdate()->findOrFail($id) : new FollowupRule(['trigger' => $d['trigger'], 'created_by' => $r->user()->id, 'creation_key' => $d['creation_key'], 'creation_hash' => $hash]);
            Access::require(match ($rule->trigger) {
                'visit' => 'agenda.read', 'examination' => 'optical.read', default => 'orders.read'
            });
            if (! empty($d['category_id'])) {
                abort_unless(in_array($rule->trigger, ['delivery', 'purchase']), 422, 'Category filters apply to delivery and purchase rules.');
                ProductCategory::findOrFail($d['category_id']);
            }
            if ($id) {
                abort_unless($rule->version === $d['version'], 409, 'This rule changed. Refresh before saving.');
            }
            abort_if($d['unit'] === 'months' && $d['offset'] > 120, 422, 'Choose at most 120 months.');
            if (! empty($d['template_id'])) {
                Access::require('communications.manage');
                $template = MessageTemplate::where('active', true)->findOrFail($d['template_id']);
                if ($rule->trigger === 'inactive') {
                    abort_unless($template->purpose === 'marketing', 422, 'Inactive-customer campaigns require marketing consent.');
                }
            }
            $rule->fill($d);
            $rule->created_by = $r->user()->id;
            $rule->version = $id ? $d['version'] + 1 : 1;
            $rule->save();
            if ($id) {
                $ids = Reminder::where('rule_id', $id)->pluck('id');
                Reminder::where('rule_id', $id)->where('status', 'open')->increment('version');
                CustomerMessage::whereIn('reminder_id', $ids)->whereIn('status', ['scheduled', 'retry_wait', 'blocked'])->update(['status' => 'cancelled', 'error_code' => 'rule_changed']);
            }
            Audit::record($id ? 'followup.updated' : 'followup.created', $rule->shop_id, $r->user()->id, 'followup_rule', $rule->id);

            return ['data' => $rule->refresh()];
        });
    }
}
