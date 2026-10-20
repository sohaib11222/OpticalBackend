<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

class Inventory
{
    public function balance(Product $p): StockBalance
    {
        $location = StockLocation::firstOrCreate([], ['name' => 'Main stock']);

        return StockBalance::firstOrCreate(['product_id' => $p->id], ['location_id' => $location->id])->refresh();
    }

    // Caller holds the shop lock inside a transaction. Every balance change has one immutable movement.
    public function move(Product $p, string $kind, int $quantity, int $reserved, string $key, string $reason, int $actor, ?string $subject = null, ?int $subjectId = null, ?int $reverses = null): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Inventory changes require a transaction.');
        }
        $hash = hash('sha256', json_encode([$p->id, $kind, $quantity, $reserved, $reason, $subject, $subjectId, $reverses], JSON_THROW_ON_ERROR));
        if ($old = StockMovement::where('request_key', $key)->first()) {
            abort_unless(hash_equals($old->request_hash, $hash), 409, 'This stock request key was used with different details.');

            return $old;
        }
        $b = StockBalance::where('product_id', $p->id)->lockForUpdate()->firstOrFail();
        $onHand = $b->on_hand + $quantity;
        $held = $b->reserved + $reserved;
        abort_unless($onHand >= 0 && $held >= 0 && $held <= $onHand, 409, 'Insufficient available stock or an invalid reservation adjustment.');
        abort_if($onHand > 1000000000, 422, 'Stock exceeds the supported quantity limit.');
        $b->on_hand = $onHand;
        $b->reserved = $held;
        $b->version++;
        $b->save();
        $movement = StockMovement::create(['product_id' => $p->id, 'location_id' => $b->location_id, 'kind' => $kind, 'quantity_delta' => $quantity, 'reserved_delta' => $reserved, 'on_hand_after' => $onHand, 'reserved_after' => $held, 'request_key' => $key, 'request_hash' => $hash, 'reason' => $reason, 'actor_id' => $actor, 'subject_type' => $subject, 'subject_id' => $subjectId, 'reverses_id' => $reverses]);
        Audit::record('stock.'.$kind, $p->shop_id, $actor, 'stock_movement', $movement->id);
        $this->lowStock($p, $b);

        return $movement;
    }

    public function lowStock(Product $p, ?StockBalance $b = null): void
    {
        $b = $b ?: StockBalance::where('product_id', $p->id)->firstOrFail();
        $low = $p->status === 'active' && $b->available <= $p->low_stock_threshold;
        if ($low && ! $b->low_notified) {
            app(Notices::class)->emit('stock', 'stock.read', 'stock.low:'.$p->id.':'.$b->version.':'.$p->version, 'stock.low', 'Low stock: '.$p->sku, '/products/'.$p->id);
        }
        if ($b->low_notified !== $low) {
            $b->low_notified = $low;
            $b->save();
        }
    }

    public function manual(int $product, array $d, int $actor): StockMovement
    {
        return DB::transaction(function () use ($product, $d, $actor) {
            ShopLock::acquire();
            $p = Product::findOrFail($product);
            $key = 'manual:'.$d['creation_key'];
            if ($old = StockMovement::where('request_key', $key)->first()) {
                abort_unless($old->product_id === $product && $old->kind === $d['kind'] && $old->quantity_delta === $d['quantity'] && $old->reason === $d['reason'], 409, 'This request was already used for a different stock change.');

                return $old;
            }
            $b = StockBalance::where('product_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($b->version === (int) $d['version'], 409, 'Stock changed. Refresh quantities before recording this movement.');
            abort_if($d['kind'] === 'receipt' && ($d['quantity'] <= 0 || $p->status === 'archived'), 422, 'Receipts require a positive quantity and a current product.');
            abort_if($d['quantity'] === 0, 422, 'Enter a non-zero stock adjustment.');

            return $this->move($p, $d['kind'], $d['quantity'], 0, $key, $d['reason'], $actor);
        }, 3);
    }

    public function reverse(int $id, array $d, int $actor): StockMovement
    {
        return DB::transaction(function () use ($id, $d, $actor) {
            ShopLock::acquire();
            $original = StockMovement::findOrFail($id);
            abort_unless(in_array($original->kind, ['receipt', 'adjustment']), 422, 'Only manual receipts and adjustments can be reversed here. Reverse sales through their order.');
            if ($old = $original->reversal()->first()) {
                abort_unless($old->request_key === 'reverse:'.$d['creation_key'] && $old->reason === $d['reason'], 409, 'This movement has already been reversed.');

                return $old;
            }
            $p = Product::findOrFail($original->product_id);
            $b = StockBalance::where('product_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($b->version === (int) $d['version'], 409, 'Stock changed. Refresh before reversing.');

            return $this->move($p, 'adjustment_reversal', -$original->quantity_delta, 0, 'reverse:'.$d['creation_key'], $d['reason'], $actor, 'stock_movement', $original->id, $original->id);
        }, 3);
    }
}
