<?php

namespace Tests\Feature;

use App\Integrations\Vista\PublishVista;
use App\Integrations\Vista\ScheduleVistaSync;
use App\Integrations\Vista\SynchronizeVista;
use App\Jobs\PublishVistaProduct;
use App\Jobs\SyncVista;
use App\Models\Permission;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Models\VistaConnection;
use App\Models\VistaPublication;
use App\Models\VistaRecord;
use App\Services\Commerce\Catalog;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VistaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): void
    {
        config(['vista.enabled' => true, 'vista.base_url' => 'http://127.0.0.1:8200', 'vista.client_secret' => str_repeat('s', 48), 'vista.redirect_uri' => config('optical.shop_url').'/auth/vista/callback']);
        Http::preventStrayRequests();
    }

    private function token(): array
    {
        return ['access_token' => 'vos_'.str_repeat('a', 64), 'expires_at' => now()->addDay()->toIso8601String(), 'identity' => ['subject' => '41', 'name' => 'Vista Seller', 'email' => 'vista@example.test', 'email_verified' => true, 'store' => ['id' => '12', 'name' => 'Vista Shop', 'status' => 'active'], 'currency' => 'EUR']];
    }

    private function login(): array
    {
        $this->configured();
        $queue = Queue::getFacadeRoot();
        Queue::fake([SyncVista::class]);
        Http::fake(['*/api/optical/token' => Http::response($this->token())]);
        $url = $this->postJson('/api/v1/auth/vista/start', ['mode' => 'login'])->assertOk()->json('data.authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $r = $this->postJson('/api/v1/auth/vista/finish', ['code' => str_repeat('c', 64), 'state' => $q['state']])->assertOk()->assertJsonPath('data.shop.status', 'active')->json('data');
        Auth::forgetGuards();

        Queue::swap($queue);

        return $r;
    }

    private function context(): void
    {
        $m = ShopMembership::with('user', 'shop', 'role.permissions')->firstOrFail();
        app(TenantContext::class)->set($m);
    }

    public function test_federated_login_has_stable_identity_encrypted_grant_and_no_password_copy(): void
    {
        $r = $this->login();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('shops', 1);
        $this->assertStringNotContainsString('vos_', DB::table('vista_connections')->value('access_token'));
        $this->getJson('/api/v1/integrations/vista')->assertOk()->assertJsonMissing(['access_token' => $this->token()['access_token']]);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $again = $this->login();
        $this->assertSame($r['id'], $again['id']);
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/v1/auth/vista/finish', ['code' => str_repeat('c', 64), 'state' => str_repeat('z', 64)])->assertStatus(419);
    }

    public function test_matching_email_is_not_automatically_linked_and_state_is_required(): void
    {
        $this->configured();
        User::factory()->create(['email' => 'vista@example.test']);
        Http::fake(['*/api/optical/token' => Http::response($this->token())]);
        $url = $this->postJson('/api/v1/auth/vista/start', ['mode' => 'login'])->assertOk()->json('data.authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->postJson('/api/v1/auth/vista/finish', ['code' => str_repeat('c', 64), 'state' => $q['state']])->assertConflict();
        $this->assertDatabaseCount('vista_connections', 0);
    }

    public function test_standalone_owner_can_link_without_replacing_password_or_creating_another_shop(): void
    {
        $this->configured();
        $owner = User::factory()->create(['email' => 'vista@example.test', 'password' => 'TestingPassword123!']);
        $originalPassword = $owner->password;
        $shop = Shop::factory()->create(['status' => 'active']);
        ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/v1/customers', ['first_name' => 'Existing', 'last_name' => 'Local Customer', 'creation_key' => Str::uuid()->toString()])->assertCreated();
        Http::fake(['*/api/optical/token' => Http::response($this->token())]);
        $url = $this->postJson('/api/v1/auth/vista/start', ['mode' => 'connect'])->assertOk()->json('data.authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->postJson('/api/v1/auth/vista/finish', ['code' => str_repeat('c', 64), 'state' => $q['state']])->assertOk()->assertJsonPath('data.id', $owner->id)->assertJsonPath('data.shop.id', $shop->id);
        Auth::forgetGuards();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('shops', 1);
        $this->assertSame($originalPassword, $owner->fresh()->password);
        $this->getJson('/api/v1/customers?search=Existing')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $again = $this->login();
        $this->assertSame($owner->id, $again['id']);
        $this->assertSame($shop->id, $again['shop']['id']);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'TestingPassword123!'])->assertOk();
    }

    public function test_snapshot_sync_is_scoped_and_customer_import_uses_explicit_duplicate_pipeline(): void
    {
        $this->login();
        $this->context();
        $c = VistaConnection::firstOrFail();
        $token = $this->token();
        Http::fake(['*/identity' => Http::response(['data' => $token['identity']]), '*/products*' => Http::response(['data' => [['id' => 11, 'name' => 'Remote product', 'price' => '5.00', 'currency' => 'EUR', 'stock_quantity' => 2]], 'through_id' => 11, 'next_id' => null]), '*/orders*' => Http::response(['data' => [['id' => 10, 'items' => [['id' => 1, 'product_name' => 'Remote product', 'prescription_data' => ['secret' => 'excluded']]]]], 'through_id' => 10, 'next_id' => null]), '*/customers*' => Http::response(['data' => [['id' => 4, 'name' => 'Vista Buyer', 'email' => 'buyer@example.test', 'phone' => '+390612345678']], 'through_id' => 4, 'next_id' => null])]);
        app(TenantContext::class)->clear();
        $this->postJson('/api/v1/integrations/vista/sync')->assertAccepted();
        $this->context();
        $this->assertSame('connected', $c->refresh()->status);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('sale_entries', 0);
        $this->assertStringNotContainsString('prescription_data', VistaRecord::where('kind', 'orders')->first()->toJson());
        $record = VistaRecord::where('kind', 'customers')->firstOrFail();
        app(TenantContext::class)->clear();
        $this->getJson('/api/v1/integrations/vista/records/products?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/integrations/vista/records/products?search=Remote')->assertOk()->assertJsonCount(1, 'data');
        $id = $this->postJson('/api/v1/integrations/vista/customer-imports', ['rows' => [['id' => $record->id, 'first_name' => 'Vista', 'last_name' => 'Buyer']]])->assertCreated()->assertJsonPath('data.status', 'ready')->json('data.id');
        $b = $this->getJson('/api/v1/customer-imports/'.$id)->assertOk()->json('data');
        $row = $this->getJson('/api/v1/customer-imports/'.$id.'/rows')->assertOk()->json('data.0');
        $b = $this->patchJson('/api/v1/customer-imports/'.$id.'/decisions', ['version' => $b['version'], 'rows' => [['id' => $row['id'], 'decision' => 'create']]])->assertOk()->json('data');
        $this->postJson('/api/v1/customer-imports/'.$id.'/commit', ['version' => $b['version']])->assertAccepted();
        $this->assertDatabaseHas('customers', ['first_name' => 'Vista', 'source' => 'vista_express']);
    }

    public function test_publication_reserves_stock_and_ambiguous_failure_retries_exactly_once(): void
    {
        $this->login();
        Queue::fake();
        Storage::fake('private');
        $this->context();
        $actor = User::first()->id;
        $cat = ProductCategory::create(['name' => 'Frames', 'name_key' => 'frames', 'status' => 'active']);
        [$p] = app(Catalog::class)->save(['name' => 'Frame', 'sku' => 'FRAME', 'brand' => 'Reference', 'model' => 'A', 'color' => 'Blue', 'description' => 'Frame description', 'category_id' => $cat->id, 'price' => '100.00', 'low_stock_threshold' => 0, 'initial_quantity' => 10, 'creation_key' => Str::uuid()->toString()], $actor);
        $im = ProductImage::create(['product_id' => $p->id, 'uploaded_by' => $actor, 'filename' => 'photo.png', 'mime' => 'image/png', 'size' => 10, 'sha256' => str_repeat('a', 64), 'upload_key' => Str::uuid()->toString(), 'storage_key' => 'shops/'.$p->shop_id.'/catalog/photo', 'display_key' => 'shops/'.$p->shop_id.'/catalog/display/1.png', 'scan_status' => 'clean']);
        Storage::disk('private')->put($im->display_key, 'synthetic processed image');
        $p->forceFill(['status' => 'active', 'primary_image_id' => $im->id])->save();
        foreach ([['gallery_image_ids' => []], ['gallery_image_ids' => ['999999']], ['variants' => [['image_ids' => ['999999']]]]] as $invalid) {
            try {
                app(PublishVista::class)->prepare($p->id, ['version' => $p->version, 'quantity' => 3, 'creation_key' => Str::uuid()->toString(), 'mapping' => ['category_id' => 1, 'product_type' => 'frame', ...$invalid]], $actor);
                $this->fail('Unavailable photographs must not reserve stock.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame(0, $p->balance()->first()->reserved);
            }
        }
        $mapping = ['category_id' => 1, 'product_type' => 'frame', 'gallery_image_ids' => [(string) $im->id],
            'variants' => [['option_key' => (string) Str::uuid(), 'color_name' => 'Blue', 'stock_quantity' => 3, 'image_ids' => [(string) $im->id]]]];
        $op = app(PublishVista::class)->prepare($p->id, ['version' => $p->version, 'quantity' => 3, 'creation_key' => Str::uuid()->toString(), 'mapping' => $mapping], $actor);
        $this->assertSame([$op->image_map[0]['remote_key']], $op->payload['product']['gallery_image_ids']);
        $this->assertSame([$op->image_map[0]['remote_key']], $op->payload['product']['variants'][0]['image_ids']);
        $this->assertSame([(string) $im->id], VistaPublication::findOrFail($op->vista_publication_id)->mapping['variants'][0]['image_ids']);
        $this->assertSame(7, $p->balance()->first()->available);
        // A queued publication must recheck every mutation permission after dispatch.
        $role = ShopMembership::firstOrFail()->role;
        $permission = Permission::where('name', 'products.write')->value('id');
        $role->permissions()->detach($permission);
        try {
            (new PublishVistaProduct($p->shop_id, $actor, $op->id))->handle(app(PublishVista::class));
            $this->fail('A revoked product writer published a queued product.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('pending', $op->refresh()->status);
        } finally {
            $role->permissions()->attach($permission);
        }
        Http::fake(['*/images' => Http::response(['data' => ['id' => 'image']]), '*/publications' => Http::response([], 503)]);
        try {
            app(PublishVista::class)->run($op);
        } catch (\RuntimeException) {
        }
        $this->assertSame('uncertain', $op->refresh()->status);
        $this->assertSame(7, $p->balance()->first()->available);
        $this->assertSame(10, $p->balance()->first()->on_hand);
        $pub = VistaPublication::findOrFail($op->vista_publication_id);
        Http::swap(new Factory);
        Http::fake(['*/images' => Http::response(['data' => ['id' => 'image']]), '*/publications' => Http::response(['data' => ['external_key' => $pub->external_key, 'product_id' => 51, 'version' => str_repeat('b', 64), 'visible' => true]])]);
        app(PublishVista::class)->run($op->refresh());
        app(PublishVista::class)->run($op->refresh());
        $this->assertSame('completed', $op->refresh()->status);
        $this->assertSame(7, $p->balance()->first()->on_hand);
        $this->assertSame(0, $p->balance()->first()->reserved);
        $this->assertDatabaseCount('vista_publications', 1);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'vista_allocation', 'quantity_delta' => -3]);
        $cancel = app(PublishVista::class)->prepare($p->id, ['version' => $p->version, 'quantity' => 2, 'creation_key' => Str::uuid()->toString(), 'expected_version' => $pub->refresh()->remote_version, 'mapping' => ['category_id' => 1, 'product_type' => 'frame']], $actor);
        $stale = clone $cancel;
        $this->assertSame(5, $p->balance()->first()->available);
        Http::fake(['*/operations/*/cancel' => Http::response(['data' => ['cancelled' => true, 'operation_key' => $cancel->operation_key]])]);
        app(TenantContext::class)->clear();
        $this->postJson('/api/v1/integrations/vista/operations/'.$cancel->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->context();
        app(PublishVista::class)->run($stale);
        $this->assertSame('rejected', $cancel->refresh()->status);
        $this->assertSame(7, $p->balance()->first()->available);

    }

    public function test_provider_errors_are_redacted_and_foreign_shop_relations_are_rejected(): void
    {
        $this->login();
        $this->context();
        $c = VistaConnection::firstOrFail();
        $other = Shop::factory()->create(['status' => 'active']);
        try {
            DB::table('vista_records')->insert(['shop_id' => $other->id, 'vista_connection_id' => $c->id, 'kind' => 'products', 'remote_id' => '99', 'payload' => '{}', 'seen_run' => Str::uuid()->toString(), 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Foreign connection must be rejected by the database');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY', strtoupper($e->getMessage()));
        }
        app(TenantContext::class)->clear();
        Http::fake(['*/catalog-metadata' => Http::response(['secret' => 'never expose this'], 403)]);
        $this->getJson('/api/v1/integrations/vista/metadata')->assertStatus(503)->assertJsonPath('code', 'vista_unavailable')->assertDontSee('never expose this');
        $this->postJson('/api/v1/customers', ['first_name' => 'Standalone', 'last_name' => 'During Outage', 'creation_key' => Str::uuid()->toString()])->assertCreated();
        $this->getJson('/api/v1/inventory')->assertOk();
        $this->getJson('/api/v1/orders')->assertOk();
        $c->expires_at = now()->subMinute();
        VistaConnection::withoutGlobalScope('shop')->whereKey($c->id)->update(['expires_at' => $c->expires_at]);
        Queue::fake();
        $this->artisan('optical:reconcile-vista')->assertSuccessful();
        Queue::assertNotPushed(SyncVista::class);
    }

    public function test_linking_cannot_revive_a_revoked_or_expired_session(): void
    {
        $this->login();
        config(['optical.absolute_session_minutes' => 480]);
        $this->withSession(['authenticated_at' => now()->subDays(2)->timestamp]);
        $this->postJson('/api/v1/auth/vista/start', ['mode' => 'connect'])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('vista_connections', 1);
    }

    public function test_automatic_sync_claims_once_then_resumes_on_schedule(): void
    {
        $this->login();
        Queue::fake();
        $c = VistaConnection::withoutGlobalScope('shop')->firstOrFail();
        VistaConnection::withoutGlobalScope('shop')->whereKey($c->id)->update(['next_sync_at' => now()->subSecond()]);
        $scheduler = app(ScheduleVistaSync::class);
        $this->assertTrue($scheduler->dispatch($c));
        $this->assertFalse($scheduler->dispatch($c));
        Queue::assertPushed(SyncVista::class, 1);
        $this->travel(11)->minutes();
        $this->assertTrue($scheduler->dispatch($c));
        Queue::assertPushed(SyncVista::class, 2);
        VistaConnection::withoutGlobalScope('shop')->whereKey($c->id)->update(['status' => 'disconnected', 'next_sync_at' => null]);
        $this->assertFalse($scheduler->dispatch($c));
    }

    public function test_completed_snapshots_update_stock_and_mark_deleted_records_unavailable(): void
    {
        $this->login();
        $this->context();
        $c = VistaConnection::firstOrFail();
        $old = VistaRecord::create(['vista_connection_id' => $c->id, 'kind' => 'products', 'remote_id' => '99', 'payload' => ['name' => 'Removed'], 'seen_run' => 'old', 'observed_at' => now()]);
        $identity = $this->token()['identity'];
        $orderStatus = 'pending';
        $customerName = 'First';
        $removed = false;
        Http::fake(['*/identity' => Http::response(['data' => $identity]), '*/products*' => Http::response(['data' => [['id' => 11, 'name' => 'Updated', 'stock_quantity' => 9]], 'through_id' => 11, 'next_id' => null]), '*/orders*' => function () use (&$orderStatus, &$removed) {
            return Http::response(['data' => $removed ? [] : [['id' => 21, 'status' => $orderStatus]], 'through_id' => 21, 'next_id' => null]);
        }, '*/customers*' => function () use (&$customerName, &$removed) {
            return Http::response(['data' => $removed ? [] : [['id' => 31, 'name' => $customerName]], 'through_id' => 31, 'next_id' => null]);
        }]);
        $sync = app(SynchronizeVista::class);
        for ($i = 0; $i < 3; $i++) {
            $sync->run($c);
        }
        $this->assertTrue($old->refresh()->unavailable);
        $this->assertSame(9, VistaRecord::where('remote_id', '11')->firstOrFail()->payload['stock_quantity']);
        $this->assertTrue($c->refresh()->next_sync_at->isFuture());
        $this->assertSame('pending', VistaRecord::where('kind', 'orders')->firstOrFail()->payload['status']);
        $this->assertSame('First', VistaRecord::where('kind', 'customers')->firstOrFail()->payload['name']);
        $orderStatus = 'completed';
        $customerName = 'Updated customer';
        for ($i = 0; $i < 3; $i++) {
            $sync->run($c);
        }
        $this->assertSame(4, VistaRecord::count());
        $this->assertSame('completed', VistaRecord::where('kind', 'orders')->firstOrFail()->payload['status']);
        $this->assertSame('Updated customer', VistaRecord::where('kind', 'customers')->firstOrFail()->payload['name']);
        $removed = true;
        for ($i = 0; $i < 3; $i++) {
            $sync->run($c);
        }
        $this->assertTrue(VistaRecord::where('kind', 'orders')->firstOrFail()->unavailable);
        $this->assertTrue(VistaRecord::where('kind', 'customers')->firstOrFail()->unavailable);
        $this->assertSame(4, VistaRecord::count());
    }

    public function test_in_flight_sync_does_not_revive_a_disconnected_connection(): void
    {
        $this->login();
        $this->context();
        $c = VistaConnection::firstOrFail();
        $identity = $this->token()['identity'];
        Http::fake([
            '*/identity' => Http::response(['data' => $identity]),
            '*/products*' => function () use ($c) {
                VistaConnection::whereKey($c->id)->update(['status' => 'disconnected']);

                return Http::response(['data' => [['id' => 11, 'name' => 'Late response']], 'through_id' => 11, 'next_id' => null]);
            },
        ]);
        (new SyncVista($c->shop_id, $c->user_id, $c->id))->handle(app(SynchronizeVista::class));
        $this->assertSame('disconnected', $c->refresh()->status);
        $this->assertSame(0, VistaRecord::count());
    }
}
