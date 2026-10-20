<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SaleEntry;
use App\Models\Shop;
use App\Models\StockBalance;
use App\Services\Commerce\Money;
use App\Services\Commerce\SalesLedger;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private function permit(string $p): void
    {
        abort_unless(app(TenantContext::class)->membership()->can($p), 403);
    }

    private function period(Request $r): array
    {
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'page' => 'nullable|integer|min:1', 'customer_id' => 'nullable|integer|min:1', 'kind' => 'nullable|in:sale,reversal']);
        $shop = Shop::findOrFail(app(TenantContext::class)->id());
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $d['from'], $shop->timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $d['to'], $shop->timezone)->addDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a reporting period of up to 366 days.');
        if (! empty($d['customer_id'])) {
            Customer::findOrFail($d['customer_id']);
        }

        return [$d, $shop, $from->utc(), $to->utc()];
    }

    private function entries(array $d, $from, $to)
    {
        return SaleEntry::where('created_at', '>=', $from)->where('created_at', '<', $to)->when($d['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->when($d['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v));
    }

    public function sales(Request $r)
    {
        $this->permit('reports.read');
        [$d,$shop,$from,$to] = $this->period($r);
        $q = $this->entries($d, $from, $to);
        $q->where('id', '<=', (clone $q)->max('id') ?? 0);

        return ['period' => ['from' => $d['from'], 'to' => $d['to'], 'timezone' => $shop->timezone], 'summary' => SalesLedger::summary($q), 'entries' => (clone $q)->with(['order:id,number,status,customer_snapshot', 'actor:id,name'])->orderByDesc('id')->paginate(30)];
    }

    public function dashboard(Request $r)
    {
        $this->permit('reports.read');
        [$d,$shop,$from,$to] = $this->period($r);
        // A common immutable-entry boundary keeps totals and trends aligned if a sale posts during this request.
        $last = SaleEntry::max('id') ?? 0;
        $q = $this->entries($d, $from, $to)->where('id', '<=', $last);
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $tomorrow = $today->addDay();
        $stock = StockBalance::query();
        $low = StockBalance::whereHas('product', fn ($p) => $p->where('status', 'active')->whereRaw('stock_balances.on_hand - stock_balances.reserved <= products.low_stock_threshold'));
        $daily = [];
        $trendAvailable = (clone $q)->count() <= 50000;
        if ($trendAvailable) {
            for ($day = $from->setTimezone($shop->timezone); $day < $to; $day = $day->addDay()) {
                $daily[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'sales_minor' => 0, 'reversals_minor' => 0, 'net_minor' => 0];
            }
            foreach ((clone $q)->select('id', 'kind', 'amount_minor', 'created_at')->orderBy('id')->cursor() as $e) {
                $day = $e->created_at->setTimezone($shop->timezone)->format('Y-m-d');
                $key = $e->kind === 'sale' ? 'sales_minor' : 'reversals_minor';
                $daily[$day][$key] += $e->amount_minor;
                $daily[$day]['net_minor'] += $e->kind === 'sale' ? $e->amount_minor : -$e->amount_minor;
            }
        }

        return ['period' => ['from' => $d['from'], 'to' => $d['to'], 'timezone' => $shop->timezone], 'currency' => $shop->currency, 'price_decimals' => $shop->currency ? Money::precision($shop->currency) : 2, 'summary' => SalesLedger::summary($q), 'trend_available' => $trendAvailable, 'daily' => array_values($daily), 'counts' => [
            'active_customers' => Customer::where('status', 'active')->count(), 'new_customers' => Customer::where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'active_products' => Product::where('status', 'active')->count(), 'confirmed_orders' => Order::where('status', 'confirmed')->count(), 'draft_orders' => Order::where('status', 'draft')->count(),
            'low_stock_products' => (clone $low)->count(), 'on_hand_units' => (int) (clone $stock)->sum('on_hand'), 'reserved_units' => (int) (clone $stock)->sum('reserved'),
            'today_appointments' => Appointment::where('status', 'scheduled')->where('starts_at', '>=', $today->utc())->where('starts_at', '<', $tomorrow->utc())->count(),
            'period_appointments' => Appointment::where('starts_at', '>=', $from)->where('starts_at', '<', $to)->count(),
            'period_orders' => Order::where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
        ], 'low_stock' => (clone $low)->with('product:id,name,sku,low_stock_threshold,status')->orderBy('product_id')->limit(10)->get(), 'recent_orders' => Order::with('customer:id,first_name,last_name')->orderByDesc('id')->limit(5)->get()];
    }

    public function export(Request $r)
    {
        $this->permit('reports.read');
        $this->permit('reports.export');
        [$d,$shop,$from,$to] = $this->period($r);
        $q = $this->entries($d, $from, $to);
        $last = (clone $q)->max('id') ?? 0;
        $q->where('id', '<=', $last);
        abort_if((clone $q)->count() > 50000, 422, 'This export exceeds 50,000 entries. Narrow the date range or customer filter; no rows have been exported.');
        // Resolve scoped queries before middleware clears tenant context. The callback only sends prepared bytes.
        $out = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($out === false) {
            throw new \RuntimeException('Unable to prepare the sales export.');
        }
        try {
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Entry ID', 'Order', 'Kind', 'Posted UTC', 'Posted shop time', 'Customer', 'Currency', 'Amount', 'Stock returned', 'Reason'], ',', '"', '');
            foreach ($q->with('order:id,number,customer_snapshot')->orderBy('id')->lazyById(500) as $e) {
                $name = trim(($e->order->customer_snapshot['first_name'] ?? '').' '.($e->order->customer_snapshot['last_name'] ?? ''));
                $values = [$e->id, $e->order->number, $e->kind, $e->created_at->utc()->toIso8601String(), $e->created_at->setTimezone($shop->timezone)->format('Y-m-d H:i:s P'), $name, $e->currency, Money::decimal($e->kind === 'reversal' ? -$e->amount_minor : $e->amount_minor, $e->price_decimals), $e->kind === 'reversal' ? ($e->stock_returned ? 'yes' : 'no') : '', $e->reason ?? ''];
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r\n]/', ltrim($v)) ? "'".$v : $v, $values), ',', '"', '');
            }
        } catch (\Throwable $error) {
            fclose($out);
            throw $error;
        }

        return response()->streamDownload(function () use ($out) {
            try {
                rewind($out);
                fpassthru($out);
            } finally {
                fclose($out);
            }
        }, 'sales-'.$d['from'].'-'.$d['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
