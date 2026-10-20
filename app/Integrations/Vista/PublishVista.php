<?php

namespace App\Integrations\Vista;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\VistaConnection;
use App\Models\VistaOutbox;
use App\Models\VistaPublication;
use App\Services\Audit;
use App\Services\Commerce\Inventory;
use App\Services\Commerce\Money;
use App\Services\Commerce\ShopLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublishVista
{
    public function prepare(int $productId, array $d, int $actor): VistaOutbox
    {
        return DB::transaction(function () use ($productId, $d, $actor) {
            ShopLock::acquire();
            $hash = hash('sha256', json_encode([$productId, $d], JSON_THROW_ON_ERROR));
            if ($old = VistaOutbox::where('operation_key', $d['creation_key'])->first()) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Publication key already used with different details.');

                return $old;
            }
            $c = VistaConnection::firstOrFail();
            abort_unless(in_array($c->status, ['connected', 'syncing']) && $c->expires_at->isFuture(), 409, 'Connect Vista Express before publishing.');
            $p = Product::with('images', 'balance')->findOrFail($productId);
            abort_unless($p->version === $d['version'], 409, 'Product changed. Refresh before publishing.');
            abort_unless($p->status === 'active' && $p->currency === 'EUR', 422, 'Publish an active product priced in EUR. Currency conversion is not automatic.');
            $pub = VistaPublication::firstOrCreate(['vista_connection_id' => $c->id, 'product_id' => $p->id], ['external_key' => Str::uuid()->toString()]);
            abort_if(VistaOutbox::where('vista_publication_id', $pub->id)->whereIn('status', ['pending', 'processing', 'uncertain'])->exists(), 409, 'Resolve the previous publication before creating another allocation.');
            if ($pub->remote_id) {
                abort_unless(($d['expected_version'] ?? null) === $pub->remote_version, 409, 'Refresh the Vista publication and review its current version.');
            }
            $images = $p->images->where('scan_status', 'clean')->whereNull('archived_at')->sortBy('id')->values();
            abort_if($images->isEmpty() || $images->count() > 100, 422, 'Choose between one and one hundred clean active product photos.');
            $imageMap = $images->map(fn ($i) => ['local_id' => $i->id, 'remote_key' => Str::uuid()->toString()])->all();
            $mapping = $d['mapping'];
            abort_if(array_key_exists('gallery_image_ids', $mapping) && empty($mapping['gallery_image_ids']), 422, 'Select at least one clean main product photograph.');
            array_walk_recursive($mapping, function ($value, $key) use ($images) {
                if ($key === 'image_index') {
                    abort_unless(is_numeric($value) && $value >= 1 && $value <= $images->count(), 422, 'Choose an available clean product photo number.');
                }
            });
            foreach ($mapping['variants'] ?? [] as $variant) {
                foreach ($variant['image_indices'] ?? [] as $index) {
                    abort_unless(is_numeric($index) && $index >= 1 && $index <= $images->count(), 422, 'Choose available clean product photo numbers.');
                }
            }
            $remoteImages = array_column($imageMap, 'remote_key', 'local_id');
            $replaceImages = function (array $value) use (&$replaceImages, $remoteImages): array {
                foreach ($value as $key => &$item) {
                    if (in_array($key, ['image_ids', 'gallery_image_ids'], true) && is_array($item)) {
                        $item = array_map(function ($id) use ($remoteImages) {
                            abort_unless(isset($remoteImages[$id]), 422, 'Select clean photos belonging to this product.');
                            return $remoteImages[$id];
                        }, $item);
                    } elseif (is_array($item)) {
                        $item = $replaceImages($item);
                    }
                }
                return $value;
            };
            $remoteMapping = $replaceImages($mapping);
            $payload = ['external_key' => $pub->external_key, 'operation_key' => $d['creation_key'], 'allocated_quantity' => $d['quantity'], 'currency' => 'EUR', 'expected_version' => $pub->remote_version,
                'product' => ['name' => $p->name, 'sku' => $p->sku, 'price' => Money::decimal($p->price_minor, $p->price_decimals), 'description' => $p->description, ...$remoteMapping],
                'images' => array_column($imageMap, 'remote_key')];
            $op = VistaOutbox::create(['vista_publication_id' => $pub->id, 'actor_id' => $actor, 'operation_key' => $d['creation_key'], 'request_hash' => $hash, 'payload' => $payload, 'image_map' => $imageMap, 'allocated_quantity' => $d['quantity'], 'next_attempt_at' => now()]);
            if ($d['quantity'] > 0) {
                app(Inventory::class)->move($p, 'vista_reservation', 0, $d['quantity'], 'vista:'.$op->operation_key.':reserve', 'Reserved for Vista Express allocation', $actor, 'vista_outbox', $op->id);
            }
            $pub->mapping = $mapping;
            $pub->save();
            Audit::record('vista.publication_queued', $p->shop_id, $actor, 'vista_outbox', $op->id);

            return $op;
        });
    }

    public function run(VistaOutbox $op): void
    {
        $op = DB::transaction(function () use ($op) {
            ShopLock::acquire();
            $fresh = VistaOutbox::lockForUpdate()->findOrFail($op->id);
            if (in_array($fresh->status, ['completed', 'rejected'])) {
                return null;
            }
            $fresh->status = 'processing';
            $fresh->attempts++;
            $fresh->save();

            return $fresh;
        });
        if (! $op) {
            return;
        }
        $pub = VistaPublication::findOrFail($op->vista_publication_id);
        $c = VistaConnection::findOrFail($pub->vista_connection_id);
        $client = app(VistaClient::class);
        try {
            foreach ($op->image_map as $map) {
                $i = ProductImage::findOrFail($map['local_id']);
                abort_unless($i->scan_status === 'clean' && $i->display_key && str_starts_with($i->display_key, 'shops/'.$op->shop_id.'/catalog/display/') && ! str_contains($i->display_key, '..'), 409, 'Processed image is unavailable.');
                $client->send('POST', 'images', ['image_key' => $map['remote_key']], $c, ['bytes' => Storage::disk('private')->get($i->display_key), 'filename' => $i->mime === 'image/png' ? 'product.png' : 'product.jpg']);
            }
            $result = $client->send('POST', 'publications', $op->payload, $c)['data'] ?? [];
            abort_unless(($result['external_key'] ?? null) === $pub->external_key && is_numeric($result['product_id'] ?? null) && is_string($result['version'] ?? null) && strlen($result['version']) === 64 && (! $pub->remote_id || (string) $result['product_id'] === $pub->remote_id), 502, 'Invalid Vista publication response.');
            DB::transaction(function () use ($op, $pub, $result) {
                ShopLock::acquire();
                $fresh = VistaOutbox::lockForUpdate()->findOrFail($op->id);
                if (in_array($fresh->status, ['completed', 'rejected'])) {
                    return;
                }
                if ($fresh->allocated_quantity > 0) {
                    app(Inventory::class)->move(Product::findOrFail($pub->product_id), 'vista_allocation', -$fresh->allocated_quantity, -$fresh->allocated_quantity, 'vista:'.$fresh->operation_key.':allocate', 'Transferred allocated stock to Vista Express', $fresh->actor_id, 'vista_outbox', $fresh->id);
                }
                $pub->forceFill(['remote_id' => (string) $result['product_id'], 'remote_version' => $result['version'], 'visible' => (bool) ($result['visible'] ?? false)])->save();
                $fresh->forceFill(['status' => 'completed', 'last_error' => null, 'next_attempt_at' => null])->save();
                Audit::record('vista.published', $op->shop_id, $op->actor_id, 'vista_publication', $pub->id);
            });
        } catch (\Throwable $e) {
            // Only the provider's atomic publication rejection proves that no stock was accepted.
            // Transport failures, auth loss and local failures retain reservations for reconciliation.
            VistaOutbox::whereKey($op->id)->whereNotIn('status', ['completed', 'rejected'])->update(['status' => 'uncertain', 'last_error' => $e instanceof VistaFailure ? $e->getMessage() : 'Publication could not be confirmed. Retry the same operation; allocated stock remains reserved.', 'next_attempt_at' => now()->addMinutes(min(60, 2 ** min($op->attempts, 5)))]);
            throw new \RuntimeException('Vista publication unconfirmed; retry the saved operation.');
        }
    }
}
