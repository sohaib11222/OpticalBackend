<?php

namespace App\Services\Commerce;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\OpticalRecord;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\SaleEntry;
use App\Services\CustomerRegistry;
use Illuminate\Support\Facades\DB;

class Orders
{
    public function details(Order $o): Order
    {
        return $o->load(['items', 'customer:id,first_name,last_name,status', 'sales.actor:id,name']);
    }

    private function prescription(int $customer, ?int $id): void
    {
        if (! $id) {
            return;
        }
        $rx = OpticalRecord::where('customer_id', $customer)->where('kind', 'prescription')->where('status', 'finalized')->findOrFail($id);
        abort_if($rx->amendment()->where('status', 'finalized')->exists(), 409, 'Select the current finalized prescription. Cancel an unfulfilled order if its prescription needs replacing.');
    }

    public function save(array $d, int $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($d, $actor, $id) {
            $shop = ShopLock::acquire();
            abort_unless($shop->currency, 409, 'Set the shop currency before creating orders.');
            $items = collect($d['items'])->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity']])->sortBy('product_id')->values()->all();
            $payload = ['customer_id' => (int) $d['customer_id'], 'prescription_id' => empty($d['prescription_id']) ? null : (int) $d['prescription_id'], 'notes' => $d['notes'] ?? null, 'items' => $items];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if (! $id && ($old = Order::where('creation_key', $d['creation_key'])->first())) {
                abort_unless(hash_equals($old->creation_hash, $hash), 409, 'This order request key was used with different details.');

                return [$this->details($old), false];
            }
            $o = $id ? Order::lockForUpdate()->findOrFail($id) : new Order;
            if ($id) {
                abort_unless($o->status === 'draft' && $o->version === (int) $d['version'], 409, 'Only the current draft can be edited. Refresh to review the order.');
            }
            $customer = Customer::lockForUpdate()->findOrFail($payload['customer_id']);
            abort_unless($customer->status === 'active', 409, 'Select an active customer.');
            $this->prescription($customer->id, $payload['prescription_id']);
            $oldCustomer = $o->customer_id;
            if ($id && $oldCustomer !== $customer->id) {
                abort_if(Appointment::where('order_id', $id)->exists(), 409, 'This order is linked to appointments. Unlink editable appointments or create another draft for a different customer.');
            }
            $lines = [];
            $total = 0;
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                abort_unless($product->status === 'active', 422, 'Only active products can be added to an order.');
                abort_unless($product->currency === $shop->currency, 409, 'Product currency does not match the shop.');
                $lineTotal = $product->price_minor * $item['quantity'];
                $total += $lineTotal;
                abort_if($lineTotal > Money::MAX_AMOUNT || $total > Money::MAX_AMOUNT, 422, 'Order amount exceeds the supported limit.');
                $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'brand' => $product->brand, 'model' => $product->model, 'color' => $product->color, 'quantity' => $item['quantity'], 'unit_price_minor' => $product->price_minor, 'total_minor' => $lineTotal];
            }
            $o->fill(['customer_id' => $customer->id, 'prescription_id' => $payload['prescription_id'], 'notes' => $payload['notes'], 'currency' => $shop->currency, 'price_decimals' => Money::precision($shop->currency), 'total_minor' => $total, 'customer_snapshot' => $customer->only(['first_name', 'last_name', 'email', 'phone', 'street_address', 'city', 'postal_code'])]);
            if ($id) {
                $o->version++;
            } else {
                $shop->order_sequence = (int) $shop->order_sequence + 1;
                $shop->save();
                $o->number = 'ORD-'.str_pad((string) $shop->order_sequence, 6, '0', STR_PAD_LEFT);
                $o->creation_key = $d['creation_key'];
                $o->creation_hash = $hash;
                $o->created_by = $actor;
            }
            $o->save();
            $o->refresh();
            if ($id) {
                $o->items()->delete();
            }foreach ($lines as $line) {
                $o->items()->create($line);
            }
            $this->event($o, $id ? 'updated' : 'created', $actor, $id ? 'draft' : null);
            if ($oldCustomer && $oldCustomer !== $customer->id) {
                app(CustomerRegistry::class)->event(Customer::findOrFail($oldCustomer), 'order.unlinked', $actor, [], null, 'order', $o->id);
            }

