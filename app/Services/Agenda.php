<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Customer;
use App\Models\OpticalRecord;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Commerce\Notices;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Agenda
{
    public function save(array $d, int $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($d, $actor, $id) {
            $shop = Shop::lockForUpdate()->findOrFail(app(TenantContext::class)->id());
            $start = CarbonImmutable::parse($d['starts_at'])->utc();
            $end = CarbonImmutable::parse($d['ends_at'])->utc();
            if ($end <= $start || $end->diffInSeconds($start, true) > 86400) {
                throw ValidationException::withMessages(['ends_at' => 'End must be after start, within 24 hours.']);
            }
            $payload = ['title' => $d['title'], 'notes' => $d['notes'] ?? null, 'customer_id' => $d['customer_id'] ?? null, 'optical_record_id' => $d['optical_record_id'] ?? null, 'order_id' => $d['order_id'] ?? null, 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $shop->timezone];
            if ($payload['customer_id']) {
                abort_unless(Customer::findOrFail($payload['customer_id'])->status === 'active', 409, 'Select an active customer.');
            }
            if ($payload['optical_record_id']) {
                abort_unless($payload['customer_id'], 422, 'Select a customer before linking optical care.');
                OpticalRecord::where('customer_id', $payload['customer_id'])->findOrFail($payload['optical_record_id']);
            }
            if ($payload['order_id']) {
                abort_unless($payload['customer_id'], 422, 'Select a customer before linking an order.');
                abort_unless(app(TenantContext::class)->membership()->can('orders.read'), 403);
                Order::where('customer_id', $payload['customer_id'])->findOrFail($payload['order_id']);
            }
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if (! $id && ($old = Appointment::where('creation_key', $d['creation_key'])->first())) {
                abort_unless(hash_equals($old->creation_hash, $hash), 409, 'This request key already belongs to another appointment.');

                return [$old, false];
            }
            $a = $id ? Appointment::lockForUpdate()->findOrFail($id) : new Appointment;
            if ($id) {
                abort_unless($a->version === $d['version'] && $a->status === 'scheduled', 409, 'This appointment changed or is no longer scheduled. Refresh before editing.');
            }
            $conflicts = Appointment::where('status', 'scheduled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->when($id, fn ($q) => $q->where('id', '!=', $id))->limit(10)->pluck('id');
            abort_if($conflicts->isNotEmpty() && ! ($d['allow_overlap'] ?? false), 409, 'This appointment overlaps scheduled appointment(s) #'.$conflicts->implode(', #').'. Review the calendar and explicitly allow the overlap to continue.');
            $previous = $id ? $this->snapshot($a) : null;
            $oldCustomer = $a->customer_id;
            $a->fill($payload);
            if ($id) {
                $a->version++;
            } else {
                $a->creation_key = $d['creation_key'];
                $a->creation_hash = $hash;
                $a->created_by = $actor;
            }$a->save();
            $a->refresh();
            $this->event($a, $id ? 'rescheduled' : 'created', $actor, $previous, $d['reason'] ?? null, $conflicts->isNotEmpty());
            if ($oldCustomer && $oldCustomer !== $a->customer_id) {
                app(CustomerRegistry::class)->event(Customer::findOrFail($oldCustomer), 'appointment.unlinked', $actor, [], null, 'appointment', $a->id);
            }

            return [$a->load('customer:id,first_name,last_name'), true];
        }, 3);
    }

    public function status(int $id, array $d, int $actor): Appointment
    {
        return DB::transaction(function () use ($id, $d, $actor) {
            Shop::lockForUpdate()->findOrFail(app(TenantContext::class)->id());
            $a = Appointment::lockForUpdate()->findOrFail($id);
            if ($a->status === $d['status'] && $a->version === $d['version'] + 1) {
                return $a;
            }
            abort_unless($a->status === 'scheduled' && $a->version === $d['version'], 409, 'This appointment is no longer scheduled or has changed. Refresh to review its history.');
            if (in_array($d['status'], ['completed', 'no_show'])) {
                abort_if($a->starts_at->isFuture(), 422, 'An appointment cannot be completed or missed before it starts.');
            }
            $previous = $this->snapshot($a);
            $a->status = $d['status'];
            $a->version++;
            $a->save();
            $this->event($a, $a->status, $actor, $previous, $d['reason']);

            return $a->load('customer:id,first_name,last_name');
        });
    }

    private function snapshot(Appointment $a): array
    {
        return ['starts_at' => $a->starts_at?->toISOString(), 'ends_at' => $a->ends_at?->toISOString(), 'status' => $a->status, 'customer_id' => $a->customer_id, 'optical_record_id' => $a->optical_record_id, 'order_id' => $a->order_id, 'version' => $a->version];
    }

    private function event(Appointment $a, string $action, int $actor, ?array $previous, ?string $reason, bool $overlap = false): void
    {
        AppointmentEvent::create(['appointment_id' => $a->id, 'actor_id' => $actor, 'action' => $action, 'previous' => $previous, 'current' => $this->snapshot($a), 'reason' => $reason, 'overlap_confirmed' => $overlap]);
        app(Notices::class)->emit('agenda', 'agenda.read', 'appointment:'.$a->id.':'.$a->version, 'appointment.'.$action, 'Appointment #'.$a->id.' '.$action, '/agenda?appointment_id='.$a->id);
        Audit::record('appointment.'.$action, $a->shop_id, $actor, 'appointment', $a->id);
        if ($a->customer_id) {
            app(CustomerRegistry::class)->event($a->customer, 'appointment.'.$action, $actor, [], $reason, 'appointment', $a->id);
        }
    }
}
