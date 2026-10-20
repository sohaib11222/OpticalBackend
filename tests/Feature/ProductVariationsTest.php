<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariationsTest extends TestCase
{
    use RefreshDatabase;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $u = User::factory()->create(['password' => 'TestingPassword123!']);
        $shop = new Shop(['name' => 'Variations']);
        $shop->forceFill(['status' => 'active', 'currency' => 'EUR'])->save();
        ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
        $this->category = $this->postJson('/api/v1/product-categories', ['name' => 'Frames', 'status' => 'active'])->assertCreated()->json('data.id');
    }

    private function data(array $extra = []): array
    {
        return [...['name' => 'Optical frame', 'sku' => null, 'brand' => 'Shop', 'model' => 'A1', 'color' => 'Black',
            'description' => 'Frame with separate variants', 'price' => '99.50', 'category_id' => $this->category,
            'initial_quantity' => 4, 'low_stock_threshold' => 1, 'creation_key' => (string) Str::uuid()], ...$extra];
    }

    public function test_generated_skus_handle_manual_collisions_retries_and_updates(): void
    {
        $this->postJson('/api/v1/products', $this->data(['sku' => 'OPT-000002']))->assertCreated();
        $d = $this->data();
        $p = $this->postJson('/api/v1/products', $d)->assertCreated()->json('data');
        $this->assertSame('OPT-000003', $p['sku']);
        $this->postJson('/api/v1/products', $d)->assertOk()->assertJsonPath('data.id', $p['id']);
        $this->postJson('/api/v1/products', [...$d, 'name' => 'Changed'])->assertConflict();
        $edit = array_diff_key($d, array_flip(['creation_key', 'initial_quantity']));
        $this->patchJson('/api/v1/products/'.$p['id'], [...$edit, 'version' => $p['version']])->assertOk()->assertJsonPath('data.sku', $p['sku']);
        $this->postJson('/api/v1/products', $this->data(['sku' => strtolower($p['sku'])]))->assertUnprocessable();
        $this->assertSame(2, DB::table('products')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_variation_reload_stock_identity_duplicate_and_archival_rules(): void
    {
        $parent = $this->postJson('/api/v1/products', $this->data())->assertCreated()->json('data');
        $data = $this->data(['parent_product_id' => $parent['id'], 'color' => 'Blue', 'variation_attributes' => ['size' => '52', 'material' => 'Titanium'], 'initial_quantity' => 3, 'price' => '110.00']);
        $variant = $this->postJson('/api/v1/products', $data)->assertCreated()->assertJsonPath('data.balance.on_hand', 3)->json('data');
        $this->getJson('/api/v1/products/'.$variant['id'])->assertOk()->assertJsonPath('data.variation_attributes.size', '52')->assertJsonPath('data.parent_product.id', $parent['id']);
        $this->getJson('/api/v1/products?family_id='.$parent['id'])->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/products', [...$data, 'creation_key' => (string) Str::uuid(), 'color' => 'blue', 'variation_attributes' => ['material' => 'titanium', 'size' => '52']])->assertUnprocessable();
        $this->postJson('/api/v1/products', $this->data(['parent_product_id' => $variant['id']]))->assertUnprocessable();
        $edit = array_diff_key($data, array_flip(['creation_key', 'initial_quantity', 'parent_product_id']));
        $this->patchJson('/api/v1/products/'.$variant['id'], [...$edit, 'version' => 1, 'price' => '115.00'])->assertOk()->assertJsonPath('data.price_minor', 11500);
        $this->patchJson('/api/v1/products/'.$variant['id'], [...$edit, 'version' => 1])->assertConflict();
        $this->patchJson('/api/v1/products/'.$variant['id'], [...$edit, 'version' => 2, 'parent_product_id' => $parent['id']])->assertUnprocessable();
        $this->patchJson('/api/v1/products/'.$parent['id'].'/status', ['version' => 1, 'status' => 'archived'])->assertConflict();
        $this->postJson('/api/v1/products/'.$variant['id'].'/movements', ['kind' => 'receipt', 'quantity' => 2, 'reason' => 'Blue delivery', 'version' => $variant['balance']['version'], 'creation_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/products/'.$parent['id'])->assertJsonPath('data.balance.on_hand', 4);
        $this->getJson('/api/v1/products/'.$variant['id'])->assertJsonPath('data.balance.on_hand', 5);
        $this->patchJson('/api/v1/products/'.$variant['id'].'/status', ['version' => 2, 'status' => 'archived'])->assertOk();
        $this->patchJson('/api/v1/products/'.$parent['id'].'/status', ['version' => 1, 'status' => 'archived'])->assertOk();
    }

    public function test_foreign_parent_and_family_ids_are_inaccessible(): void
    {
        $p = $this->postJson('/api/v1/products', $this->data())->assertCreated()->json('data');
        $other = new Shop(['name' => 'Other']);
        $other->save();
        // Simulate an ID belonging to another tenant without bypassing endpoint scopes.
        DB::table('products')->where('id', $p['id'])->update(['parent_product_id' => null]);
        $foreignCategory = DB::table('product_categories')->insertGetId(['shop_id' => $other->id, 'name' => 'Other', 'name_key' => 'other', 'status' => 'active']);
        $foreign = Product::withoutGlobalScopes()->findOrFail($p['id'])->replicate();
        $foreign->shop_id = $other->id;
        $foreign->category_id = $foreignCategory;
        $foreign->creation_key = (string) Str::uuid();
        $foreign->saveQuietly();
        $this->postJson('/api/v1/products', $this->data(['parent_product_id' => $foreign->id]))->assertNotFound();
        $this->getJson('/api/v1/products?family_id='.$foreign->id)->assertNotFound();
    }
}
