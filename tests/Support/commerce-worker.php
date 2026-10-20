<?php

use App\Models\ShopMembership;
use App\Services\Commerce\Orders;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$root = realpath(storage_path('framework/testing'));
$db = realpath($input['database']);
if (! app()->environment('testing') || ! $db || dirname($db) !== $root || ! preg_match('/^commerce-[a-f0-9-]+\.sqlite$/', basename($db))) {
    throw new RuntimeException('Concurrency worker requires its isolated test database.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $db, 'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'queue.default' => 'sync']);
DB::purge('sqlite');
app(TenantContext::class)->set(ShopMembership::with('role')->findOrFail($input['membership']));
// Delay each worker once inside its transaction so simultaneous starts overlap.
$delayed = false;
DB::listen(function ($query) use (&$delayed) {
    if (! $delayed && str_contains($query->sql, 'shops') && DB::transactionLevel() > 0) {
        $delayed = true;
        usleep(150000);
    }
});
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($input['barrier'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    if ($input['action'] === 'create-product') {
        [$product] = app(\App\Services\Commerce\Catalog::class)->save($input['payload'], $input['actor']);
        echo json_encode(['http' => 200, 'id' => $product->id, 'sku' => $product->sku]);
        return;
    }
    $order = app(Orders::class)->transition($input['order'], $input['action'], $input['payload'], $input['actor']);
    echo json_encode(['http' => 200, 'id' => $order->id, 'status' => $order->status]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['http' => $e->getStatusCode(), 'message' => $e->getMessage()]);
} finally {
    app(TenantContext::class)->clear();
    DB::disconnect();
}
