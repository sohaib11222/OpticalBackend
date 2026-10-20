<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('viewAny', Customer::class);
        $d = $r->validate(['search' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'archived', 'all'])], 'city' => 'nullable|string|max:100', 'sex' => 'nullable|string|max:40', 'dob_from' => 'nullable|date_format:Y-m-d', 'dob_to' => 'nullable|date_format:Y-m-d|after_or_equal:dob_from', 'sort' => ['nullable', Rule::in(['last_name', 'first_name', 'created_at'])], 'direction' => 'nullable|in:asc,desc', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = Customer::query();
        if (($d['status'] ?? 'active') !== 'all') {
            $q->where('status', $d['status'] ?? 'active');
        }
        if (! empty($d['search'])) {
            $v = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $d['search']).'%';
            $q->where(function ($n) use ($v) {
                foreach (['first_name', 'last_name', 'email', 'phone', 'postal_code'] as $field) {
                    $n->orWhereRaw("$field LIKE ? ESCAPE '!'", [$v]);
                }
            });
        }
        foreach (['city', 'sex'] as $field) {
            if (! empty($d[$field])) {
                $q->where($field, $d[$field]);
            }
        }
        if (! empty($d['dob_from'])) {
            $q->where('date_of_birth', '>=', $d['dob_from']);
        }if (! empty($d['dob_to'])) {
            $q->where('date_of_birth', '<=', $d['dob_to']);
        }

        return CustomerResource::collection($q->orderBy($d['sort'] ?? 'last_name', $d['direction'] ?? 'asc')->orderBy('id')->paginate($d['per_page'] ?? 20));
    }

    public function show(int $id)
    {
        $c = Customer::findOrFail($id);
        Gate::authorize('view', $c);

        return new CustomerResource($c);
    }

    public function store(CustomerRequest $r, CustomerRegistry $registry)
    {
        [$c,$created] = $registry->save($r->validated(), $r->user()->id);

        return (new CustomerResource($c))->response()->setStatusCode($created ? 201 : 200);
    }

    public function update(CustomerRequest $r, CustomerRegistry $registry, int $id)
    {
        $c = Customer::findOrFail($id);
        Gate::authorize('update', $c);
        [$c] = $registry->save($r->validated(), $r->user()->id, $id);

        return new CustomerResource($c);
    }

    public function duplicates(CustomerRequest $r, CustomerRegistry $registry)
    {
        $d = $r->validate(['exclude_id' => 'nullable|integer|min:1']);

        return CustomerResource::collection($registry->duplicates($r->validated(), $d['exclude_id'] ?? null));
    }

    public function status(Request $r, CustomerRegistry $registry, int $id)
    {
        $c = Customer::findOrFail($id);
        Gate::authorize('update', $c);
        $d = $r->validate(['status' => 'required|in:active,archived', 'version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000', 'shop_id' => 'prohibited']);

        return new CustomerResource($registry->changeStatus($id, $d, $r->user()->id));
    }

    public function history(Request $r, int $id)
    {
        $c = Customer::findOrFail($id);
        Gate::authorize('view', $c);
        $d = $r->validate(['page' => 'nullable|integer|min:1']);

        return $c->events()->with('actor:id,name')->select('id', 'customer_id', 'actor_id', 'action', 'changed_fields', 'reason', 'subject_type', 'subject_id', 'created_at')->orderByDesc('id')->paginate(30);
    }
}
