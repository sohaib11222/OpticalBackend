<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Vista\PublicationFields;
use App\Integrations\Vista\PublishVista;
use App\Integrations\Vista\ScheduleVistaSync;
use App\Integrations\Vista\VistaClient;
use App\Jobs\PublishVistaProduct;
use App\Jobs\SyncVista;
use App\Models\CustomerImport;
use App\Models\Product;
use App\Models\VistaConnection;
use App\Models\VistaOutbox;
use App\Models\VistaPublication;
use App\Models\VistaRecord;
use App\Services\Audit;
use App\Services\Commerce\Inventory;
use App\Services\Commerce\ShopLock;
use App\Services\Imports\CustomerImports;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VistaController extends Controller
{
    private function permit(string $p): void
    {
        abort_unless(app(TenantContext::class)->membership()->can($p), 403);
    }

    public function importCustomers(Request $r)
    {
        foreach (['integrations.read', 'imports.write', 'customers.write'] as $p) {
            $this->permit($p);
        }
        $d = $r->validate(['rows' => 'required|array|min:1|max:100', 'rows.*.id' => 'required|integer|min:1|distinct', 'rows.*.first_name' => 'required|string|max:100', 'rows.*.last_name' => 'required|string|max:100']);
        $c = VistaConnection::firstOrFail();
        abort_if($c->status === 'disconnected', 409, 'Reconnect first.');
        $b = DB::transaction(function () use ($d, $c, $r) {
            ShopLock::acquire();
            $rows = [];
            foreach ($d['rows'] as $choice) {
                $record = VistaRecord::where('vista_connection_id', $c->id)->where('kind', 'customers')->where('unavailable', false)->findOrFail($choice['id']);
                $rows[] = ['remote_id' => $record->remote_id, 'values' => [$choice['first_name'], $choice['last_name'], $record->payload['email'] ?? '', $record->payload['phone'] ?? '']];
            }
            $hash = hash('sha256', 'vista:'.$c->id.':'.json_encode($rows, JSON_THROW_ON_ERROR));
            if ($old = CustomerImport::where('source_hash', $hash)->first()) {
                return $old;
            }
            $b = CustomerImport::create(['file_id' => null, 'source_kind' => 'vista_express', 'vista_connection_id' => $c->id, 'source_hash' => $hash, 'headers' => ['First name', 'Last name', 'Email', 'Phone'], 'mapping' => ['first_name' => 0, 'last_name' => 1, 'email' => 2, 'phone' => 3], 'date_format' => 'Y-m-d', 'delimiter' => ',', 'total_rows' => count($rows), 'created_by' => $r->user()->id, 'status' => 'previewing', 'stage' => 'preview']);
            foreach ($rows as $i => $row) {
                $b->rows()->create(['row_number' => $i + 1, 'creation_key' => Str::uuid()->toString(), 'raw_values' => $row['values'], 'parse_errors' => []]);
            }
            app(CustomerImports::class)->preview($b);
            Audit::record('vista.customer_preview', $c->shop_id, $r->user()->id, 'customer_import', $b->id);

            return $b;
        });

        return response()->json(['data' => $b], 201);
    }

    public function show()
    {
        $this->permit('integrations.read');
        if ($connection = VistaConnection::first()) {
            app(ScheduleVistaSync::class)->dispatch($connection);
        }

        return ['data' => ['enabled' => (bool) config('vista.enabled'), 'connection' => VistaConnection::first(), 'stock_policy' => 'allocated', 'source' => 'vista_express']];
    }

    public function metadata(VistaClient $client)
    {
        $this->permit('integrations.publish');

        return $client->send('GET', 'catalog-metadata', [], VistaConnection::firstOrFail());
    }

    public function sync(Request $r)
    {
        $this->permit('integrations.write');
        $c = VistaConnection::firstOrFail();
        abort_if($c->status === 'disconnected', 409, 'Reconnect first.');
        try {
            SyncVista::dispatch($c->shop_id, $r->user()->id, $c->id);
        } catch (\Throwable) {
            $c->forceFill(['status' => 'error', 'last_error' => 'Could not finish synchronization. Retry after checking the worker.'])->save();
        }

        return response()->json(['data' => $c->refresh()], 202);
    }

    public function disconnect(Request $r, VistaClient $client)
    {
        $this->permit('integrations.write');
        $c = VistaConnection::firstOrFail();
        abort_if(VistaOutbox::whereIn('status', ['pending', 'processing', 'uncertain'])->exists(), 409, 'Resolve pending publications before disconnecting; their stock remains allocated.');
        $warning = null;
        try {
            $client->send('POST', 'revoke', [], $c);
        } catch (\Throwable) {
            $warning = 'Disconnected locally. Remote revocation could not be confirmed; the existing grant expires at its recorded expiry.';
        }
        $c->forceFill(['status' => 'disconnected', 'access_token' => null, 'last_error' => $warning, 'checkpoint' => null])->save();
        Audit::record('vista.disconnected', $c->shop_id, $r->user()->id, 'vista_connection', $c->id);

        return ['data' => $c];
    }

    public function records(Request $r, string $kind)
    {
        $this->permit('integrations.read');
        $this->permit(match ($kind) {
            'products' => 'products.read','orders' => 'orders.read','customers' => 'customers.read'
        });
        $d = $r->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100']);
        $q = VistaRecord::where('kind', $kind)->where('unavailable', false);
        // IDs are stable; source payload remains separate from standalone entities and sales totals.
        if (! empty($d['search'])) {
            $q->whereRaw("payload LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $d['search']).'%']);
        }

        return $q->orderByDesc('id')->paginate(20);
    }

    public function publication(int $product, VistaClient $client)
    {
        $this->permit('integrations.read');
        $this->permit('products.read');
        Product::findOrFail($product);
        $pub = VistaPublication::where('product_id', $product)->first();

        return ['data' => $pub, 'operations' => $pub ? VistaOutbox::where('vista_publication_id', $pub->id)->orderByDesc('id')->limit(20)->get() : []];
    }

    public function refreshPublication(int $product, VistaClient $client)
    {
        $this->permit('integrations.publish');
        $pub = VistaPublication::where('product_id', $product)->firstOrFail();
        $remote = $client->send('GET', 'publications/'.$pub->external_key, [], VistaConnection::findOrFail($pub->vista_connection_id))['data'];
        abort_unless((string) $remote['product_id'] === $pub->remote_id, 409, 'Unexpected provider product identity.');
        $pub->forceFill(['remote_version' => $remote['version'], 'visible' => $remote['visible']])->save();

        return ['data' => $pub];
    }

    public function publish(Request $r, PublishVista $service, int $product)
    {
        foreach (['integrations.publish', 'products.write', 'stock.write'] as $p) {
            $this->permit($p);
        }
        $d = $r->validate(['version' => 'required|integer|min:1', 'quantity' => 'required|integer|min:0|max:1000000', 'creation_key' => 'required|uuid', 'expected_version' => 'nullable|string|size:64', 'mapping' => 'required|array']);
        $d['mapping'] = app(PublicationFields::class)->validate($d['mapping'], $d['quantity'], VistaPublication::where('product_id', $product)->value('mapping') ?? []);
        $d['version'] = (int) $d['version'];
        $d['quantity'] = (int) $d['quantity'];
        $op = $service->prepare($product, $d, $r->user()->id);
        $this->dispatch($op);

        return response()->json(['data' => $op->refresh()], 202);
    }

    private function dispatch(VistaOutbox $op): void
    {
        try {
            PublishVistaProduct::dispatch($op->shop_id, $op->actor_id, $op->id);
        } catch (\Throwable) {/* Durable outbox remains available for scheduler and explicit retry. */
        }
    }

    public function retry(Request $r, int $operation)
    {
        $this->permit('integrations.publish');
        $this->permit('stock.write');
        $op = VistaOutbox::findOrFail($operation);
        abort_unless(in_array($op->status, ['pending', 'uncertain']), 409, 'This operation cannot be retried.');
        $op->actor_id = $r->user()->id;
        $op->save();
        $this->dispatch($op);

        return response()->json(['data' => $op->refresh()], 202);
    }

    public function cancel(Request $r, VistaClient $client, int $operation)
    {
        $this->permit('integrations.publish');
        $this->permit('stock.write');
        $op = VistaOutbox::findOrFail($operation);
        abort_unless(in_array($op->status, ['pending', 'uncertain']), 409, 'This publication is not cancellable.');
        $pub = VistaPublication::findOrFail($op->vista_publication_id);
        $result = $client->send('POST', 'operations/'.$op->operation_key.'/cancel', [], VistaConnection::findOrFail($pub->vista_connection_id));
        abort_unless(($result['data']['cancelled'] ?? false) === true && ($result['data']['operation_key'] ?? null) === $op->operation_key, 502);
        DB::transaction(function () use ($op, $pub, $r) {
            ShopLock::acquire();
            $fresh = VistaOutbox::lockForUpdate()->findOrFail($op->id);
            if ($fresh->status === 'rejected') {
                return;
            }abort_if($fresh->status === 'completed', 409);
            if ($fresh->allocated_quantity > 0) {
                app(Inventory::class)->move(Product::findOrFail($pub->product_id), 'vista_release', 0, -$fresh->allocated_quantity, 'vista:'.$fresh->operation_key.':release', 'Vista confirmed cancellation; allocation released', $r->user()->id, 'vista_outbox', $fresh->id);
            }
            $fresh->forceFill(['status' => 'rejected', 'last_error' => 'Cancelled before Vista accepted the publication. Stock reservation released.', 'next_attempt_at' => null])->save();
        });

        return ['data' => $op->refresh()];
    }
}
