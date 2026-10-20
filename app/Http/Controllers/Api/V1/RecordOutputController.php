<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\OpticalRecord;
use App\Models\Order;
use App\Models\PrivateFile;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockBalance;
use App\Services\Audit;
use App\Services\Commerce\Money;
use App\Services\Operations\Access;
use App\Services\Operations\Payments;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Bounded, explicit output projections; never exports private file bytes or credentials. */
class RecordOutputController extends Controller
{
    public function output(Request $r, string $module)
    {
        $d = $r->validate(['purpose' => 'required|in:export,print', 'customer_id' => 'nullable|integer|min:1', 'record_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        Access::require($module.'.'.$d['purpose']);
        Access::require($module === 'files' ? 'files.read' : ($module === 'audit' ? 'audit.read' : $module.'.read'));
        [$query, $fields, $date] = match ($module) {
            'customers' => [Customer::query(), ['id', 'first_name', 'last_name', 'date_of_birth', 'phone', 'email', 'city', 'status'], 'created_at'],
            'products' => [Product::query(), ['id', 'sku', 'name', 'brand', 'model', 'color', 'price_minor', 'currency', 'status'], 'created_at'],
            'stock' => [StockBalance::query()->with('product:id,sku,name'), ['product_id', 'on_hand', 'reserved'], 'updated_at'],
            'orders' => [Order::query(), ['id', 'number', 'customer_id', 'status', 'total_minor', 'currency', 'created_at', 'fulfilled_at'], 'created_at'],
            'agenda' => [Appointment::query(), ['id', 'customer_id', 'title', 'starts_at', 'ends_at', 'timezone', 'status'], 'starts_at'],
            'optical' => [OpticalRecord::query(), ['id', 'customer_id', 'kind', 'status', 'recorded_on', 'practitioner', 'revision', 'data'], 'recorded_on'],
            'files' => [PrivateFile::query(), ['id', 'filename', 'mime', 'size', 'scan_status', 'created_at'], 'created_at'],
            'payments' => [Receivable::query(), ['id', 'customer_id', 'reference', 'currency', 'amount_minor', 'due_on', 'status'], 'due_on'],
            'audit' => [AuditEvent::where('shop_id', app(TenantContext::class)->id())->with('actor:id,name'), ['id', 'actor_id', 'action', 'subject_type', 'subject_id', 'created_at'], 'created_at'],
            default => abort(404),
        };
        if (! empty($d['customer_id'])) {
            Customer::findOrFail($d['customer_id']);
            abort_unless(in_array($module, ['customers', 'orders', 'agenda', 'optical', 'payments']), 422, 'This output does not support customer filtering.');
            $query->where($module === 'customers' ? 'id' : 'customer_id', $d['customer_id']);
        }
        if (! empty($d['record_id'])) {
            $query->where($module === 'stock' ? 'product_id' : 'id', $d['record_id']);
        }
        if (! empty($d['from'])) {
            $query->whereDate($date, '>=', $d['from']);
        }
        if (! empty($d['to'])) {
            $query->whereDate($date, '<=', $d['to']);
        }
        $query->orderBy($module === 'stock' ? 'product_id' : 'id');
        $records = $query->limit(5001)->get();
        abort_if($records->count() > 5000, 422, 'This output exceeds 5,000 records. Narrow the date or customer filter.');
        $headers = $fields;
        if ($module === 'stock') {
            $headers = [...$headers, 'sku', 'product_name', 'available'];
        }
        if ($module === 'payments') {
            $headers = [...$headers, 'paid_minor', 'balance_minor', 'refund_due_minor', 'effective_status'];
        }
        if ($module === 'audit') {
            $headers[] = 'actor_name';
        }
        $rows = $records->map(function ($record) use ($fields, $module) {
            $row = array_map(function ($field) use ($record) {
                $v = $record->getAttribute($field);

                return $v instanceof \DateTimeInterface ? $v->format($field === 'due_on' || $field === 'recorded_on' || $field === 'date_of_birth' ? 'Y-m-d' : DATE_ATOM) : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? ''));
            }, $fields);
            if ($module === 'stock') {
                $row = [...$row, $record->product?->sku, $record->product?->name, $record->on_hand - $record->reserved];
            }
            if ($module === 'payments') {
                $row = [...$row, ...array_values(app(Payments::class)->summary($record))];
            }
            if ($module === 'audit') {
                $row[] = $record->actor?->name;
            }

            return $row;
        });
        $ledger = $module === 'payments' && ! empty($d['record_id']) && $records->isNotEmpty() ? $records->first()->entries()->with('actor:id,name')->get()->map(fn ($e) => [$e->paid_on->toDateString(), $e->kind, $e->amount_minor, $e->method, $e->reference, $e->reason, $e->actor?->name])->all() : [];
        Audit::record($module.'.'.$d['purpose'], app(TenantContext::class)->id(), $r->user()->id);
        if ($d['purpose'] === 'print') {
            $formatMoney = fn ($value, $currency) => $currency.' '.Money::decimal((int) $value, Money::precision($currency));
            $rows = $rows->map(function ($row, $index) use ($records, $headers, $formatMoney) {
                foreach ($headers as $column => $name) {
                    if (str_ends_with($name, '_minor')) {
                        $row[$column] = $formatMoney($row[$column], $records[$index]->currency);
                    }
                }

                return $row;
            });
            foreach ($ledger as &$entry) {
                $entry[2] = $formatMoney($entry[2], $records->first()->currency);
            }
            unset($entry);
            $label = fn ($name) => ucfirst(str_replace('_', ' ', str_replace('_minor', '', $name)));

            return ['title' => $module, 'headers' => array_map($label, $headers), 'rows' => $rows, 'ledger' => $ledger, 'ledger_headers' => ['Paid on', 'Entry type', 'Amount', 'Method', 'Reference', 'Reason', 'Recorded by']];
        }
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($v) => preg_match('/^[=+\-@\t\r\n]/', ltrim((string) $v)) ? "'".$v : $v, $row), ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        return ['filename' => $module.'-'.now()->format('Y-m-d').'.csv', 'csv' => $csv];
    }
}
