<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Commerce\Catalog;
use App\Services\Commerce\Orders;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SQLiteCommerceConcurrencyTest extends TestCase
{
    private array $files = [];

    private array $processes = [];

    private array $oldSqlite;

    private string $oldDefault;

    private string $db;

    private ShopMembership $membership;

    private User $owner;

    private Customer $customer;

    private int $product;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldDefault = config('database.default');
        $this->oldSqlite = config('database.connections.sqlite');
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $this->prefix = $directory.'/commerce-'.Str::uuid();
        $this->db = $this->prefix.'.sqlite';
        $this->files[] = $this->db;
        touch($this->db);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->db, 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->owner = User::factory()->create();
        $shop = new Shop(['name' => 'Concurrent synthetic shop']);
        $shop->status = 'active';
        $shop->currency = 'EUR';
        $shop->save();
        $this->membership = ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $this->owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        app(TenantContext::class)->set($this->membership);
        $category = ProductCategory::create(['name' => 'Frames', 'name_key' => 'frames']);
        [$p] = app(Catalog::class)->save(['name' => 'Last frame', 'sku' => 'LAST', 'brand' => 'Test', 'model' => 'Test', 'color' => 'Blue', 'category_id' => $category->id, 'price' => '10.00', 'initial_quantity' => 1, 'low_stock_threshold' => 0, 'creation_key' => (string) Str::uuid()], $this->owner->id);
        DB::table('products')->where('id', $p->id)->update(['status' => 'active']);
        $this->product = $p->id;
        $this->customer = new Customer(['first_name' => 'Concurrent', 'last_name' => 'Customer']);
        $this->customer->creation_key = (string) Str::uuid();
        $this->customer->creation_hash = str_repeat('0', 64);
        $this->customer->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $p) {
            if ($p->isRunning()) {
                $p->stop();
            }
        }
        app(TenantContext::class)->clear();
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        config(['database.default' => $this->oldDefault, 'database.connections.sqlite' => $this->oldSqlite]);
        foreach (array_merge($this->files, [$this->db.'-journal', $this->db.'-wal', $this->db.'-shm']) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function draft(): Order
    {
        return app(Orders::class)->save(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product, 'quantity' => 1]], 'creation_key' => (string) Str::uuid()], $this->owner->id)[0];
    }

    private function race(array $requests): array
    {
        $barrier = $this->prefix.'-go-'.Str::uuid();
        $this->files[] = $barrier;
        $readyFiles = [];
        $workers = [];
        foreach ($requests as $i => $request) {
            $input = $this->prefix.'-input-'.Str::uuid().'.json';
            $ready = $input.'.ready';
            array_push($this->files, $input, $ready);
            $readyFiles[] = $ready;
            file_put_contents($input, json_encode(['database' => $this->db, 'membership' => $this->membership->id, 'actor' => $this->owner->id, 'ready' => $ready, 'barrier' => $barrier, ...$request], JSON_THROW_ON_ERROR));
            $p = new Process([PHP_BINARY, base_path('tests/Support/commerce-worker.php'), $input], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('c', 32)), 'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->db, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array']);
            $p->setTimeout(30);
            $p->start();
            $this->processes[] = $p;
            $workers[] = $p;
        }
        $until = microtime(true) + 20;
        while (count(array_filter($readyFiles, 'is_file')) !== count($readyFiles)) {
            foreach ($workers as $p) {
                if (! $p->isRunning()) {
                    $this->fail($p->getErrorOutput().$p->getOutput());
                }
            }
            if (microtime(true) > $until) {
                $this->fail('Workers did not reach the concurrency barrier.');
            }usleep(10000);
        }
        touch($barrier);
        $results = [];
        foreach ($workers as $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
            $this->assertJson($p->getOutput(), $p->getOutput().$p->getErrorOutput());
            $results[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_two_simultaneous_orders_cannot_both_reserve_the_last_unit(): void
    {
        $a = $this->draft();
        $b = $this->draft();
        $result = $this->race(array_map(fn ($o) => ['order' => $o->id, 'action' => 'confirm', 'payload' => ['version' => 1, 'creation_key' => (string) Str::uuid()]], [$a, $b]));
        $codes = array_column($result, 'http');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $this->assertSame(1, Order::where('status', 'confirmed')->count());
        $this->assertSame(1, StockBalance::first()->reserved);
        $this->assertSame(1, StockMovement::where('kind', 'reservation')->count());
    }

    public function test_simultaneous_automatic_skus_are_unique_and_repeated_creation_is_exactly_once(): void
    {
        $data = ['name' => 'Concurrent frame', 'brand' => 'Shop', 'model' => 'A1', 'color' => 'Blue',
            'category_id' => ProductCategory::firstOrFail()->id, 'price' => '19.50', 'initial_quantity' => 2, 'low_stock_threshold' => 0];
        $requests = array_map(fn () => ['action' => 'create-product', 'payload' => [...$data, 'creation_key' => (string) Str::uuid()]], [1, 2]);
        $results = $this->race($requests);
        $this->assertSame([200, 200], array_column($results, 'http'));
        $this->assertCount(2, array_unique(array_column($results, 'sku')));
        $this->assertSame(3, \App\Models\Product::count());
        $same = ['action' => 'create-product', 'payload' => [...$data, 'creation_key' => (string) Str::uuid()]];
        $results = $this->race([$same, $same]);
        $this->assertSame([200, 200], array_column($results, 'http'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame($results[0]['sku'], $results[1]['sku']);
        $this->assertSame(4, \App\Models\Product::count());
        $this->assertSame(4, StockMovement::where('kind', 'receipt')->count());
    }

    public function test_simultaneous_identical_confirmation_and_fulfilment_are_exactly_once(): void
    {
        $o = $this->draft();
        $request = ['order' => $o->id, 'action' => 'confirm', 'payload' => ['version' => 1, 'creation_key' => (string) Str::uuid()]];
        foreach ($this->race([$request, $request]) as $r) {
            $this->assertSame(200, $r['http']);
        }
        $this->assertSame(1, StockMovement::where('kind', 'reservation')->count());
        $request = ['order' => $o->id, 'action' => 'fulfil', 'payload' => ['version' => 2, 'creation_key' => (string) Str::uuid()]];
        foreach ($this->race([$request, $request]) as $r) {
            $this->assertSame(200, $r['http']);
        }
        $this->assertSame(1, StockMovement::where('kind', 'fulfilment')->count());
        $this->assertSame(1, DB::table('sale_entries')->count());
        $balance = StockBalance::first();
        $this->assertSame(0, $balance->on_hand);
        $this->assertSame(0, $balance->reserved);
    }
}
