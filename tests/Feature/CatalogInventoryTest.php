<?php

namespace Tests\Feature;

use App\Jobs\Middleware\WithTenant;
use App\Jobs\ProcessProductImage;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Commerce\Inventory;
use App\Services\Files\FileScanner;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
        $this->owner = $this->owner('catalog@example.test');
        $this->login($this->owner);
        $this->settings('EUR');
        $this->category = $this->postJson('/api/v1/product-categories', ['name' => 'Frames', 'description' => 'Optical frames', 'status' => 'active'])->assertCreated()->json('data.id');
    }

    private function owner(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'password' => 'TestingPassword123!']);
        $s = new Shop(['name' => $email]);
        $s->status = 'active';
        $s->save();
        ShopMembership::create(['shop_id' => $s->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

        return $u;
    }

    private function login(User $u): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
    }

    private function settings(string $currency)
    {
        $current = $this->getJson('/api/v1/shop/settings')->assertOk()->json('data.shop');

        return $this->patchJson('/api/v1/shop/settings', ['name' => 'Optical business', 'currency' => $currency, 'stock_location' => 'Main warehouse', 'settings_version' => $current['settings_version'], 'notification_preferences' => ['stock' => true, 'orders' => true, 'imports' => true, 'agenda' => true]]);
    }

    private function data(array $extra = []): array
    {
        return array_merge(['name' => 'Blue frame', 'sku' => 'FRAME-01', 'brand' => 'Reference', 'model' => 'Model One', 'color' => 'Blue', 'description' => 'Lightweight optical frame', 'category_id' => $this->category, 'price' => '120.45', 'low_stock_threshold' => 1, 'initial_quantity' => 5, 'creation_key' => (string) Str::uuid()], $extra);
    }

    private function product(array $extra = []): array
    {
        return $this->postJson('/api/v1/products', $this->data($extra))->assertCreated()->json('data');
    }

    private function photo(int $id, bool $clean = true): int
    {
        $image = $this->postJson('/api/v1/products/'.$id.'/images', ['file' => UploadedFile::fake()->image('frame.png', 60, 60), 'upload_key' => (string) Str::uuid()])->assertStatus(202)->json('data.id');
        $this->get('/api/v1/catalog-images/'.$image)->assertConflict();
        $scanner = new class($clean) implements FileScanner
        {
            public function __construct(private bool $clean) {}

            public function scan($stream): bool
            {
                return $this->clean;
            }
        };
        $job = new ProcessProductImage($this->owner->memberships()->first()->shop_id, $this->owner->id, $image);
        (new WithTenant($job->shopId, $job->actorId))->handle($job, fn ($j) => $j->handle($scanner));

        return $image;
    }

    public function test_complete_catalog_preserves_money_media_stock_categories_and_stale_edits(): void
    {
        $data = $this->data();
        $p = $this->postJson('/api/v1/products', $data)->assertCreated()->assertJsonPath('data.price_minor', 12045)->assertJsonPath('data.balance.on_hand', 5)->json('data');
        $this->postJson('/api/v1/products', $data)->assertOk()->assertJsonPath('data.id', $p['id']);
        $this->postJson('/api/v1/products', $this->data())->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['status' => 'active', 'version' => 1])->assertUnprocessable();
        $image = $this->photo($p['id']);
        $this->get('/api/v1/catalog-images/'.$image)->assertOk()->assertHeader('Content-Type', 'image/png');
        $p = $this->getJson('/api/v1/products/'.$p['id'])->assertJsonPath('data.primary_image_id', $image)->json('data');
        $this->assertArrayNotHasKey('storage_key', $p['images'][0]);
        $this->assertDatabaseCount('private_files', 0);
        $p = $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['status' => 'active', 'version' => $p['version']])->assertOk()->json('data');
        $this->patchJson('/api/v1/catalog-images/'.$image, ['action' => 'archive', 'version' => $p['version']])->assertConflict();
        $edit = $data;
        unset($edit['creation_key'],$edit['initial_quantity']);
        $edit['version'] = $p['version'];
        $edit['price'] = '131.01';
        $edit['color'] = 'Deep blue';
        $this->patchJson('/api/v1/products/'.$p['id'], $edit)->assertOk()->assertJsonPath('data.price_minor', 13101);
        $this->patchJson('/api/v1/products/'.$p['id'], $edit)->assertConflict();
        $this->settings('USD')->assertConflict();
        $this->getJson('/api/v1/products?search=Deep&status=active')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products/'.$p['id'].'/movements')->assertJsonPath('data.0.quantity_delta', 5);
        $this->postJson('/api/v1/product-categories', ['name' => 'frames', 'status' => 'active'])->assertUnprocessable();
    }

    public function test_stock_adjustments_reservations_reversals_and_retries_never_erase_history(): void
    {
        $p = $this->product();
        $move = ['kind' => 'adjustment', 'quantity' => -2, 'reason' => 'Stock count correction', 'version' => $p['balance']['version'], 'creation_key' => (string) Str::uuid()];
        $m = $this->postJson('/api/v1/products/'.$p['id'].'/movements', $move)->assertCreated()->assertJsonPath('data.on_hand_after', 3)->json('data');
        $this->postJson('/api/v1/products/'.$p['id'].'/movements', $move)->assertCreated()->assertJsonPath('data.id', $m['id']);
        $move['quantity'] = -20;
        $move['creation_key'] = (string) Str::uuid();
        $move['version'] = 3;
        $this->postJson('/api/v1/products/'.$p['id'].'/movements', $move)->assertConflict();
        $reverse = ['version' => 3, 'creation_key' => (string) Str::uuid(), 'reason' => 'Recount confirmed original quantity'];
        $this->postJson('/api/v1/stock-movements/'.$m['id'].'/reverse', $reverse)->assertCreated()->assertJsonPath('data.on_hand_after', 5);
        $this->postJson('/api/v1/stock-movements/'.$m['id'].'/reverse', $reverse)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 3);
        $context = app(TenantContext::class);
        $context->set($this->owner->memberships()->with('role')->first());
        DB::transaction(function () use ($p) {
            Shop::whereKey($p['shop_id'])->lockForUpdate()->firstOrFail();
            app(Inventory::class)->move(Product::findOrFail($p['id']), 'reservation', 0, 4, 'synthetic:reservation', 'Test reserved order', $this->owner->id);
        });
        $context->clear();
        $move['quantity'] = -2;
        $move['version'] = 5;
        $move['creation_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/products/'.$p['id'].'/movements', $move)->assertConflict();
        $this->getJson('/api/v1/inventory')->assertJsonPath('data.0.available', 1)->assertJsonPath('data.0.reserved', 4);
        $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['version' => 1, 'status' => 'archived'])->assertConflict();
    }

    public function test_currency_precision_limits_foreign_ids_and_backend_capabilities(): void
    {
        $this->settings('JPY')->assertOk();
        $this->postJson('/api/v1/products', $this->data(['price' => '1.25']))->assertUnprocessable();
        $this->settings('KWD')->assertOk();
        $p = $this->product(['price' => '1.234']);
        $this->assertSame(1234, $p['price_minor']);
        $this->assertSame(3, $p['price_decimals']);
        $this->postJson('/api/v1/products', $this->data(['price' => '1e3', 'sku' => 'BAD']))->assertUnprocessable();
        $other = $this->owner('othercatalog@example.test');
        $this->login($other);
        $this->settings('EUR')->assertOk();
        $this->getJson('/api/v1/products/'.$p['id'])->assertNotFound();
        $this->getJson('/api/v1/inventory?product_id='.$p['id'])->assertNotFound();
        $this->postJson('/api/v1/products', $this->data())->assertNotFound();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->login($this->owner);
        $permission = Permission::where('name', 'stock.write')->first();
        Role::where('name', 'owner')->first()->permissions()->detach($permission);
        $this->postJson('/api/v1/products', $this->data(['sku' => 'NO-STOCK', 'price' => '2.345']))->assertForbidden();
        $this->postJson('/api/v1/products/'.$p['id'].'/movements', ['kind' => 'receipt', 'quantity' => 1, 'reason' => 'Unauthorized stock', 'version' => 2, 'creation_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_rejected_photos_never_activate_and_low_stock_notices_are_scoped_and_deduplicated(): void
    {
        $p = $this->product(['initial_quantity' => 1]);
        $bad = $this->photo($p['id'], false);
        $this->get('/api/v1/catalog-images/'.$bad)->assertConflict();
        $this->postJson('/api/v1/catalog-images/'.$bad.'/retry')->assertConflict();
        $this->photo($p['id']);
        $p = $this->getJson('/api/v1/products/'.$p['id'])->json('data');
        $p = $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['version' => $p['version'], 'status' => 'active'])->assertOk()->json('data');
        $notices = $this->getJson('/api/v1/notices')->assertOk()->assertJsonPath('unread', 1)->json('notices.data');
        $id = $notices[0]['id'];
        $this->postJson('/api/v1/notices/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/notices')->assertJsonPath('unread', 0);
        $this->login($this->owner('noticesforeign@example.test'));
        $this->getJson('/api/v1/notices')->assertJsonPath('unread', 0);
        $this->postJson('/api/v1/notices/'.$id.'/read')->assertNotFound();
    }

    public function test_shop_notification_preferences_suppress_new_alerts_and_preserve_existing_history(): void
    {
        $preferences = function (bool $enabled) {
            $current = $this->getJson('/api/v1/shop/settings')->assertOk()->json('data.shop');
            $this->patchJson('/api/v1/shop/settings', [
                'name' => $current['name'], 'currency' => $current['currency'],
                'stock_location' => 'Main warehouse', 'settings_version' => $current['settings_version'],
                'notification_preferences' => ['stock' => $enabled, 'orders' => true, 'imports' => true, 'agenda' => true],
            ])->assertOk()->assertJsonPath('data.shop.notification_preferences.stock', $enabled);
        };
        $preferences(false);
        $p = $this->product(['initial_quantity' => 1]);
        $this->photo($p['id']);
        $p = $this->getJson('/api/v1/products/'.$p['id'])->json('data');
        $p = $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['version' => $p['version'], 'status' => 'active'])->assertOk()->json('data');
        $this->getJson('/api/v1/notices')->assertJsonPath('unread', 0)->assertJsonCount(0, 'notices.data');
        $preferences(true);
        $p = $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['version' => $p['version'], 'status' => 'draft'])->assertOk()->json('data');
        $this->patchJson('/api/v1/products/'.$p['id'].'/status', ['version' => $p['version'], 'status' => 'active'])->assertOk();
        $this->getJson('/api/v1/notices')->assertJsonPath('unread', 1)->assertJsonCount(1, 'notices.data');
        $preferences(false);
        $this->getJson('/api/v1/notices')->assertJsonPath('unread', 1)->assertJsonCount(1, 'notices.data');
    }

    public function test_stock_database_constraint_rejects_negative_balances_even_outside_service(): void
    {
        $p = $this->product();
        $this->expectException(QueryException::class);
        DB::table('stock_balances')->where('product_id', $p['id'])->update(['reserved' => 6]);
    }
}
