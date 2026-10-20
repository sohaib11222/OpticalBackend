<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\OpticalRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpticalRecords
{
    public function save(Customer $customer, string $kind, array $d, int $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($customer, $kind, $d, $actor, $id) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            abort_unless($customer->status === 'active', 409, 'Restore this customer before recording optical care.');
            $payload = ['recorded_on' => $d['recorded_on'], 'practitioner' => $d['practitioner'], 'data' => $d['data'], 'prescription_id' => $d['prescription_id'] ?? null, 'source_record_id' => $d['source_record_id'] ?? null];
            ksort($payload['data']);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if (! $id && ($previous = OpticalRecord::where('creation_key', $d['creation_key'])->first())) {
                abort_unless($previous->customer_id === $customer->id && $previous->kind === $kind && hash_equals($previous->creation_hash, $hash), 409, 'This request key was already used for different details.');

                return [$previous, false];
            }
            $class = OpticalDictionary::MODELS[$kind];
            $record = $id ? $class::where('customer_id', $customer->id)->lockForUpdate()->findOrFail($id) : new $class;
            if ($id) {
                abort_unless($record->status === 'draft', 409, 'Finalized records require an amendment.');
                abort_unless($record->version === $d['version'], 409, 'This record changed. Reload before saving.');
                $record->version++;
            }
            $this->validateSource($customer->id, $kind, $payload['source_record_id']);
            if ($payload['prescription_id']) {
                abort_unless(in_array($kind, ['glasses', 'contact_lens']), 422, 'Only glasses and contact-lens records link a prescription.');
                $rx = OpticalRecord::where('customer_id', $customer->id)->where('kind', 'prescription')->where('status', 'finalized')->findOrFail($payload['prescription_id']);
                abort_if($rx->amendment()->where('status', 'finalized')->exists(), 409, 'Select the current prescription revision.');
            }
            if (! empty($payload['data']['expiry_date']) && $payload['data']['expiry_date'] <= $payload['recorded_on']) {
                throw ValidationException::withMessages(['data.expiry_date' => 'Expiry must be after the recorded date.']);
            }
            $record->fill($payload);
            if (! $id) {
                $record->customer_id = $customer->id;
                $record->kind = $kind;
                $record->created_by = $actor;
                $record->creation_key = $d['creation_key'];
                $record->creation_hash = $hash;
            }
            $record->save();
            app(CustomerRegistry::class)->event($customer, $kind.($id ? '.updated' : '.created'), $actor, [], null, 'optical_record', $record->id);

            return [$record->refresh(), true];
        }, 3);
    }

    public function finalize(OpticalRecord $record, int $version, int $actor): OpticalRecord
    {
        return DB::transaction(function () use ($record, $version, $actor) {
            $customer = Customer::lockForUpdate()->findOrFail($record->customer_id);
            $record = OpticalRecord::lockForUpdate()->findOrFail($record->id);
            abort_unless($customer->status === 'active', 409, 'Restore this customer before finalizing.');
            if ($record->status === 'finalized' && $record->version === $version + 1) {
                return $record;
            }
            abort_unless($record->status === 'draft' && $record->version === $version, 409, 'This record changed. Reload before finalizing.');
            $this->validateSource($customer->id, $record->kind, $record->source_record_id);
            if ($record->prescription_id) {
                $rx = OpticalRecord::where('customer_id', $customer->id)->where('kind', 'prescription')->where('status', 'finalized')->findOrFail($record->prescription_id);
                abort_if($rx->amendment()->where('status', 'finalized')->exists(), 409, 'Select the current prescription revision before finalizing.');
            }
            OpticalDictionary::validateFinal($record);
            $record->status = 'finalized';
            $record->finalized_at = now();
            $record->finalized_by = $actor;
            $record->version++;
            $record->save();
            app(CustomerRegistry::class)->event($customer, $record->kind.'.finalized', $actor, [], null, 'optical_record', $record->id);

            return $record;
        });
    }

    public function amend(OpticalRecord $record, array $d, int $actor): OpticalRecord
    {
        return DB::transaction(function () use ($record, $d, $actor) {
            $customer = Customer::lockForUpdate()->findOrFail($record->customer_id);
            $record = OpticalRecord::lockForUpdate()->findOrFail($record->id);
            abort_unless($customer->status === 'active', 409, 'Restore this customer before amending.');
            abort_unless($record->status === 'finalized' && $record->version === $d['version'], 409, 'Only the current finalized version can be amended.');
            $existing = $record->amendment()->first();
            if ($existing) {
                abort_unless($existing->creation_key === $d['creation_key'] && $existing->amendment_reason === $d['reason'], 409, 'An amendment already exists. Open that revision.');

                return $existing;
            }
            abort_if(OpticalRecord::where('creation_key', $d['creation_key'])->exists(), 409, 'This request key has already been used.');
            $next = $record->replicate(['id', 'created_at', 'updated_at', 'finalized_at', 'finalized_by', 'amends_id', 'creation_key', 'creation_hash']);
            $next->status = 'draft';
            $next->version = 1;
            $next->revision = $record->revision + 1;
            $next->amends_id = $record->id;
            $next->amendment_reason = $d['reason'];
            $next->created_by = $actor;
            $next->creation_key = $d['creation_key'];
            $next->creation_hash = hash('sha256', $d['creation_key']);
            $next->save();
            app(CustomerRegistry::class)->event($customer, $record->kind.'.amended', $actor, [], $d['reason'], 'optical_record', $next->id);

            return $next;
        });
    }

    private function validateSource(int $customer, string $kind, ?int $id): void
    {
        if (! $id) {
            return;
        }
        $allowed = match ($kind) {
            'examination' => ['optometry'], 'prescription' => ['optometry', 'examination'], default => []
        };
        abort_unless($allowed, 422, 'Only examinations and prescriptions link earlier care records.');
        $source = OpticalRecord::where('customer_id', $customer)->whereIn('kind', $allowed)->where('status', 'finalized')->findOrFail($id);
        abort_if($source->amendment()->where('status', 'finalized')->exists(), 409, 'Select the current source record revision.');
    }
}
