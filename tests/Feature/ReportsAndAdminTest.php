<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsAndAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Shop $shop;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'report@example.test', 'password' => 'TestingPassword123!']);
        $this->shop = new Shop(['name' => 'Reports optical', 'timezone' => 'Europe/Rome']);
        $this->shop->status = 'active';
        $this->shop->currency = 'EUR';
        $this->shop->save();
        ShopMembership::create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->login($this->owner);
        $this->customer = $this->postJson('/api/v1/customers', ['first_name' => '=Formula', 'last_name' => 'Customer', 'email' => 'private-customer@example.test', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $a = $this->sale('A', 12500, '2026-03-28 23:30:00');
        $this->sale('B', 8000, '2026-03-29 22:30:00');
        $this->sale('C', 3000, '2026-03-28 22:59:59');
        DB::table('sale_entries')->insert(['shop_id' => $this->shop->id, 'customer_id' => $this->customer, 'order_id' => $a['order'], 'kind' => 'reversal', 'currency' => 'EUR', 'price_decimals' => 2, 'amount_minor' => 12500, 'reverses_id' => $a['entry'], 'reason' => '+Formula reason', 'stock_returned' => false, 'actor_id' => $this->owner->id, 'created_at' => '2026-03-29 21:59:59']);
    }

    private function login(User $u): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/'.($u->account_type === 'admin' ? 'admin/auth' : 'auth').'/login', ['email' => $u->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
    }

    private function sale(string $number, int $amount, string $date): array
    {
        $o = DB::table('orders')->insertGetId(['shop_id' => $this->shop->id, 'customer_id' => $this->customer, 'number' => $number, 'status' => 'fulfilled', 'channel' => 'in_store', 'currency' => 'EUR', 'price_decimals' => 2, 'total_minor' => $amount, 'customer_snapshot' => json_encode(['first_name' => '=Formula', 'last_name' => 'Customer']), 'version' => 3, 'creation_key' => (string) Str::uuid(), 'creation_hash' => str_repeat('0', 64), 'created_by' => $this->owner->id, 'created_at' => $date, 'updated_at' => $date]);
        $e = DB::table('sale_entries')->insertGetId(['shop_id' => $this->shop->id, 'customer_id' => $this->customer, 'order_id' => $o, 'kind' => 'sale', 'currency' => 'EUR', 'price_decimals' => 2, 'amount_minor' => $amount, 'stock_returned' => false, 'actor_id' => $this->owner->id, 'created_at' => $date]);

        return ['order' => $o, 'entry' => $e];
    }

    public function test_posting_day_uses_shop_dst_boundaries_and_summary_reconciles_with_daily_chart(): void
    {
        $url = '/api/v1/reports/sales?from=2026-03-29&to=2026-03-29';
        $this->getJson($url)->assertOk()->assertJsonPath('summary.0.sales_minor', 12500)->assertJsonPath('summary.0.reversals_minor', 12500)->assertJsonPath('summary.0.net_minor', 0)->assertJsonCount(2, 'entries.data');
        $this->getJson('/api/v1/dashboard?from=2026-03-29&to=2026-03-29')->assertOk()->assertJsonPath('daily.0.date', '2026-03-29')->assertJsonPath('daily.0.sales_minor', 12500)->assertJsonPath('daily.0.reversals_minor', 12500)->assertJsonPath('summary.0.net_minor', 0)->assertJsonCount(1, 'daily');
        $this->getJson('/api/v1/reports/sales?from=2026-03-30&to=2026-03-30')->assertJsonPath('summary.0.sales_minor', 8000)->assertJsonCount(1, 'entries.data');
        $this->getJson('/api/v1/reports/sales?from=2026-03-29&to=2026-03-29&kind=reversal')->assertJsonPath('summary.0.net_minor', -12500);
        $this->getJson('/api/v1/dashboard?from=2026-01-01&to=2028-01-01')->assertUnprocessable();
        $this->getJson('/api/v1/reports/sales?from=2026-03-31&to=2026-03-01')->assertUnprocessable();
    }

    public function test_export_is_complete_across_pages_uses_decimal_units_and_escapes_spreadsheet_formulas(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $this->sale('BULK-'.$i, 101, '2026-03-29 12:00:00');
        }
        $this->getJson('/api/v1/reports/sales?from=2026-03-29&to=2026-03-29&page=2')->assertJsonPath('entries.total', 34)->assertJsonCount(4, 'entries.data');
        $response = $this->get('/api/v1/reports/sales/export?from=2026-03-29&to=2026-03-29&page=2')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $rows = array_map(fn ($row) => str_getcsv($row, ',', '"', ''), array_filter(explode("\n", trim($csv))));
        $this->assertCount(35, $rows);
        $this->assertStringContainsString("'=Formula Customer", $csv);
        $this->assertStringContainsString("'+Formula reason", $csv);
        $this->assertStringContainsString('125.00', $csv);
        $this->assertStringContainsString('1.01', $csv);
        $this->assertStringNotContainsString('private-customer@example.test', $csv);
        Role::where('name', 'owner')->first()->permissions()->detach(Permission::where('name', 'reports.export')->first());
        $this->getJson('/api/v1/reports/sales/export?from=2026-03-29&to=2026-03-29')->assertForbidden();
        $this->getJson('/api/v1/reports/sales?from=2026-03-29&to=2026-03-29')->assertOk();
    }

    public function test_report_tenant_scope_permissions_and_admin_boundary(): void
    {
        $other = User::factory()->create(['email' => 'otherreport@example.test', 'password' => 'TestingPassword123!']);
        $s = new Shop(['name' => 'Other shop']);
        $s->status = 'active';
        $s->save();
        ShopMembership::create(['shop_id' => $s->id, 'user_id' => $other->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->login($other);
        $this->getJson('/api/v1/reports/sales?from=2026-03-29&to=2026-03-29')->assertOk()->assertJsonCount(0, 'entries.data');
        $this->getJson('/api/v1/reports/sales?from=2026-03-29&to=2026-03-29&customer_id='.$this->customer)->assertNotFound();
        $this->getJson('/api/v1/admin/operations')->assertForbidden();
        $this->getJson('/api/v1/admin/audit')->assertForbidden();
        Role::where('name', 'owner')->first()->permissions()->detach(Permission::where('name', 'reports.read')->first());
        $this->getJson('/api/v1/dashboard?from=2026-03-29&to=2026-03-29')->assertForbidden();
        $admin = User::factory()->create(['account_type' => 'admin', 'email' => 'adminreport@example.test', 'password' => 'TestingPassword123!']);
        $this->login($admin);
        $this->getJson('/api/v1/reports/sales?from=2026-03-29&to=2026-03-29')->assertForbidden();
    }

    public function test_admin_audit_is_redacted_filtered_bounded_and_operations_do_not_claim_worker_health(): void
    {
        $admin = User::factory()->create(['account_type' => 'admin', 'email' => 'auditadmin@example.test', 'password' => 'TestingPassword123!']);
        $this->login($admin);
        AuditEvent::create(['shop_id' => $this->shop->id, 'actor_id' => $this->owner->id, 'action' => 'order.fulfil', 'subject_type' => 'order', 'subject_id' => 1]);
        $result = $this->getJson('/api/v1/admin/audit?shop_id='.$this->shop->id.'&action=order.fulfil')->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertEqualsCanonicalizing(['id', 'shop_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'request_id', 'created_at'], array_keys($result['data'][0]));
        $this->getJson('/api/v1/admin/audit?action=%25')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/audit?page=0')->assertUnprocessable();
        $r = $this->getJson('/api/v1/admin/operations')->assertOk()->assertJsonPath('data.database', 'reachable')->assertJsonPath('data.worker_verification', 'synchronous')->assertJsonPath('data.scanner_verification', 'not_checked');
        $this->assertStringNotContainsString('private-customer@example.test', $r->getContent());
        $this->assertStringNotContainsString('=Formula', $r->getContent());
        Role::where('name', 'system_admin')->first()->permissions()->detach(Permission::where('name', 'platform.audit')->first());
        $this->getJson('/api/v1/admin/audit')->assertForbidden();
        $admin->email_verified_at = null;
        $admin->save();
        Auth::forgetGuards();
        $this->getJson('/api/v1/admin/operations')->assertForbidden();
    }
}
