<?php

namespace App\Services\Operations;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\FollowupRule;
use App\Models\OpticalRecord;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\Shop;
use App\Services\Commerce\ShopLock;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReminderPlanner
{
    public function valid(Reminder $r): bool
    {
        if ($r->status !== 'open' || ($r->customer_id && $r->customer?->status !== 'active')) {
            return false;
        }
        if ($r->rule_id && (! $r->rule?->active)) {
            return false;
        }
        if ($r->rule?->category_id && ! $r->order?->items()->whereIn('product_id', Product::select('id')->where('category_id', $r->rule->category_id))->exists()) {
            return false;
        }
        if ($r->order_id && in_array($r->order?->status, ['cancelled', 'reversed'])) {
            return false;
        }
        if ($r->optical_record_id) {
            $care = $r->opticalRecord;
            if (! $care || $care->status !== 'finalized' || $care->amendment()->where('status', 'finalized')->exists()) {
                return false;
            }
            if ($r->rule?->trigger === 'examination' && OpticalRecord::where('customer_id', $r->customer_id)->whereIn('kind', ['optometry', 'examination'])->where('status', 'finalized')->where(fn ($q) => $q->where('recorded_on', '>', $care->recorded_on)->orWhere(fn ($n) => $n->where('recorded_on', $care->recorded_on)->where('id', '>', $care->id)))->exists()) {
                return false;
            }
        }
        if ($r->appointment_id && $r->appointment?->status !== 'completed') {
            return false;
        }
        if ($r->rule?->trigger === 'inactive' && $r->order_id && Order::where('customer_id', $r->customer_id)->where('status', 'fulfilled')->where(fn ($q) => $q->where('fulfilled_at', '>', $r->order->fulfilled_at)->orWhere(fn ($n) => $n->where('fulfilled_at', $r->order->fulfilled_at)->where('id', '>', $r->order_id)))->exists()) {
            return false;
        }

        return true;
    }

    public function refresh(): void
    {
        DB::transaction(function () {
            $shop = ShopLock::acquire();
            foreach (Reminder::where('status', 'open')->with(['customer', 'order', 'rule', 'opticalRecord', 'appointment'])->lazyById(200) as $r) {
                if (! $this->valid($r)) {
                    $r->forceFill(['status' => 'cancelled', 'completion_note' => 'Source is no longer eligible.', 'version' => $r->version + 1])->save();
                    CustomerMessage::where('reminder_id', $r->id)->whereIn('status', ['scheduled', 'blocked', 'retry_wait'])->update(['status' => 'cancelled', 'error_code' => 'source_changed']);
                }
            }
            foreach (FollowupRule::where('active', true)->get() as $rule) {
                foreach ($this->sources($rule) as $source) {
                    $customer = Customer::find($source['customer_id']);
                    if ($customer?->status !== 'active') {
                        continue;
                    }
                    $anchor = CarbonImmutable::parse($source['anchor'], $shop->timezone);
                    $due = $rule->unit === 'months' ? $anchor->addMonthsNoOverflow($rule->offset) : $anchor->addDays($rule->offset);
                    if ($due->toDateString() < $rule->starts_on->toDateString()) {
                        continue;
                    }
                    $key = 'rule:'.$rule->id.':'.$source['key'];
                    $old = Reminder::where('source_key', $key)->first();
                    if ($old) {
                        if ($old->status === 'open' && ! CustomerMessage::where('reminder_id', $old->id)->whereNotIn('status', ['cancelled'])->exists()) {
                            $old->fill(['due_on' => $due->toDateString(), 'title' => $rule->name, 'template_id' => $rule->template_id, 'lead_days' => $rule->lead_days, 'created_by' => $rule->created_by]);
                            if ($old->isDirty()) {
                                $old->version++;
                                $old->save();
                            }
                        }

                        continue;
                    }
                    Reminder::create(['customer_id' => $customer->id, 'kind' => 'followup', 'title' => $rule->name, 'due_on' => $due->toDateString(), 'rule_id' => $rule->id, 'order_id' => $source['order_id'] ?? null, 'appointment_id' => $source['appointment_id'] ?? null, 'optical_record_id' => $source['optical_record_id'] ?? null, 'source_key' => $key, 'template_id' => $rule->template_id, 'lead_days' => $rule->lead_days, 'created_by' => $rule->created_by]);
                }
            }
        }, 3);
    }

    private function sources(FollowupRule $rule): iterable
    {
        $tz = Shop::findOrFail(app(TenantContext::class)->id())->timezone;
        $cutoff = CarbonImmutable::parse($rule->starts_on, $tz);
        $cutoff = $rule->unit === 'months' ? $cutoff->subMonthsNoOverflow($rule->offset + 1) : $cutoff->subDays($rule->offset + 1);
        if (in_array($rule->trigger, ['delivery', 'purchase', 'inactive'])) {
            $q = Order::where('status', 'fulfilled')->where('fulfilled_at', '>=', $cutoff->utc());
            if ($rule->category_id) {
                $q->whereHas('items', fn ($i) => $i->whereIn('product_id', Product::select('id')->where('category_id', $rule->category_id)));
            }
            if ($rule->trigger === 'inactive') {
                $q->whereNotExists(function ($n) {
                    $n->selectRaw('1')->from('orders as newer')->whereColumn('newer.shop_id', 'orders.shop_id')->whereColumn('newer.customer_id', 'orders.customer_id')->where('newer.status', 'fulfilled')->where(fn ($q) => $q->whereColumn('newer.fulfilled_at', '>', 'orders.fulfilled_at')->orWhere(fn ($n) => $n->whereColumn('newer.fulfilled_at', 'orders.fulfilled_at')->whereColumn('newer.id', '>', 'orders.id')));
                });
            }
            foreach ($q->lazyById(200) as $o) {
                yield ['key' => 'order:'.$o->id, 'customer_id' => $o->customer_id, 'order_id' => $o->id, 'anchor' => $o->fulfilled_at->setTimezone($tz)->toDateString()];
            }
        } elseif ($rule->trigger === 'visit') {
            foreach (Appointment::where('status', 'completed')->where('starts_at', '>=', $cutoff->utc())->whereNotNull('customer_id')->lazyById(200) as $a) {
                yield ['key' => 'visit:'.$a->id, 'customer_id' => $a->customer_id, 'appointment_id' => $a->id, 'anchor' => $a->starts_at->setTimezone($tz)->toDateString()];
            }
        } elseif ($rule->trigger === 'examination') {
            foreach (OpticalRecord::whereIn('kind', ['optometry', 'examination'])->where('recorded_on', '>=', $cutoff->toDateString())->where('status', 'finalized')->whereDoesntHave('amendment', fn ($q) => $q->where('status', 'finalized'))->lazyById(200) as $o) {
                if (OpticalRecord::where('customer_id', $o->customer_id)->whereIn('kind', ['optometry', 'examination'])->where('status', 'finalized')->where(fn ($q) => $q->where('recorded_on', '>', $o->recorded_on)->orWhere(fn ($n) => $n->where('recorded_on', $o->recorded_on)->where('id', '>', $o->id)))->exists()) {
                    continue;
                }
                yield ['key' => 'exam:'.$o->id, 'customer_id' => $o->customer_id, 'optical_record_id' => $o->id, 'anchor' => $o->recorded_on->toDateString()];
            }
        }
    }
}
