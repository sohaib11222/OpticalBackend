<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SaleEntry;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $customer;

    private int $category;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner('orders@example.test');
        $this->login($this->owner);
        $this->patchJson('/api/v1/shop/settings', ['name' => 'Order shop', 'currency' => 'EUR', 'stock_location' => 'Main', 'settings_version' => 1, 'notification_preferences' => ['orders' => true, 'stock' => true, 'imports' => true, 'agenda' => true]])->assertOk();
        $this->category = $this->postJson('/api/v1/product-categories', ['name' => 'Frames', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->product = $this->product('FRAME-A', 5);
        $this->customer = $this->postJson('/api/v1/customers', ['first_name' => 'Order', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
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

    private function product(string $sku, int $stock): int
    {
        $id = $this->postJson('/api/v1/products', ['name' => $sku, 'sku' => $sku, 'brand' => 'Reference', 'model' => 'Frame', 'color' => 'Blue', 'description' => 'Optical frame', 'category_id' => $this->category, 'price' => '120.45', 'low_stock_threshold' => 1, 'initial_quantity' => $stock, 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        // Photo processing/activation are covered by CatalogInventoryTest; this fixture isolates order behavior.
        DB::table('products')->where('id', $id)->update(['status' => 'active']);

        return $id;
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['customer_id' => $this->customer, 'items' => [['product_id' => $this->product, 'quantity' => 2]], 'creation_key' => (string) Str::uuid()], $extra);
    }

    private function order(array $extra = []): array
    {
        return $this->postJson('/api/v1/orders', $this->payload($extra))->assertCreated()->json('data');
    }

    private function transition(array $o, string $action, array $extra = []): array
    {
        return ['version' => $o['version'], 'creation_key' => (string) Str::uuid(), ...$extra];
    }

    private function stock(int $onHand, int $reserved): void
    {
        $this->getJson('/api/v1/products/'.$this->product)->assertJsonPath('data.balance.on_hand', $onHand)->assertJsonPath('data.balance.reserved', $reserved)->assertJsonPath('data.balance.available', $onHand - $reserved);
    }

    public function test_order_customer_prescription_stock_sale_and_reversal_are_atomic_and_retry_safe(): void
    {
        $rx = $this->postJson('/api/v1/customers/'.$this->customer.'/optical/prescription', ['creation_key' => (string) Str::uuid(), 'recorded_on' => '2026-01-01', 'practitioner' => 'Synthetic Optician', 'data' => ['od_sphere' => -1.25, 'prescription_type' => 'single_vision']])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers/'.$this->customer.'/optical/'.$rx.'/finalize', ['version' => 1])->assertOk();
        $payload = $this->payload(['prescription_id' => $rx]);
        $o = $this->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('data.total_minor', 24090)->assertJsonPath('data.number', 'ORD-000001')->json('data');
        $this->postJson('/api/v1/orders', $payload)->assertOk()->assertJsonPath('data.id', $o['id']);
        $this->stock(5, 0);
        $confirm = $this->transition($o, 'confirm');
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $confirm)->assertOk()->assertJsonPath('data.status', 'confirmed')->json('data');
        $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $confirm)->assertOk();
        $this->stock(5, 2);
        DB::table('products')->where('id', $this->product)->update(['price_minor' => 99999]);
        $fulfil = $this->transition($o, 'fulfil');
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $fulfil)->assertOk()->assertJsonPath('data.total_minor', 24090)->assertJsonPath('data.items.0.unit_price_minor', 12045)->assertJsonPath('data.sales.0.amount_minor', 24090)->json('data');
        $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $fulfil)->assertOk();
        $this->assertDatabaseCount('sale_entries', 1);
        $this->stock(3, 0);
        $reverse = $this->transition($o, 'reverse', ['reason' => 'Complete sale correction', 'return_stock' => true]);
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/reverse', $reverse)->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonCount(2, 'data.sales')->json('data');
        $this->postJson('/api/v1/orders/'.$o['id'].'/reverse', $reverse)->assertOk();
        $this->stock(5, 0);
        $this->assertDatabaseCount('sale_entries', 2);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->getJson('/api/v1/orders/'.$o['id'].'/history')->assertJsonCount(4, 'data')->assertJsonPath('data.0.stock_returned', true);
        $this->getJson('/api/v1/customers/'.$this->customer.'/history')->assertJsonPath('data.0.action', 'order.reverse');
        $this->getJson('/api/v1/orders?customer_id='.$this->customer.'&status=reversed&search=ORD-000001')->assertJsonCount(1, 'data');
    }

    public function test_short_stock_rolls_back_all_lines_and_cancellation_releases_exactly_once(): void
    {
        $p2 = $this->product('LOW-STOCK', 0);
        $o = $this->order(['items' => [['product_id' => $this->product, 'quantity' => 3], ['product_id' => $p2, 'quantity' => 1]]]);
        $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertConflict();
        $this->stock(5, 0);
        $this->getJson('/api/v1/orders/'.$o['id'])->assertJsonPath('data.status', 'draft');
        $a = $this->order(['items' => [['product_id' => $this->product, 'quantity' => 5]]]);
        $b = $this->order(['items' => [['product_id' => $this->product, 'quantity' => 1]]]);
        $a = $this->postJson('/api/v1/orders/'.$a['id'].'/confirm', $this->transition($a, 'confirm'))->assertOk()->json('data');
        $this->stock(5, 5);
        $this->postJson('/api/v1/orders/'.$b['id'].'/confirm', $this->transition($b, 'confirm'))->assertConflict();
        $this->stock(5, 5);
        $cancel = $this->transition($a, 'cancel', ['reason' => 'Customer changed plans']);
        $this->postJson('/api/v1/orders/'.$a['id'].'/cancel', $cancel)->assertOk();
        $this->postJson('/api/v1/orders/'.$a['id'].'/cancel', $cancel)->assertOk();
        $this->stock(5, 0);
        $this->postJson('/api/v1/orders/'.$b['id'].'/confirm', $this->transition($b, 'confirm'))->assertOk();
        $this->stock(5, 1);
        $this->assertDatabaseCount('sale_entries', 0);
    }

    public function test_draft_edit_refreshes_prices_but_stale_and_confirmed_edits_and_invalid_transitions_fail(): void
    {
        $payload = $this->payload();
        $o = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('data');
        unset($payload['creation_key']);
        $payload['version'] = 1;
        $payload['items'][0]['quantity'] = 3;
        $o = $this->patchJson('/api/v1/orders/'.$o['id'], $payload)->assertOk()->assertJsonPath('data.total_minor', 36135)->json('data');
        $this->patchJson('/api/v1/orders/'.$o['id'], $payload)->assertConflict();
        $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $this->transition($o, 'fulfil'))->assertConflict();
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertOk()->json('data');
        $payload['version'] = $o['version'];
        $this->patchJson('/api/v1/orders/'.$o['id'], $payload)->assertConflict();
        $this->postJson('/api/v1/orders/'.$o['id'].'/reverse', $this->transition($o, 'reverse', ['reason' => 'Wrong state', 'return_stock' => true]))->assertConflict();
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $this->transition($o, 'fulfil'))->assertOk()->json('data');
        $this->postJson('/api/v1/orders/'.$o['id'].'/cancel', $this->transition($o, 'cancel', ['reason' => 'Invalid cancellation']))->assertConflict();
        $this->postJson('/api/v1/orders/'.$o['id'].'/reverse', $this->transition($o, 'reverse', ['reason' => 'No goods returned', 'return_stock' => false]))->assertOk()->assertJsonPath('data.sales.1.stock_returned', false);
        $this->stock(2, 0);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_orders_validate_cross_shop_customer_product_prescription_and_action_permissions(): void
    {
        $o = $this->order();
        $foreign = $this->owner('foreign-order@example.test');
        $this->login($foreign);
        $this->getJson('/api/v1/orders/'.$o['id'])->assertNotFound();
        $this->getJson('/api/v1/orders/'.$o['id'].'/history')->assertNotFound();
        $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertNotFound();
        $this->getJson('/api/v1/orders?customer_id='.$this->customer)->assertNotFound();
        $this->login($this->owner);
        $ownerRole = Role::where('name', 'owner')->first();
        $ownerRole->permissions()->detach(Permission::where('name', 'orders.write')->first());
        $this->postJson('/api/v1/orders', $this->payload())->assertForbidden();
        $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertForbidden();
        $ownerRole->permissions()->attach(Permission::where('name', 'orders.write')->value('id'));
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertOk()->json('data');
        $ownerRole->permissions()->detach(Permission::where('name', 'orders.fulfil')->first());
        $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $this->transition($o, 'fulfil'))->assertForbidden();
        $this->stock(5, 2);
        $this->postJson('/api/v1/orders', $this->payload(['items' => [['product_id' => $this->product, 'quantity' => 0]]]))->assertUnprocessable();
        $this->postJson('/api/v1/orders', $this->payload(['items' => [['product_id' => $this->product, 'quantity' => 1], ['product_id' => $this->product, 'quantity' => 2]]]))->assertUnprocessable();
        $this->postJson('/api/v1/orders', $this->payload(['total_minor' => 1]))->assertUnprocessable();
    }

    public function test_finalized_sales_and_confirmed_item_models_refuse_direct_changes(): void
    {
        $o = $this->order();
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertOk()->json('data');
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $this->transition($o, 'fulfil'))->assertOk()->json('data');
        app(TenantContext::class)->set($this->owner->memberships()->with('role')->first());
        try {
            Order::findOrFail($o['id'])->items()->first()->update(['quantity' => 9]);
            $this->fail('Confirmed items changed');
        } catch (\LogicException$e) {
            $this->assertStringContainsString('cannot change', $e->getMessage());
        }
        try {
            SaleEntry::first()->update(['amount_minor' => 1]);
            $this->fail('Sale changed');
        } catch (\LogicException$e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_sales_sheet_and_appointment_order_links_require_the_same_customer(): void
    {
        $o = $this->order();
        $other = $this->postJson('/api/v1/customers', ['first_name' => 'Another', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $appointment = ['title' => 'Order handover', 'customer_id' => $this->customer, 'order_id' => $o['id'], 'starts_at' => '2026-10-20T09:00:00Z', 'ends_at' => '2026-10-20T09:30:00Z', 'creation_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/appointments', [...$appointment, 'customer_id' => $other])->assertNotFound();
        $this->postJson('/api/v1/appointments', [...$appointment, 'customer_id' => null])->assertUnprocessable();
        $this->postJson('/api/v1/appointments', $appointment)->assertCreated()->assertJsonPath('data.order_id', $o['id']);
        $edit = $this->payload(['customer_id' => $other, 'version' => 1]);
        unset($edit['creation_key']);
        $this->patchJson('/api/v1/orders/'.$o['id'], $edit)->assertConflict();
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertOk()->json('data');
        $o = $this->postJson('/api/v1/orders/'.$o['id'].'/fulfil', $this->transition($o, 'fulfil'))->assertOk()->json('data');
        $this->getJson('/api/v1/customers/'.$this->customer.'/sales')->assertOk()->assertJsonPath('summary.0.net_minor', 24090)->assertJsonPath('entries.data.0.order.number', $o['number']);
        $this->postJson('/api/v1/orders/'.$o['id'].'/reverse', $this->transition($o, 'reverse', ['reason' => 'Full sale reversed', 'return_stock' => true]))->assertOk();
        $this->getJson('/api/v1/customers/'.$this->customer.'/sales')->assertJsonPath('summary.0.sales_minor', 24090)->assertJsonPath('summary.0.reversals_minor', 24090)->assertJsonPath('summary.0.net_minor', 0)->assertJsonCount(2, 'entries.data');
        $this->getJson('/api/v1/customers/'.$other.'/sales')->assertJsonCount(0, 'entries.data');
        $this->login($this->owner('sales-foreign@example.test'));
        $this->getJson('/api/v1/customers/'.$this->customer.'/sales')->assertNotFound();
    }

    public function test_order_prescriptions_are_current_finalized_and_same_customer(): void
    {
        $other = $this->postJson('/api/v1/customers', ['first_name' => 'Other', 'last_name' => 'Prescription', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $make = function (int $customer) {
            return $this->postJson('/api/v1/customers/'.$customer.'/optical/prescription', ['creation_key' => (string) Str::uuid(), 'recorded_on' => '2026-01-01', 'practitioner' => 'Synthetic Optician', 'data' => ['od_sphere' => -1.25, 'prescription_type' => 'single_vision']])->assertCreated()->json('data.id');
        };
        $foreign = $make($other);
        $this->postJson('/api/v1/customers/'.$other.'/optical/'.$foreign.'/finalize', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/orders', $this->payload(['prescription_id' => $foreign]))->assertNotFound();
        $rx = $make($this->customer);
        $this->postJson('/api/v1/orders', $this->payload(['prescription_id' => $rx]))->assertNotFound();
        $this->postJson('/api/v1/customers/'.$this->customer.'/optical/'.$rx.'/finalize', ['version' => 1])->assertOk();
        $o = $this->order(['prescription_id' => $rx]);
        $next = $this->postJson('/api/v1/customers/'.$this->customer.'/optical/'.$rx.'/amend', ['version' => 2, 'creation_key' => (string) Str::uuid(), 'reason' => 'Updated prescription'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers/'.$this->customer.'/optical/'.$next.'/finalize', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertConflict();
        $this->stock(5, 0);
    }

    public function test_notifications_disappear_when_underlying_permission_is_revoked(): void
    {
        $o = $this->order();
        $this->postJson('/api/v1/orders/'.$o['id'].'/confirm', $this->transition($o, 'confirm'))->assertOk();
        $notice = $this->getJson('/api/v1/notices')->assertJsonPath('unread', 1)->json('notices.data.0.id');
        Role::where('name', 'owner')->first()->permissions()->detach(Permission::where('name', 'orders.read')->first());
        $this->getJson('/api/v1/notices')->assertJsonPath('unread', 0)->assertJsonCount(0, 'notices.data');
        $this->postJson('/api/v1/notices/'.$notice.'/read')->assertNotFound();
    }
}
