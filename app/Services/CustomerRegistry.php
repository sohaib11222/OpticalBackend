<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Shop;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class CustomerRegistry
{
    public function duplicates(array $d, ?int $except = null)
    {
        $phone = preg_replace('/\D/', '', $d['phone'] ?? '');

        return Customer::query()->when($except, fn ($q) => $q->where('id', '!=', $except))->where(function ($q) use ($d, $phone) {
            $q->whereRaw('1=0');
            if (! empty($d['email'])) {
                $q->orWhere('email', strtolower($d['email']));
            }
            if ($phone !== '') {
                $q->orWhere('phone_normalized', $phone);
            }
            if (! empty($d['date_of_birth'])) {
                $q->orWhere(fn ($n) => $n->whereRaw('LOWER(first_name) = ?', [mb_strtolower($d['first_name'] ?? '')])->whereRaw('LOWER(last_name) = ?', [mb_strtolower($d['last_name'] ?? '')])->where('date_of_birth', $d['date_of_birth']));
            }
        })->orderBy('id')->limit(25)->get();
    }

    public function save(array $d, int $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($d, $actor, $id) {
            // Serializes duplicate review and idempotent creation within this shop.
            Shop::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $fields = array_fill_keys(Customer::FIELDS, null);
            foreach (Customer::FIELDS as $field) {
                $fields[$field] = $d[$field] ?? null;
            }
            $custom = $this->customFields($d['custom_fields'] ?? []);
            $hash = hash('sha256', json_encode([$fields, $custom], JSON_THROW_ON_ERROR));
            if (! $id && ($previous = Customer::where('creation_key', $d['creation_key'])->first())) {
                abort_unless(hash_equals($previous->creation_hash, $hash), 409, 'This request key was already used with different details.');

                return [$previous, false];
            }
            $c = $id ? Customer::lockForUpdate()->findOrFail($id) : new Customer;
            if ($id) {
                abort_unless($c->version === $d['version'], 409, 'This customer changed. Refresh before saving.');
                abort_unless($c->status === 'active', 409, 'Restore this customer before editing.');
            }
            abort_if(! ($d['confirm_distinct'] ?? false) && $this->duplicates($fields, $id)->isNotEmpty(), 409, 'Possible duplicate customer. Review the matches and explicitly confirm that this is a distinct record.');
            $c->fill($fields);
            if ($id) {
                // Imports only include non-empty additional values. Merge them
                // so an update never erases an unrelated profile field.
                $custom = array_replace($c->custom_fields ?? [], $custom);
            }
            $c->custom_fields = $custom ?: null;
            $changed = array_keys($c->getDirty());
            $c->phone_normalized = preg_replace('/\D/', '', $fields['phone'] ?? '') ?: null;
            if (! $id) {
                $c->creation_key = $d['creation_key'];
                $c->creation_hash = $hash;
            } else {
                $c->version++;
            }
            $c->save();
            $c->refresh();
            $this->event($c, $id ? 'customer.updated' : 'customer.created', $actor, $changed);

            return [$c, true];
        }, 3);
    }

    /** @return array<string,string> */
    private function customFields(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $clean = [];
        foreach (array_slice($values, 0, 20, true) as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || mb_strlen($key) > 100 || ! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && mb_strlen($value) <= 4096) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public function changeStatus(int $id, array $d, int $actor): Customer
    {
        return DB::transaction(function () use ($id, $d, $actor) {
            $c = Customer::lockForUpdate()->findOrFail($id);
            abort_unless($c->version === $d['version'], 409, 'This customer changed. Refresh before updating its status.');
            if ($c->status !== $d['status']) {
                $c->status = $d['status'];
                $c->version++;
                $c->save();
                $this->event($c, 'customer.'.$d['status'], $actor, [], $d['reason']);
            }

            return $c;
        });
    }

    public function event(Customer $customer, string $action, int $actor, array $fields = [], ?string $reason = null, ?string $subjectType = null, ?int $subjectId = null): void
    {
        CustomerEvent::create(['customer_id' => $customer->id, 'actor_id' => $actor, 'action' => $action, 'changed_fields' => $fields, 'reason' => $reason, 'subject_type' => $subjectType, 'subject_id' => $subjectId]);
        Audit::record($action, $customer->shop_id, $actor, $subjectType ?? 'customer', $subjectId ?? $customer->id);
    }
}
