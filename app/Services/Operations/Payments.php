<?php

namespace App\Services\Operations;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Receivable;
use App\Services\Audit;
use App\Services\Commerce\Money;
use App\Services\Commerce\ShopLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Payments
{
    public function summary(Receivable $r): array
    {
        $paid = (int) $r->entries()->selectRaw("COALESCE(SUM(CASE WHEN kind = 'payment' THEN amount_minor ELSE -amount_minor END),0) AS paid")->value('paid');
        $void = $r->status === 'void' || ($r->order_id && in_array($r->order?->status, ['cancelled', 'reversed']));

        return ['paid_minor' => $paid, 'balance_minor' => $void ? 0 : max(0, $r->amount_minor - $paid), 'refund_due_minor' => $void ? $paid : 0, 'effective_status' => $void ? ($paid ? 'refund_due' : 'void') : ($paid >= $r->amount_minor ? 'paid' : ($paid ? 'partial' : 'unpaid'))];
    }

    public function detail(Receivable $r): array
    {
        $r->load(['customer:id,first_name,last_name,email,phone,status', 'order:id,number,status', 'entries.actor:id,name']);

        return [...$r->toArray(), ...$this->summary($r)];
    }

    public function save(array $d, int $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($d, $actor, $id) {
            $shop = ShopLock::acquire();
            abort_unless($shop->currency, 409, 'Set the shop currency first.');
            $hash = hash('sha256', json_encode($d));
            if (! $id && ($old = Receivable::where('creation_key', $d['creation_key'])->first())) {
                abort_unless(hash_equals($old->creation_hash, $hash), 409, 'This request key was used for different details.');

                return $this->detail($old);
            }
            if ($id) {
                $r = Receivable::lockForUpdate()->findOrFail($id);
                abort_unless($r->version === $d['version'] && $r->status === 'open', 409, 'This balance changed or was voided. Refresh before editing.');
                $r->fill(['reference' => $d['reference'], 'due_on' => $d['due_on'], 'notes' => $d['notes'] ?? null, 'version' => $r->version + 1]);
            } else {
                $c = Customer::findOrFail($d['customer_id']);
                abort_unless($c->status === 'active', 409, 'Restore the customer before creating a balance.');
                $order = empty($d['order_id']) ? null : Order::where('customer_id', $c->id)->findOrFail($d['order_id']);
                if ($order) {
                    abort_unless(in_array($order->status, ['confirmed', 'fulfilled']), 409, 'Only confirmed or fulfilled orders can have a balance.');
                    abort_if(Receivable::where('order_id', $order->id)->exists(), 409, 'This order already has a balance. Open the existing record.');
                }
                $amount = $order ? $order->total_minor : Money::parse($d['amount'], $shop->currency, 'amount');
                abort_unless($amount > 0, 422, 'Enter a positive amount.');
                $r = new Receivable(['customer_id' => $c->id, 'order_id' => $order?->id, 'reference' => $d['reference'], 'due_on' => $d['due_on'], 'notes' => $d['notes'] ?? null, 'currency' => $order?->currency ?? $shop->currency, 'price_decimals' => $order?->price_decimals ?? Money::precision($shop->currency), 'amount_minor' => $amount, 'creation_key' => $d['creation_key'], 'creation_hash' => $hash, 'created_by' => $actor]);
            }
            $r->save();
            Audit::record($id ? 'receivable.updated' : 'receivable.created', $shop->id, $actor, 'receivable', $r->id);

            return $this->detail($r->refresh());
        }, 3);
    }

    public function record(int $id, array $d, int $actor): array
    {
        return DB::transaction(function () use ($id, $d, $actor) {
            $shop = ShopLock::acquire();
            abort_if($d['paid_on'] > CarbonImmutable::now($shop->timezone)->toDateString(), 422, 'Payment date cannot be in the future in the shop timezone.');
            $r = Receivable::lockForUpdate()->findOrFail($id);
            $hash = hash('sha256', json_encode([$id, $d]));
            if ($old = PaymentEntry::where('creation_key', $d['creation_key'])->first()) {
                abort_unless(hash_equals($old->creation_hash, $hash), 409, 'This request key was used for different details.');

                return $this->detail($r);
            }
            abort_unless($r->version === $d['version'], 409, 'This balance changed. Refresh before posting a payment.');
            $reversed = empty($d['reverses_id']) ? null : $r->entries()->where('kind', 'payment')->findOrFail($d['reverses_id']);
            if ($reversed) {
                Access::require('payments.reverse');
                abort_if(PaymentEntry::where('reverses_id', $reversed->id)->exists(), 409, 'This payment was already reversed.');
                abort_if($d['paid_on'] < $reversed->paid_on->toDateString(), 422, 'A correction cannot predate the original payment.');
                $amount = $reversed->amount_minor;
            } else {
                $balance = $this->summary($r);
                $amount = Money::parse($d['amount'], $r->currency, 'amount');
                abort_unless($amount > 0 && $amount <= $balance['balance_minor'], 422, 'Payment must be positive and cannot exceed the outstanding balance.');
                abort_unless($r->customer->status === 'active', 409, 'Restore the customer before recording payment.');
            }
            $entry = PaymentEntry::create(['receivable_id' => $id, 'kind' => $reversed ? 'reversal' : 'payment', 'method' => $reversed ? $reversed->method : $d['method'], 'amount_minor' => $amount, 'paid_on' => $d['paid_on'], 'reference' => $d['reference'] ?? null, 'reason' => $d['reason'] ?? null, 'reverses_id' => $reversed?->id, 'creation_key' => $d['creation_key'], 'creation_hash' => $hash, 'actor_id' => $actor]);
            $r->increment('version');
            Audit::record($reversed ? 'payment.reversed' : 'payment.recorded', $r->shop_id, $actor, 'payment', $entry->id);

            return $this->detail($r->refresh());
        }, 3);
    }

    public function void(int $id, array $d, int $actor): array
    {
        return DB::transaction(function () use ($id, $d, $actor) {
            ShopLock::acquire();
            $r = Receivable::lockForUpdate()->findOrFail($id);
            abort_unless($r->version === $d['version'], 409, 'This balance changed. Refresh before continuing.');
            abort_if($r->order_id && ! in_array($r->order->status, ['cancelled', 'reversed']), 409, 'Cancel or reverse the linked order before voiding its balance.');
            $r->forceFill(['status' => 'void', 'notes' => trim(($r->notes ?? '')."\nVoid: ".$d['reason']), 'version' => $r->version + 1])->save();
            Audit::record('receivable.voided', $r->shop_id, $actor, 'receivable', $id);

            return $this->detail($r);
        });
    }
}
