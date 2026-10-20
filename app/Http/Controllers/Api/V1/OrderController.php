<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SaleEntry;
use App\Services\Commerce\Orders;
use App\Services\Commerce\SalesLedger;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private function permit(string $permission): void
    {
        abort_unless(app(TenantContext::class)->membership()->can($permission), 403);
    }

    public function index(Request $r)
    {
        $this->permit('orders.read');
        $d = $r->validate(['search' => 'nullable|string|max:150', 'customer_id' => 'nullable|integer|min:1', 'status' => 'nullable|in:draft,confirmed,fulfilled,cancelled,reversed', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        if (! empty($d['customer_id'])) {
            Customer::findOrFail($d['customer_id']);
        }
        $q = Order::with('customer:id,first_name,last_name')->when($d['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        if (! empty($d['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($d['search'])).'%';
            $q->where(fn ($q) => $q->whereRaw("LOWER(number) LIKE ? ESCAPE '!'", [$term])->orWhereHas('customer', fn ($c) => $c->where(fn ($c) => $c->whereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$term])->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$term]))));
        }

        return $q->orderByDesc('id')->paginate($d['per_page'] ?? 20);
    }

    public function show(Orders $service, int $id)
    {
        $this->permit('orders.read');

        return ['data' => $service->details(Order::findOrFail($id))];
    }

    private function fields(Request $r, bool $edit = false): array
    {
        $d = $r->validate(['customer_id' => 'required|integer|min:1', 'prescription_id' => 'nullable|integer|min:1', 'notes' => 'nullable|string|max:5000', 'items' => 'required|array|min:1|max:50', 'items.*' => 'required|array:product_id,quantity', 'items.*.product_id' => 'required|integer|min:1|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000000', 'creation_key' => $edit ? 'prohibited' : 'required|uuid', 'version' => $edit ? 'required|integer|min:1' : 'prohibited', 'shop_id' => 'prohibited', 'status' => 'prohibited', 'channel' => 'prohibited', 'currency' => 'prohibited', 'total_minor' => 'prohibited']);
        if (! empty($d['prescription_id'])) {
            $this->permit('optical.read');
        }

        return $d;
    }

    public function store(Request $r, Orders $s)
    {
        $this->permit('orders.write');
        [$o,$created] = $s->save($this->fields($r), $r->user()->id);

        return response()->json(['data' => $o], $created ? 201 : 200);
    }

    public function update(Request $r, Orders $s, int $id)
    {
        $this->permit('orders.write');
        [$o] = $s->save($this->fields($r, true), $r->user()->id, $id);

        return ['data' => $o];
    }

    public function transition(Request $r, Orders $s, int $id, string $action)
    {
        $this->permit(match ($action) {
            'fulfil' => 'orders.fulfil','reverse' => 'sales.reverse',default => 'orders.write'
        });
        $d = $r->validate(['version' => 'required|integer|min:1', 'creation_key' => 'required|uuid', 'reason' => in_array($action, ['cancel', 'reverse']) ? 'required|string|min:3|max:2000' : 'nullable|string|max:2000', 'return_stock' => $action === 'reverse' ? 'required|boolean' : 'prohibited', 'shop_id' => 'prohibited']);
        if ($action === 'reverse') {
            $d['return_stock'] = (bool) $d['return_stock'];
        }

        return ['data' => $s->transition($id, $action, $d, $r->user()->id)];
    }

    public function history(Request $r, int $id)
    {
        $this->permit('orders.read');
        $r->validate(['page' => 'nullable|integer|min:1']);

        return Order::findOrFail($id)->events()->with('actor:id,name')->orderByDesc('id')->paginate(30);
    }

    public function customerSales(Request $r, int $id)
    {
        $this->permit('orders.read');
        Customer::findOrFail($id);
        $r->validate(['page' => 'nullable|integer|min:1']);
        $q = SaleEntry::where('customer_id', $id);
        $q->where('id', '<=', (clone $q)->max('id') ?? 0);

        return ['entries' => (clone $q)->with(['order:id,number,status', 'actor:id,name'])->orderByDesc('id')->paginate(20), 'summary' => SalesLedger::summary($q)];
    }
}