            return [$this->details($o), true];
        }, 3);
    }

    public function transition(int $id, string $action, array $d, int $actor): Order
    {
        return DB::transaction(function () use ($id, $action, $d, $actor) {
            ShopLock::acquire();
            $o = Order::lockForUpdate()->findOrFail($id);
            $hash = hash('sha256', json_encode([$id, $action, (int) $d['version'], $d['reason'] ?? null, $d['return_stock'] ?? null], JSON_THROW_ON_ERROR));
            if ($event = OrderEvent::where('request_key', $d['creation_key'])->first()) {
                abort_unless(hash_equals($event->request_hash, $hash), 409, 'This transition key was used for different details.');

                return $this->details($o);
            }
            abort_unless($o->version === (int) $d['version'], 409, 'Order changed. Refresh before continuing.');
            $from = $o->status;
            $inventory = app(Inventory::class);
            $lines = $o->items()->orderBy('product_id')->get();
            if (in_array($action, ['confirm', 'fulfil'])) {
                abort_unless($o->status === ($action === 'confirm' ? 'draft' : 'confirmed'), 409, 'This order cannot be '.$action.'ed from its current status.');
                $c = Customer::lockForUpdate()->findOrFail($o->customer_id);
                abort_unless($c->status === 'active', 409, 'Restore this customer before confirming or fulfilling an order.');
                $this->prescription($c->id, $o->prescription_id);
            }
            switch ($action) {
                case 'confirm':
                    foreach ($lines as $line) {
                        $p = Product::findOrFail($line->product_id);
                        abort_unless($p->status === 'active', 409, 'All order products must be active before confirmation.');
                        $inventory->move($p, 'reservation', 0, $line->quantity, 'order:'.$id.':reserve:'.$line->id, 'Order '.$o->number.' confirmed', $actor, 'order', $id);
                    }
                    $o->customer_snapshot = $c->only(['first_name', 'last_name', 'email', 'phone', 'street_address', 'city', 'postal_code']);
                    $o->status = 'confirmed';
                    $o->confirmed_at = now();
                    break;
                case 'fulfil':
                    foreach ($lines as $line) {
                        $inventory->move(Product::findOrFail($line->product_id), 'fulfilment', -$line->quantity, -$line->quantity, 'order:'.$id.':fulfil:'.$line->id, 'Order '.$o->number.' fulfilled', $actor, 'order', $id);
                    }
                    $o->status = 'fulfilled';
                    $o->fulfilled_at = now();
                    SaleEntry::create(['order_id' => $id, 'customer_id' => $o->customer_id, 'kind' => 'sale', 'currency' => $o->currency, 'price_decimals' => $o->price_decimals, 'amount_minor' => $o->total_minor, 'actor_id' => $actor]);
                    break;
                case 'cancel':
                    abort_unless(in_array($o->status, ['draft', 'confirmed']), 409, 'Only draft or confirmed orders can be cancelled. Reverse fulfilled sales instead.');
                    if ($o->status === 'confirmed') {
                        foreach ($lines as $line) {
                            $inventory->move(Product::findOrFail($line->product_id), 'release', 0, -$line->quantity, 'order:'.$id.':cancel:'.$line->id, 'Cancelled '.$o->number.': '.$d['reason'], $actor, 'order', $id);
                        }
                    }
                    $o->status = 'cancelled';
                    $o->cancelled_at = now();
                    break;
                case 'reverse':
                    abort_unless($o->status === 'fulfilled', 409, 'Only fulfilled sales can be reversed.');
                    $sale = SaleEntry::where('order_id', $id)->where('kind', 'sale')->firstOrFail();
                    if ($d['return_stock']) {
                        foreach ($lines as $line) {
                            $inventory->move(Product::findOrFail($line->product_id), 'sale_reversal', $line->quantity, 0, 'order:'.$id.':reverse:'.$line->id, 'Sale reversal '.$o->number.': '.$d['reason'], $actor, 'order', $id);
                        }
                    }
                    SaleEntry::create(['order_id' => $id, 'customer_id' => $o->customer_id, 'kind' => 'reversal', 'currency' => $o->currency, 'price_decimals' => $o->price_decimals, 'amount_minor' => $sale->amount_minor, 'reverses_id' => $sale->id, 'stock_returned' => $d['return_stock'], 'reason' => $d['reason'], 'actor_id' => $actor]);
                    $o->status = 'reversed';
                    $o->reversed_at = now();
                    break;
                default:throw new \LogicException('Unsupported order action.');
            }
            $o->version++;
            $o->save();
            $this->event($o, $action, $actor, $from, $d['reason'] ?? null, $d['creation_key'], $hash, $action === 'reverse' ? $d['return_stock'] : null);
            app(Notices::class)->emit('orders', 'orders.read', 'order:'.$id.':'.$action, 'order.'.$action, $o->number.' '.$o->status, '/orders/'.$id);

            return $this->details($o);
        }, 3);
    }

    private function event(Order $o, string $action, int $actor, ?string $from, ?string $reason = null, ?string $key = null, ?string $hash = null, ?bool $stock = null): void
    {
        OrderEvent::create(['order_id' => $o->id, 'action' => $action, 'from_status' => $from, 'to_status' => $o->status, 'version' => $o->version, 'reason' => $reason, 'request_key' => $key, 'request_hash' => $hash, 'stock_returned' => $stock, 'actor_id' => $actor]);
        app(CustomerRegistry::class)->event($o->customer, 'order.'.$action, $actor, [], $reason, 'order', $o->id);
    }
}
