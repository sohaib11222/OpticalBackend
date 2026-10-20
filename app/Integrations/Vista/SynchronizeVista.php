<?php

namespace App\Integrations\Vista;

use App\Models\VistaConnection;
use App\Models\VistaRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SynchronizeVista
{
    public function run(VistaConnection $connection): void
    {
        $client = app(VistaClient::class);
        $identity = $client->send('GET', 'identity', [], $connection)['data'] ?? [];
        abort_unless(($identity['subject'] ?? null) === $connection->subject && ($identity['store']['id'] ?? null) === $connection->store_id, 403, 'Vista identity mismatch.');
        $checkpoint = $connection->checkpoint ?: ['kind' => 'products', 'after_id' => 0, 'through_id' => null];
        $run = $connection->sync_run ?: Str::uuid()->toString();
        DB::transaction(function () use ($connection, $run, $checkpoint) {
            $this->checkConnection($connection);
            $connection->forceFill(['sync_run' => $run, 'checkpoint' => $checkpoint, 'status' => 'syncing', 'last_error' => null])->save();
        });
        // One bounded page per queue job; checkpoints survive process interruption.
        $response = $client->send('GET', $checkpoint['kind'], array_filter(['after_id' => $checkpoint['after_id'], 'through_id' => $checkpoint['through_id']], fn ($v) => $v !== null), $connection);
        abort_unless(is_array($response['data'] ?? null) && count($response['data']) <= 100 && is_numeric($response['through_id'] ?? null), 502, 'Invalid Vista data page.');
        $allowed = match ($checkpoint['kind']) {
            'products' => ['id', 'name', 'sku', 'product_type', 'category_id', 'description', 'price', 'stock_quantity', 'stock_status', 'images', 'is_active', 'is_approved', 'is_muted', 'frame_shape', 'frame_material', 'frame_color', 'gender', 'updated_at', 'deleted_at', 'currency', 'visible', 'variants', 'sizes'],
            'orders' => ['id', 'status', 'subtotal', 'delivery_fee', 'total', 'payment_status', 'created_at', 'updated_at', 'currency', 'order_number', 'customer_id', 'items'],
            'customers' => ['id', 'name', 'email', 'phone', 'updated_at'],
        };
        DB::transaction(function () use ($connection, $checkpoint, $run, $response, $allowed) {
            $this->checkConnection($connection);
            foreach ($response['data'] as $row) {
                abort_unless(is_array($row) && is_numeric($row['id'] ?? null) && $row['id'] > $checkpoint['after_id'] && $row['id'] <= $response['through_id'], 502, 'Invalid remote record identity.');
                $payload = array_intersect_key($row, array_flip($allowed));
                if (isset($payload['items'])) {
                    $payload['items'] = array_map(fn ($item) => array_intersect_key($item, array_flip(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_total', 'product_name', 'product_sku'])), $payload['items']);
                }
                VistaRecord::updateOrCreate(['vista_connection_id' => $connection->id, 'kind' => $checkpoint['kind'], 'remote_id' => (string) $row['id']], ['payload' => $payload, 'seen_run' => $run, 'observed_at' => now(), 'unavailable' => ! empty($row['deleted_at'])]);
            }
            $next = $response['next_id'] ?? null;
            if ($next !== null) {
                abort_unless(is_numeric($next) && $next > $checkpoint['after_id'] && $next <= $response['through_id'], 502, 'Invalid Vista checkpoint.');
                $checkpoint['after_id'] = (int) $next;
                $checkpoint['through_id'] = (int) $response['through_id'];
            } else {
                VistaRecord::where('vista_connection_id', $connection->id)->where('kind', $checkpoint['kind'])->where('seen_run', '!=', $run)->update(['unavailable' => true]);
                $nextKind = match ($checkpoint['kind']) {
                    'products' => 'orders','orders' => 'customers',default => null
                };
                $checkpoint = $nextKind ? ['kind' => $nextKind, 'after_id' => 0, 'through_id' => null] : null;
            }
            $connection->checkpoint = $checkpoint;
            $connection->status = $checkpoint ? 'syncing' : 'connected';
            if (! $checkpoint) {
                $connection->synced_at = now();
                $connection->sync_run = null;
                $connection->next_sync_at = now()->addSeconds(config('vista.sync_interval_seconds', 120));
            }$connection->save();
        });
    }

    private function checkConnection(VistaConnection $connection): void
    {
        $current = VistaConnection::lockForUpdate()->findOrFail($connection->id);
        abort_unless(! in_array($current->status, ['disconnected', 'requires_reconnect']) && $current->expires_at->isFuture() && hash_equals((string) $current->access_token, (string) $connection->access_token), 409, 'Vista authorization changed during synchronization.');
    }
}
