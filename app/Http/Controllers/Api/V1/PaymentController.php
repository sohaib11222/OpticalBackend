<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Receivable;
use App\Services\Operations\Access;
use App\Services\Operations\Payments;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $r, Payments $payments)
    {
        Access::require('payments.read');
        $d = $r->validate(['customer_id' => 'nullable|integer', 'order_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:120', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);

        return Receivable::when($d['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->when($d['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))->when($d['search'] ?? null, fn ($q, $v) => $q->where('reference', 'like', '%'.addcslashes($v, '%_').'%'))->when($d['from'] ?? null, fn ($q, $v) => $q->where('due_on', '>=', $v))->when($d['to'] ?? null, fn ($q, $v) => $q->where('due_on', '<=', $v))->orderBy('due_on')->orderBy('id')->paginate(25)->through(fn ($row) => $payments->detail($row));
    }

    public function show(Request $r, Payments $payments, int $id)
    {
        Access::require('payments.read');
        if ($r->query('purpose') === 'print') {
            Access::require('payments.print');
        }

        return ['data' => $payments->detail(Receivable::findOrFail($id))];
    }

    public function save(Request $r, Payments $payments, ?int $id = null)
    {
        Access::require('payments.write');
        $d = $r->validate(['customer_id' => $id ? 'prohibited' : 'required|integer', 'order_id' => $id ? 'prohibited' : 'nullable|integer', 'reference' => 'required|string|max:120', 'amount' => $id ? 'prohibited' : 'required_without:order_id|nullable|string|max:30', 'due_on' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000', 'creation_key' => $id ? 'prohibited' : 'required|uuid', 'version' => $id ? 'required|integer|min:1' : 'prohibited']);

        return response()->json(['data' => $payments->save($d, $r->user()->id, $id)], $id ? 200 : 201);
    }

    public function payment(Request $r, Payments $payments, int $id)
    {
        Access::require('payments.write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'creation_key' => 'required|uuid', 'amount' => 'required_without:reverses_id|nullable|string|max:30', 'method' => 'required_without:reverses_id|nullable|in:cash,card,bank_transfer,other', 'paid_on' => 'required|date_format:Y-m-d', 'reference' => 'nullable|string|max:150', 'reason' => 'required_with:reverses_id|nullable|string|min:3|max:2000', 'reverses_id' => 'nullable|integer|min:1']);

        return ['data' => $payments->record($id, $d, $r->user()->id)];
    }

    public function void(Request $r, Payments $payments, int $id)
    {
        Access::require('payments.reverse');

        return ['data' => $payments->void($id, $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']), $r->user()->id)];
    }
}
