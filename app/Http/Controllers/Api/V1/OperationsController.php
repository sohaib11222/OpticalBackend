<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Receivable;
use App\Models\Reminder;
use App\Models\Shop;
use App\Models\StockBalance;
use App\Services\Operations\Access;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    private function query(): array
    {
        $shop = Shop::findOrFail(app(TenantContext::class)->id());
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $parts = [];
        if (Access::can('reminders.read')) {
            $kinds = array_values(array_filter(['checkup', 'contact_lens', 'custom', 'order', 'followup'], fn ($k) => Access::can(Access::reminderPermission($k))));
            $parts[] = Reminder::where('status', 'open')->whereIn('kind', $kinds)->where(fn ($q) => $q->whereNull('customer_id')->orWhereHas('customer', fn ($c) => $c->where('status', 'active')))->selectRaw("id, kind as type, title, due_on, customer_id, id as reference_id, 'reminder' as source");
        }
        if ($parts) {
            Access::scopeReminders($parts[0]);
        }
        if (Access::can('payments.read')) {
            $paid = "(SELECT COALESCE(SUM(CASE WHEN p.kind='payment' THEN p.amount_minor ELSE -p.amount_minor END),0) FROM payment_entries p WHERE p.shop_id=receivables.shop_id AND p.receivable_id=receivables.id)";
            $void = "(receivables.status='void' OR EXISTS (SELECT 1 FROM orders o WHERE o.shop_id=receivables.shop_id AND o.id=receivables.order_id AND o.status IN ('cancelled','reversed')))";
            $parts[] = Receivable::whereRaw("((NOT $void AND amount_minor > $paid) OR ($void AND $paid > 0))")->selectRaw("id, CASE WHEN $void THEN 'refund_due' ELSE 'payment' END as type, reference as title, CASE WHEN $void THEN ? ELSE due_on END as due_on, customer_id, id as reference_id, 'payment' as source", [$today->toDateString()]);
        }
        if (Access::can('orders.read')) {
            // Orders without an explicit due date are today's open work, never invented overdue deadlines.
            $parts[] = Order::whereIn('status', ['draft', 'confirmed'])->selectRaw("id, 'incomplete_order' as type, number as title, COALESCE((SELECT MIN(r.due_on) FROM reminders r WHERE r.shop_id=orders.shop_id AND r.order_id=orders.id AND r.kind='order' AND r.status='open'), ?) as due_on, customer_id, id as reference_id, 'order' as source", [$today->toDateString()]);
        }
        if (Access::can('agenda.read')) {
            $parts[] = Appointment::where('status', 'scheduled')->where('starts_at', '>=', $today->utc())->where('starts_at', '<', $today->addDay()->utc())->selectRaw("id, 'appointment' as type, title, ? as due_on, customer_id, id as reference_id, 'appointment' as source", [$today->toDateString()]);
        }
        if (Access::can('stock.read')) {
            $parts[] = StockBalance::whereHas('product', fn ($p) => $p->where('status', 'active')->whereRaw('stock_balances.on_hand - stock_balances.reserved <= products.low_stock_threshold'))->selectRaw("product_id as id, 'low_stock' as type, (SELECT name FROM products WHERE products.id=stock_balances.product_id AND products.shop_id=stock_balances.shop_id) as title, ? as due_on, NULL as customer_id, product_id as reference_id, 'product' as source", [$today->toDateString()]);
        }
        $union = null;
        foreach ($parts as $p) {
            $base = $p->toBase();
            $union ? $union->unionAll($base) : $union = $base;
        }
        $union ??= DB::query()->selectRaw('NULL as id, NULL as type, NULL as title, NULL as due_on, NULL as customer_id, NULL as reference_id, NULL as source')->whereRaw('1=0');

        return [DB::query()->fromSub($union, 'activities'), $today, $shop];
    }

    public function index(Request $r)
    {
        Access::require('operations.read');
        $d = $r->validate(['filter' => 'nullable|in:appointment,followup,contact_lens,checkup,payment,refund_due,incomplete_order,low_stock,custom,order', 'bucket' => 'nullable|in:overdue,today,next7,next30', 'page' => 'nullable|integer|min:1']);
        [$q, $today, $shop] = $this->query();
        $limit = $today->addDays(30)->toDateString();
        $counts = (clone $q)->where('due_on', '<=', $limit)->select('type')->selectRaw('COUNT(*) as total')->groupBy('type')->pluck('total', 'type');
        if ($counts->has('followup')) {
            $counts->put('followup', (clone $q)->where('due_on', '<=', $limit)->where('type', 'followup')->distinct()->count('customer_id'));
        }
        foreach (['appointment' => 'agenda.read', 'followup' => 'followups.read', 'contact_lens' => 'optical.read', 'checkup' => 'optical.read', 'payment' => 'payments.read', 'refund_due' => 'payments.read', 'incomplete_order' => 'orders.read', 'low_stock' => 'stock.read'] as $kind => $permission) {
            if (Access::can($permission) && ! $counts->has($kind)) {
                $counts->put($kind, 0);
            }
        }
        if (! empty($d['filter'])) {
            $q->where('type', $d['filter']);
        }
        app(ReminderController::class)->bucket($q, $d['bucket'] ?? null);
        $list = $q->where('due_on', '<=', $limit)->orderBy('due_on')->orderBy('type')->orderBy('id')->paginate(30);
        $customerIds = collect($list->items())->pluck('customer_id')->filter()->unique();
        $customers = Access::can('customers.read') ? Customer::whereIn('id', $customerIds)->get(['id', 'first_name', 'last_name', 'email', 'phone'])->keyBy('id') : collect();
        $list->through(function ($row) use ($customers) {
            $row->customer = $customers->get($row->customer_id);
            $row->url = match ($row->source) {
                'order' => '/orders/'.$row->reference_id, 'payment' => '/payments?id='.$row->reference_id, 'appointment' => '/agenda?appointment_id='.$row->reference_id, 'product' => '/inventory?low=1', default => '/reminders?id='.$row->reference_id
            };

            return $row;
        });

        return ['date' => $today->toDateString(), 'timezone' => $shop->timezone, 'horizon' => $limit, 'counts' => $counts, 'activities' => $list];
    }
}
