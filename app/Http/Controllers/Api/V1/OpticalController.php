<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OpticalRecord;
use App\Services\OpticalDictionary;
use App\Services\OpticalRecords;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class OpticalController extends Controller
{
    private function permit(string $action): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('optical.'.$action), 403);
    }

    private function record(int $customer, int $id): OpticalRecord
    {
        Customer::findOrFail($customer);

        return OpticalRecord::where('customer_id', $customer)->findOrFail($id);
    }

    public function dictionary()
    {
        $this->permit('read');

        return ['data' => array_map(fn ($kind) => ['kind' => $kind, 'label' => OpticalDictionary::LABELS[$kind], 'fields' => OpticalDictionary::fields($kind)], array_keys(OpticalDictionary::MODELS))];
    }

    public function index(Request $r, int $customer)
    {
        $this->permit('read');
        Customer::findOrFail($customer);
        $d = $r->validate(['kind' => 'nullable|in:'.implode(',', array_keys(OpticalDictionary::MODELS)), 'status' => 'nullable|in:draft,finalized', 'page' => 'nullable|integer|min:1']);

        return OpticalRecord::where('customer_id', $customer)->when($d['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->with('amendment:id,amends_id,revision,status')->orderByDesc('id')->paginate(20);
    }

    public function show(int $customer, int $id)
    {
        $this->permit('read');

        return ['data' => $this->record($customer, $id)->load('amendment:id,amends_id,revision,status')];
    }

    public function store(Request $r, OpticalRecords $service, int $customer, string $kind)
    {
        $this->permit('write');
        $d = $this->validateRecord($r, $kind);
        [$record,$created] = $service->save(Customer::findOrFail($customer), $kind, $d, $r->user()->id);

        return response()->json(['data' => $record], $created ? 201 : 200);
    }

    public function update(Request $r, OpticalRecords $service, int $customer, int $id)
    {
        $this->permit('write');
        $record = $this->record($customer, $id);
        $d = $this->validateRecord($r, $record->kind, true);
        [$record] = $service->save($record->customer, $record->kind, $d, $r->user()->id, $id);

        return ['data' => $record];
    }

    private function validateRecord(Request $r, string $kind, bool $editing = false): array
    {
        return $r->validate([...OpticalDictionary::rules($kind), 'recorded_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'practitioner' => 'required|string|max:150', 'prescription_id' => 'nullable|integer|min:1', 'source_record_id' => 'nullable|integer|min:1', 'creation_key' => $editing ? 'prohibited' : 'required|uuid', 'version' => $editing ? 'required|integer|min:1' : 'prohibited', 'shop_id' => 'prohibited', 'customer_id' => 'prohibited', 'status' => 'prohibited', 'finalized_by' => 'prohibited']);
    }

    public function finalize(Request $r, OpticalRecords $service, int $customer, int $id)
    {
        $this->permit('finalize');
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return ['data' => $service->finalize($this->record($customer, $id), $d['version'], $r->user()->id)];
    }

    public function amend(Request $r, OpticalRecords $service, int $customer, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000', 'creation_key' => 'required|uuid']);

        return response()->json(['data' => $service->amend($this->record($customer, $id), $d, $r->user()->id)], 201);
    }
}
