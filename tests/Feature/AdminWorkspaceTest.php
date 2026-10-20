<?php

namespace Tests\Feature;

use App\Jobs\Middleware\WithTenant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $name): Shop
    {
        return Shop::forceCreate(['name' => $name, 'status' => 'active']);
    }

    private function login(User $user): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/'.($user->account_type === 'admin' ? 'admin/' : '').'auth/login', ['email' => $user->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
    }

    public function test_admin_operates_in_explicit_shop_scope_without_impersonation_or_cross_shop_ids(): void
    {
        $admin = User::factory()->create(['account_type' => 'admin', 'password' => 'TestingPassword123!']);
        $a = $this->shop('A');
        $b = $this->shop('B');
        $this->login($admin);
        $base = '/api/v1/admin/shops/'.$a->id.'/workspace';
        $other = '/api/v1/admin/shops/'.$b->id.'/workspace';
        $id = $this->postJson($base.'/customers', ['first_name' => 'Ada', 'last_name' => 'Test', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->getJson($base.'/customers/'.$id)->assertOk()->assertJsonPath('data.first_name', 'Ada');
        $this->getJson($other.'/customers/'.$id)->assertNotFound();
        $this->getJson($other.'/customers')->assertJsonCount(0, 'data');
        $this->getJson($base.'/customers?shop_id='.$b->id)->assertForbidden();
        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->assertDatabaseCount('shop_memberships', 0);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'shop_id' => $a->id, 'action' => 'admin.workspace.write']);
        try {
            app(TenantContext::class)->membership();
            $this->fail('Tenant context leaked past the request.');
        } catch (\LogicException $e) {
            $this->assertSame('A validated tenant context is required.', $e->getMessage());
        }
        $a->forceFill(['status' => 'suspended'])->save();
        $this->getJson($base.'/customers')->assertOk();
        $this->patchJson($base.'/customers/'.$id, ['first_name' => 'Changed', 'last_name' => 'Test', 'version' => 1])->assertConflict();
    }

    public function test_admin_capabilities_and_background_access_are_rechecked_without_borrowing_owner_authority(): void
    {
        $admin = User::factory()->create(['account_type' => 'admin', 'password' => 'TestingPassword123!']);
        $shop = $this->shop('Managed');
        $this->login($admin);
        $base = '/api/v1/admin/shops/'.$shop->id.'/workspace';
        Role::where('name', 'system_admin')->first()->permissions()->detach(Permission::where('name', 'shops.customers.write')->value('id'));
        $this->postJson($base.'/customers', ['first_name' => 'Denied', 'last_name' => 'Test', 'creation_key' => (string) Str::uuid()])->assertForbidden();
        $this->getJson($base.'/customers')->assertOk();
        (new WithTenant($shop->id, $admin->id))->handle(new \stdClass, function () {
            $m = app(TenantContext::class)->membership()->fresh(['user', 'shop', 'role.permissions']);
            $this->assertTrue($m->can('customers.read'));
            $this->assertFalse($m->can('customers.write'));
        });
        Role::where('name', 'system_admin')->first()->permissions()->detach(Permission::where('name', 'shops.workspace')->value('id'));
        $this->getJson($base.'/customers')->assertForbidden();
        $this->expectException(HttpException::class);
        (new WithTenant($shop->id, $admin->id))->handle(new \stdClass, fn () => $this->fail('Revoked administrator executed a tenant job.'));
    }

    public function test_shop_actor_cannot_use_admin_routes_and_worker_health_is_queue_specific(): void
    {
        $owner = User::factory()->create(['password' => 'TestingPassword123!']);
        $shop = $this->shop('Independent');
        ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->login($owner);
        $this->getJson('/api/v1/admin/shops/'.$shop->id.'/workspace/customers')->assertForbidden();
        config(['queue.default' => 'database']);
        Event::dispatch(new Looping('database', 'default'));
        $this->getJson('/api/v1/uploads/processing')->assertOk()->assertJsonPath('data.worker_active', false);
        Event::dispatch(new Looping('database', 'uploads,integrations'));
        $this->getJson('/api/v1/uploads/processing')->assertOk()->assertJsonPath('data.worker_active', true);
        Cache::put('optical:worker-heartbeat:database:uploads', time() - 100, 120);
        $this->getJson('/api/v1/uploads/processing')->assertOk()->assertJsonPath('data.worker_active', false);
    }
}
