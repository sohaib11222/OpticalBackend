<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerWorkflowTest extends TestCase
{
    use RefreshDatabase;

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

    private function data(array $extra = []): array
    {
        return array_merge(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-12-10', 'phone' => '+39 06 12345678', 'email' => 'ADA@example.test', 'street_address' => 'Via Roma 1', 'city' => 'Roma', 'postal_code' => '00100', 'sex' => 'Female', 'creation_key' => (string) Str::uuid()], $extra);
    }

    public function test_customer_lifecycle_keeps_nine_fields_history_and_version_conflicts(): void
    {
        $this->login($this->owner('one@example.test'));
        $d = $this->data();
        $r = $this->postJson('/api/v1/customers', $d)->assertCreated()->assertJsonPath('data.email', 'ada@example.test')->assertJsonPath('data.date_of_birth', '1990-12-10')->assertJsonPath('data.postal_code', '00100');
        $id = $r->json('data.id');
        $this->getJson('/api/v1/customers?search=Ada')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customers/'.$id)->assertOk()->assertJsonPath('data.version', 1);
        $change = array_merge($d, ['version' => 1, 'city' => 'Milano']);
        $this->patchJson('/api/v1/customers/'.$id, $change)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/customers/'.$id, $change)->assertConflict();
        $this->patchJson('/api/v1/customers/'.$id.'/status', ['status' => 'archived', 'version' => 2, 'reason' => 'No longer attends'])->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson('/api/v1/customers')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customers?status=archived')->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/customers/'.$id, array_merge($change, ['version' => 3]))->assertConflict();
        $this->patchJson('/api/v1/customers/'.$id.'/status', ['status' => 'active', 'version' => 3, 'reason' => 'Returned to shop'])->assertOk();
        $this->getJson('/api/v1/customers/'.$id.'/history')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.action', 'customer.active');
        $this->assertDatabaseHas('customers', ['id' => $id, 'city' => 'Milano', 'version' => 4]);
    }

    public function test_create_retry_is_idempotent_and_duplicate_review_never_merges(): void
    {
        $this->login($this->owner('one@example.test'));
        $d = $this->data();
        $id = $this->postJson('/api/v1/customers', $d)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers', $d)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/customers', array_merge($d, ['city' => 'Different']))->assertConflict();
        $this->postJson('/api/v1/customers/duplicate-check', $this->data())->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/customers', $this->data())->assertConflict();
        $this->postJson('/api/v1/customers', $this->data(['confirm_distinct' => true]))->assertCreated();
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('customer_events', 2);
    }

    public function test_every_customer_endpoint_is_tenant_scoped_including_history_and_duplicates(): void
    {
        $a = $this->owner('one@example.test');
        $b = $this->owner('two@example.test');
        $this->login($a);
        $id = $this->postJson('/api/v1/customers', $this->data())->assertCreated()->json('data.id');
        $this->login($b);
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data');
        foreach (['', '/history'] as $suffix) {
            $this->getJson('/api/v1/customers/'.$id.$suffix)->assertNotFound();
        }
        $this->patchJson('/api/v1/customers/'.$id, $this->data(['version' => 1]))->assertNotFound();
        $this->patchJson('/api/v1/customers/'.$id.'/status', ['status' => 'archived', 'version' => 1, 'reason' => 'Attack'])->assertNotFound();
        $this->postJson('/api/v1/customers/duplicate-check', $this->data())->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/customers', $this->data(['shop_id' => $a->memberships()->first()->shop_id]))->assertForbidden();
        $this->postJson('/api/v1/customers', $this->data())->assertCreated();
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_permissions_validation_and_literal_filters_are_enforced(): void
    {
        $u = $this->owner('one@example.test');
        $this->login($u);
        $this->postJson('/api/v1/customers', $this->data(['date_of_birth' => '2099-01-01', 'email' => 'invalid', 'first_name' => '', 'phone' => 'letters']))->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth', 'email', 'first_name', 'phone']);
        $this->getJson('/api/v1/customers?per_page=500')->assertUnprocessable();
        $this->postJson('/api/v1/customers', $this->data())->assertCreated();
        $this->getJson('/api/v1/customers?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $role = $u->memberships()->first()->role;
        $role->permissions()->detach(DB::table('permissions')->where('name', 'customers.write')->value('id'));
        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', $this->data())->assertForbidden();
        $role->permissions()->detach(DB::table('permissions')->where('name', 'customers.read')->value('id'));
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_platform_admin_cannot_read_customer_records_and_no_tenant_query_fails_closed(): void
    {
        $a = User::factory()->create(['account_type' => 'admin', 'password' => 'TestingPassword123!']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => $a->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->assertSame(0, Customer::count());
    }

    public function test_database_relationship_rejects_cross_shop_customer_event(): void
    {
        $a = $this->owner('one@example.test');
        $b = $this->owner('two@example.test');
        $this->login($a);
        $id = $this->postJson('/api/v1/customers', $this->data())->assertCreated()->json('data.id');
        $this->expectException(QueryException::class);
        DB::table('customer_events')->insert(['shop_id' => $b->memberships()->first()->shop_id, 'customer_id' => $id, 'actor_id' => $b->id, 'action' => 'invalid']);
    }
}
