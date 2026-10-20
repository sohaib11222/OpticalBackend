<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Shop;
use App\Services\Agenda;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AgendaController extends Controller
{
    private function permit(string $capability): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('agenda.'.$capability), 403);
    }

    public function index(Request $r)
    {
        $this->permit('read');
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after:from', 'status' => 'nullable|in:scheduled,completed,cancelled,no_show', 'customer_id' => 'nullable|integer|min:1']);
        $zone = Shop::findOrFail(app(TenantContext::class)->id())->timezone;
        $from = CarbonImmutable::parse($d['from'], $zone)->utc();
        $to = CarbonImmutable::parse($d['to'], $zone)->utc();
        abort_if($from->diffInDays($to) > 94, 422, 'Choose a calendar range of no more than 93 days.');
        if (! empty($d['customer_id'])) {
            Customer::findOrFail($d['customer_id']);
        }
        $q = Appointment::where('starts_at', '<', $to)->where('ends_at', '>', $from)->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($d['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v));
        abort_if((clone $q)->count() > 1000, 422, 'Too many appointments. Narrow the calendar range or customer filter.');

        return ['data' => $q->with('customer:id,first_name,last_name')->orderBy('starts_at')->get(), 'timezone' => $zone];
    }

    public function show(int $id)
    {
        $this->permit('read');

        return ['data' => Appointment::with('customer:id,first_name,last_name')->findOrFail($id)];
    }

    public function history(Request $r, int $id)
    {
        $this->permit('read');
        $r->validate(['page' => 'nullable|integer|min:1']);

        return Appointment::findOrFail($id)->events()->with('actor:id,name')->orderByDesc('id')->paginate(30);
    }

    public function store(Request $r, Agenda $service)
    {
        $this->permit('write');
        [$a,$created] = $service->save($this->validateData($r), $r->user()->id);

        return response()->json(['data' => $a], $created ? 201 : 200);
    }

    public function update(Request $r, Agenda $service, int $id)
    {
        $this->permit('write');
        Appointment::findOrFail($id);
        [$a] = $service->save($this->validateData($r, true), $r->user()->id, $id);

        return ['data' => $a];
    }

    private function validateData(Request $r, bool $editing = false): array
    {
        $instant = ['required', 'date', 'regex:/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/'];

        return $r->validate(['title' => 'required|string|max:150', 'notes' => 'nullable|string|max:5000', 'customer_id' => 'nullable|integer|min:1', 'optical_record_id' => 'nullable|integer|min:1', 'order_id' => 'nullable|integer|min:1', 'starts_at' => $instant, 'ends_at' => $instant, 'creation_key' => $editing ? 'prohibited' : 'required|uuid', 'version' => $editing ? 'required|integer|min:1' : 'prohibited', 'reason' => $editing ? 'required|string|min:3|max:2000' : 'nullable|string|max:2000', 'allow_overlap' => 'sometimes|boolean', 'status' => 'prohibited', 'shop_id' => 'prohibited', 'timezone' => 'prohibited']);
    }

    public function status(Request $r, Agenda $service, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['status' => 'required|in:completed,cancelled,no_show', 'version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']);

        return ['data' => $service->status($id, $d, $r->user()->id)];
    }
}
