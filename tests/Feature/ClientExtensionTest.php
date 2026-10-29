<?php

namespace Tests\Feature;

use App\Jobs\Middleware\WithTenant;
use App\Jobs\SendCustomerMessage;
use App\Models\CustomerMessage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class ClientExtensionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ShopMembership $membership;

    private int $customer;

    private const PASS = 'TestingPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Http::preventStrayRequests();
        config(['optical.email_verification_required' => false]);
        $this->owner = User::factory()->create(['password' => self::PASS]);
        $shop = Shop::factory()->create(['status' => 'active', 'currency' => 'EUR', 'timezone' => 'Europe/Rome']);
        $this->membership = ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $this->owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->login($this->owner);
        $this->customer = $this->postJson('/api/v1/customers', ['first_name' => 'Extension', 'last_name' => 'Customer', 'email' => 'customer@example.test', 'phone' => '+393331234567', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function login(User $user): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/'.($user->account_type === 'admin' ? 'admin/auth' : 'auth').'/login', ['email' => $user->email, 'password' => self::PASS])->assertOk();
        Auth::forgetGuards();
    }

    private function within(callable $fn)
    {
        return (new WithTenant($this->membership->shop_id, $this->owner->id))->handle(new \stdClass, $fn);
    }

    public function test_bulk_messages_are_atomic_personalized_and_idempotent(): void
    {
        $second = $this->postJson('/api/v1/customers', ['first_name' => 'Second', 'last_name' => 'Person', 'email' => 'second@example.test', 'phone' => '+393331234568', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        foreach (['sms', 'email'] as $channel) {
            $payload = ['customer_ids' => [$this->customer, $second], 'channel' => $channel, 'purpose' => 'service', 'subject' => 'Hello {{first_name}}', 'body' => 'Welcome {{first_name}}', 'creation_key' => (string) Str::uuid()];
            $this->postJson('/api/v1/communications/bulk/preview', $payload)->assertOk()->assertJsonPath('data.eligible', 0);
            $this->postJson('/api/v1/communications/bulk', $payload)->assertUnprocessable();
            foreach ($payload['customer_ids'] as $id) $this->postJson('/api/v1/customers/'.$id.'/consents', ['channel' => $channel, 'purpose' => 'service', 'granted' => true, 'evidence' => 'Customer requested service updates'])->assertOk();
            $this->postJson('/api/v1/communications/bulk/preview', $payload)->assertOk()->assertJsonPath('data.eligible', 2);
            $batch = $this->postJson('/api/v1/communications/bulk', $payload)->assertCreated()->assertJsonCount(2, 'messages')->json('data');
            $this->postJson('/api/v1/communications/bulk', $payload)->assertCreated()->assertJsonPath('data.id', $batch['id']);
            $this->postJson('/api/v1/communications/bulk', [...$payload, 'body' => 'Changed'])->assertConflict();
            $this->assertDatabaseHas('customer_messages', ['customer_id' => $second, 'channel' => $channel, 'body' => 'Welcome Second']);
        }
        $this->assertDatabaseCount('customer_messages', 4);
        $this->assertDatabaseCount('communication_batches', 2);
        Http::assertNothingSent();
    }

    public function test_bulk_cannot_message_foreign_customers_or_whatsapp_or_unconsented_recipients(): void
    {
        $p = ['customer_ids' => [$this->customer, 999999], 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Hello', 'creation_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/communications/bulk/preview', $p)->assertNotFound();
        $this->postJson('/api/v1/communications/bulk', $p)->assertNotFound();
        $this->postJson('/api/v1/communications/bulk', [...$p, 'channel' => 'whatsapp'])->assertUnprocessable();
        $this->postJson('/api/v1/communications/bulk', [...$p, 'customer_ids' => [$this->customer, $this->customer]])->assertUnprocessable();
        $this->postJson('/api/v1/communications/bulk', [...$p, 'customer_ids' => [$this->customer]])->assertUnprocessable();
        $this->assertDatabaseCount('customer_messages', 0);
        $this->assertDatabaseCount('communication_batches', 0);
    }

    public function test_sms_supports_a_sender_without_a_messaging_service_and_email_requires_tls_port(): void
    {
        config(['communications.enabled' => true, 'communications.public_url' => 'https://crm.example.test', 'communications.twilio.account_sid' => 'AC'.str_repeat('a',32), 'communications.twilio.auth_token' => 'test-only', 'communications.twilio.messaging_service_sid' => null, 'communications.twilio.sms_from' => '+393331111111']);
        $provider = app(\App\Services\Communications\Provider::class);
        $this->assertTrue($provider->ready('sms'));
        $this->assertFalse($provider->ready('whatsapp'));
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM'.str_repeat('b',32)], 201)]);
        $result = $provider->send(new CustomerMessage(['channel' => 'sms', 'recipient' => '+393331234567', 'body' => 'Hello', 'public_id' => (string) Str::uuid()]));
        $this->assertSame('accepted', $result['status']);
        Http::assertSent(fn($r) => $r['From'] === '+393331111111' && $r['Body'] === 'Hello' && !isset($r['MessagingServiceSid']));
        config(['communications.aruba.username' => 'mail@example.test', 'communications.aruba.password' => 'test-only', 'communications.aruba.from_address' => 'mail@example.test', 'communications.aruba.host' => 'smtps.aruba.it', 'communications.aruba.port' => 465]);
        $this->assertTrue($provider->ready('email'));
        config(['communications.aruba.port' => 25]);
        $this->assertFalse($provider->ready('email'));
    }

    public function test_bulk_preview_rejects_duplicate_addresses_and_backend_permissions(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $second = $this->postJson('/api/v1/customers', ['first_name' => 'Other', 'last_name' => 'Contact', 'phone' => '+393331234567', 'creation_key' => (string) Str::uuid(), 'confirm_distinct' => true])->assertCreated()->json('data.id');
        foreach ([$this->customer, $second] as $id) $this->postJson('/api/v1/customers/'.$id.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Customer requested updates'])->assertOk();
        $p = ['customer_ids' => [$this->customer, $second], 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Hello', 'creation_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/communications/bulk/preview', $p)->assertOk()->assertJsonPath('data.rows.1.reason', 'duplicate_recipient');
        $this->postJson('/api/v1/communications/bulk', $p)->assertUnprocessable();
        $this->assertDatabaseCount('customer_messages', 0);
        $employee = User::factory()->create(['password' => self::PASS]);
        ShopMembership::create(['shop_id' => $this->membership->shop_id, 'user_id' => $employee->id, 'role_id' => Role::where('name', 'salesperson')->value('id'), 'permission_overrides' => ['communications.send' => false]]);
        $this->login($employee);
        $this->postJson('/api/v1/communications/bulk/preview', $p)->assertForbidden();
        $this->postJson('/api/v1/communications/bulk', $p)->assertForbidden();
    }

    public function test_employee_invitation_overrides_suspension_and_owner_protection(): void
    {
        $payload = ['name' => 'Sales Employee', 'email' => 'employee@example.test', 'role' => 'salesperson', 'status' => 'active', 'permission_overrides' => ['products.read' => false]];
        $invite = $this->postJson('/api/v1/employees', $payload)->assertCreated()->json();
        $this->assertNotEmpty($invite['invitation_token']);
        $this->assertNotSame($invite['invitation_token'], DB::table('shop_memberships')->where('id', $invite['data']['id'])->value('invitation_hash'));
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $accept = ['token' => $invite['invitation_token'], 'password' => self::PASS, 'password_confirmation' => self::PASS];
        $this->postJson('/api/v1/auth/accept-invitation', $accept)->assertOk();
        $this->postJson('/api/v1/auth/accept-invitation', $accept)->assertUnprocessable();
        $employee = User::where('email', $payload['email'])->firstOrFail();
        $this->login($employee);
        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/operations')->assertOk();
        $this->postJson('/api/v1/customers', ['first_name' => 'Unauthorized', 'last_name' => 'New', 'creation_key' => (string) Str::uuid()])->assertForbidden();
        $this->login($this->owner);
        $this->patchJson('/api/v1/employees/'.$this->membership->id, ['name' => 'Owner', 'role' => 'cashier', 'status' => 'suspended', 'permission_overrides' => [], 'version' => 1])->assertForbidden();
        $this->patchJson('/api/v1/employees/'.$invite['data']['id'], ['name' => 'Sales Employee', 'role' => 'salesperson', 'status' => 'suspended', 'permission_overrides' => [], 'version' => 2])->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $employee->email, 'password' => self::PASS])->assertUnprocessable();
    }

    public function test_payment_advances_partial_payments_reversal_idempotency_and_dashboard(): void
    {
        $r = $this->postJson('/api/v1/receivables', ['customer_id' => $this->customer, 'reference' => 'External invoice 100', 'amount' => '120.50', 'due_on' => now()->toDateString(), 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $p = ['amount' => '20.50', 'method' => 'cash', 'paid_on' => now()->toDateString(), 'version' => $r['version'], 'creation_key' => (string) Str::uuid()];
        $paid = $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', $p)->assertOk()->assertJsonPath('data.balance_minor', 10000)->assertJsonPath('data.effective_status', 'partial')->json('data');
        $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', $p)->assertOk()->assertJsonCount(1, 'data.entries');
        $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', [...$p, 'creation_key' => (string) Str::uuid(), 'version' => 2, 'amount' => '100.01'])->assertUnprocessable();
        $reverse = ['reverses_id' => $paid['entries'][0]['id'], 'reason' => 'Cash entry corrected', 'paid_on' => now()->toDateString(), 'version' => 2, 'creation_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', $reverse)->assertOk()->assertJsonPath('data.balance_minor', 12050);
        $this->getJson('/api/v1/operations?filter=payment&bucket=today')->assertOk()->assertJsonPath('counts.payment', 1)->assertJsonPath('activities.total', 1);
        $this->getJson('/api/v1/activity')->assertOk();
    }

    public function test_messages_require_consent_and_remain_blocked_without_platform_credentials(): void
    {
        $m = ['customer_id' => $this->customer, 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Hello {{first_name}}', 'creation_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/communications/messages', $m)->assertUnprocessable();
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Customer signed the service messaging form'])->assertOk();
        $row = $this->postJson('/api/v1/communications/messages', $m)->assertCreated()->json('data');
        $this->postJson('/api/v1/communications/messages', $m)->assertCreated()->assertJsonPath('data.id', $row['id']);
        app()->call([new SendCustomerMessage($this->membership->shop_id, $this->owner->id, $row['id']), 'handle']);
        $this->assertDatabaseHas('customer_messages', ['id' => $row['id'], 'status' => 'blocked', 'attempts' => 0]);
        Http::assertNothingSent();
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => false, 'evidence' => 'Customer withdrew consent'])->assertOk();
        $this->assertDatabaseHas('customer_messages', ['id' => $row['id'], 'status' => 'cancelled']);
    }

    public function test_twilio_submission_signed_callbacks_deduplication_and_out_of_order_events(): void
    {
        config(['communications.enabled' => true, 'communications.public_url' => 'https://optical.example.test', 'communications.twilio.account_sid' => 'AC'.str_repeat('a', 32), 'communications.twilio.auth_token' => 'test-secret', 'communications.twilio.messaging_service_sid' => 'MG'.str_repeat('b', 32)]);
        $sid = 'SM'.str_repeat('c', 32);
        Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => $sid, 'status' => 'queued'], 201)]);
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Signed consent'])->assertOk();
        $row = $this->postJson('/api/v1/communications/messages', ['customer_id' => $this->customer, 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Ready for collection', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $job = new SendCustomerMessage($this->membership->shop_id, $this->owner->id, $row['id']);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $path = '/api/v1/communications/callback/'.$row['public_id'];
        $params = ['AccountSid' => config('communications.twilio.account_sid'), 'To' => '+393331234567', 'MessageSid' => $sid, 'MessageStatus' => 'delivered'];
        $this->post($path, $params)->assertForbidden();
        foreach (['delivered', 'sent', 'delivered'] as $status) {
            $params['MessageStatus'] = $status;
            $signature = (new RequestValidator('test-secret'))->computeSignature('https://optical.example.test'.$path, $params);
            $this->withHeaders(['X-Twilio-Signature' => $signature, 'Content-Type' => 'application/x-www-form-urlencoded'])->post($path, $params)->assertNoContent();
        }
        $this->assertDatabaseHas('customer_messages', ['id' => $row['id'], 'status' => 'delivered', 'provider_id' => $sid]);
        $this->assertSame(1, DB::table('message_events')->where('message_id', $row['id'])->where('status', 'delivered')->count());
    }

    public function test_reminder_calendar_months_and_tenant_isolation(): void
    {
        $id = DB::table('optical_records')->insertGetId(['shop_id' => $this->membership->shop_id, 'customer_id' => $this->customer, 'kind' => 'optometry', 'status' => 'finalized', 'recorded_on' => '2026-01-31', 'practitioner' => 'Test optician', 'data' => '{}', 'created_by' => $this->owner->id, 'creation_key' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->postJson('/api/v1/reminders', ['customer_id' => $this->customer, 'kind' => 'checkup', 'title' => 'Next check-up', 'months' => 3, 'optical_record_id' => $id, 'lead_days' => 7, 'creation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.due_on', '2026-04-30')->json('data');
        $this->getJson('/api/v1/operations?filter=checkup')->assertOk()->assertJsonPath('counts.checkup', 1);
        $other = Shop::factory()->create(['status' => 'active']);
        $u = User::factory()->create(['password' => self::PASS]);
        ShopMembership::create(['shop_id' => $other->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->login($u);
        $this->getJson('/api/v1/reminders')->assertOk()->assertJsonPath('total', 0);
        $this->patchJson('/api/v1/reminders/'.$r['id'].'/status', ['version' => 1, 'status' => 'completed', 'reason' => 'Wrong tenant'])->assertNotFound();
        $this->getJson('/api/v1/customers/'.$this->customer.'/consents')->assertNotFound();
    }

    private function orderFixture(bool $fulfilled = true): array
    {
        $category = $this->postJson('/api/v1/product-categories', ['name' => 'Frames '.Str::random(6), 'status' => 'active'])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/v1/products', ['name' => 'Review frame', 'sku' => Str::random(12), 'brand' => 'Test', 'model' => 'Frame', 'color' => 'Black', 'description' => 'Test fixture', 'category_id' => $category, 'price' => '100.00', 'low_stock_threshold' => 1, 'initial_quantity' => 5, 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        DB::table('products')->where('id', $product)->update(['status' => 'active']);
        $o = $this->postJson('/api/v1/orders', ['customer_id' => $this->customer, 'items' => [['product_id' => $product, 'quantity' => 1]], 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        foreach ($fulfilled ? ['confirm', 'fulfil'] : ['confirm'] as $action) {
            $o = $this->postJson('/api/v1/orders/'.$o['id'].'/'.$action, ['version' => $o['version'], 'creation_key' => (string) Str::uuid()])->assertOk()->json('data');
        }

        return [$o, $category];
    }

    public function test_scheduler_deduplicates_followups_and_replaces_only_unsent_rule_messages(): void
    {
        [$o, $category] = $this->orderFixture();
        DB::table('orders')->where('id', $o['id'])->update(['fulfilled_at' => now()->subDays(8)]);
        $template = $this->postJson('/api/v1/communications/templates', ['name' => 'After delivery', 'channel' => 'sms', 'purpose' => 'service', 'body' => '{{shop_name}}: How are your glasses, {{first_name}}? {{reference}}', 'active' => true])->assertOk()->json('data.id');
        $data = ['name' => 'Seven days after glasses delivery', 'trigger' => 'delivery', 'category_id' => $category, 'offset' => 7, 'unit' => 'days', 'starts_on' => now()->subDays(30)->toDateString(), 'active' => true, 'template_id' => $template, 'lead_days' => 0, 'creation_key' => (string) Str::uuid()];
        $rule = $this->postJson('/api/v1/followup-rules', $data)->assertOk()->json('data');
        $this->postJson('/api/v1/followup-rules', $data)->assertOk()->assertJsonPath('data.id', $rule['id']);
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseCount('reminders', 1);
        $this->assertDatabaseCount('customer_messages', 0);
        $this->assertDatabaseHas('reminders', ['rule_id' => $rule['id'], 'automation_error' => 'check_consent_contact_template_or_sender']);
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Recorded service consent'])->assertOk();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseCount('reminders', 1);
        $this->assertDatabaseCount('customer_messages', 1);
        $old = DB::table('customer_messages')->first();
        $this->assertStringContainsString($o['number'], $old->body);
        unset($data['trigger'], $data['creation_key']);
        $this->patchJson('/api/v1/followup-rules/'.$rule['id'], [...$data, 'name' => 'Updated follow-up', 'version' => 1])->assertOk();
        $this->assertDatabaseHas('customer_messages', ['id' => $old->id, 'status' => 'cancelled']);
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseCount('reminders', 1);
        $this->assertDatabaseCount('customer_messages', 2);
        $this->within(function () {
            CustomerMessage::where('status', 'scheduled')->update(['status' => 'delivered']);
        });
        $this->patchJson('/api/v1/followup-rules/'.$rule['id'], [...$data, 'name' => 'Still no duplicate delivery', 'version' => 2])->assertOk();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseCount('customer_messages', 2);
        $this->postJson('/api/v1/orders/'.$o['id'].'/reverse', ['version' => $o['version'], 'creation_key' => (string) Str::uuid(), 'reason' => 'Full order returned', 'return_stock' => true])->assertOk();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseHas('reminders', ['rule_id' => $rule['id'], 'status' => 'cancelled']);
    }

    public function test_cancelled_order_advance_becomes_refund_work_until_corrected(): void
    {
        [$o] = $this->orderFixture(false);
        $r = $this->postJson('/api/v1/receivables', ['customer_id' => $this->customer, 'order_id' => $o['id'], 'reference' => 'Advance on '.$o['number'], 'due_on' => now()->addDays(5)->toDateString(), 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $p = $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', ['amount' => '25.00', 'method' => 'cash', 'paid_on' => now()->toDateString(), 'version' => 1, 'creation_key' => (string) Str::uuid()])->assertOk()->json('data');
        $this->postJson('/api/v1/orders/'.$o['id'].'/cancel', ['version' => $o['version'], 'creation_key' => (string) Str::uuid(), 'reason' => 'Customer cancellation'])->assertOk();
        $this->getJson('/api/v1/receivables/'.$r['id'])->assertOk()->assertJsonPath('data.refund_due_minor', 2500)->assertJsonPath('data.balance_minor', 0);
        $this->getJson('/api/v1/operations?filter=refund_due&bucket=today')->assertOk()->assertJsonPath('counts.refund_due', 1)->assertJsonPath('activities.total', 1);
        $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', ['reverses_id' => $p['entries'][0]['id'], 'reason' => 'Advance refunded in cash', 'paid_on' => now()->toDateString(), 'version' => 2, 'creation_key' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/v1/operations?filter=refund_due')->assertOk()->assertJsonPath('counts.refund_due', 0)->assertJsonPath('activities.total', 0);
    }

    public function test_uncertain_delivery_cannot_resend_and_provider_review_is_audited(): void
    {
        config(['communications.enabled' => true, 'communications.public_url' => 'https://optical.example.test', 'communications.twilio.account_sid' => 'AC'.str_repeat('a', 32), 'communications.twilio.auth_token' => 'test-secret', 'communications.twilio.messaging_service_sid' => 'MG'.str_repeat('b', 32)]);
        Http::fake(['https://api.twilio.com/*' => Http::response([], 503)]);
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Signed service consent'])->assertOk();
        $m = $this->postJson('/api/v1/communications/messages', ['customer_id' => $this->customer, 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Follow-up test', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $job = new SendCustomerMessage($this->membership->shop_id, $this->owner->id, $m['id']);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('customer_messages', ['id' => $m['id'], 'status' => 'uncertain']);
        $this->postJson('/api/v1/communications/messages/'.$m['id'].'/retry', ['version' => 1, 'reason' => 'Attempt blind retry'])->assertConflict();
        $this->postJson('/api/v1/communications/messages/'.$m['id'].'/resolve', ['version' => 1, 'reason' => 'Provider support confirmed no accepted message'])->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->getJson('/api/v1/communications/messages/'.$m['id'])->assertOk()->assertJsonFragment(['reason' => 'Provider support confirmed no accepted message', 'actor_id' => $this->owner->id]);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
    }

    public function test_recipient_opt_out_overrides_shop_consent_and_start_does_not_invent_consent(): void
    {
        config(['communications.public_url' => 'https://optical.example.test', 'communications.twilio.account_sid' => 'AC'.str_repeat('a', 32), 'communications.twilio.auth_token' => 'test-secret']);
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Signed consent'])->assertOk();
        $path = '/api/v1/communications/inbound';
        $params = ['AccountSid' => config('communications.twilio.account_sid'), 'From' => '+393331234567', 'Body' => 'STOP', 'OptOutType' => 'STOP'];
        $signature = (new RequestValidator('test-secret'))->computeSignature('https://optical.example.test'.$path, $params);
        $this->withHeaders(['X-Twilio-Signature' => $signature, 'Content-Type' => 'application/x-www-form-urlencoded'])->post($path, $params)->assertOk();
        $this->postJson('/api/v1/communications/messages', ['customer_id' => $this->customer, 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Must not send', 'creation_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => false, 'evidence' => 'Customer withdrew consent'])->assertOk();
        $params['Body'] = 'START';
        $params['OptOutType'] = 'START';
        $signature = (new RequestValidator('test-secret'))->computeSignature('https://optical.example.test'.$path, $params);
        $this->withHeaders(['X-Twilio-Signature' => $signature])->post($path, $params)->assertOk();
        $this->postJson('/api/v1/communications/messages', ['customer_id' => $this->customer, 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Still no shop consent', 'creation_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/v1/customers/'.$this->customer.'/consents')->assertOk()->assertJsonCount(2, 'history');
    }

    public function test_admin_extension_is_tenant_scoped_and_export_is_explicitly_authorized(): void
    {
        $admin = User::factory()->create(['account_type' => 'admin', 'password' => self::PASS]);
        $this->login($admin);
        $base = '/api/v1/admin/shops/'.$this->membership->shop_id.'/workspace';
        $other = Shop::factory()->create(['status' => 'active']);
        $id = $this->postJson($base.'/reminders', ['customer_id' => $this->customer, 'kind' => 'custom', 'title' => 'Call customer', 'due_on' => now()->toDateString(), 'lead_days' => 0, 'creation_key' => (string) Str::uuid()])->assertOk()->json('data.id');
        $this->getJson($base.'/operations')->assertOk()->assertJsonPath('counts.custom', 1);
        $this->patchJson('/api/v1/admin/shops/'.$other->id.'/workspace/reminders/'.$id.'/status', ['version' => 1, 'status' => 'completed', 'reason' => 'Wrong shop'])->assertNotFound();
        $this->getJson($base.'/record-output/customers?purpose=export')->assertOk()->assertJsonStructure(['csv', 'filename']);
        Role::where('name', 'system_admin')->first()->permissions()->detach(Permission::where('name', 'shops.customers.export')->value('id'));
        $this->getJson($base.'/record-output/customers?purpose=export')->assertForbidden();
        $this->getJson($base.'/customers')->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'customers.export', 'actor_id' => $admin->id, 'shop_id' => $this->membership->shop_id]);
    }

    public function test_granular_denials_do_not_disable_allowed_read_or_edit_operations(): void
    {
        $employee = User::factory()->create(['password' => self::PASS]);
        ShopMembership::create(['shop_id' => $this->membership->shop_id, 'user_id' => $employee->id, 'role_id' => Role::where('name', 'store_administrator')->value('id'), 'permission_overrides' => ['customers.write' => true, 'customers.edit' => true, 'customers.create' => false, 'customers.archive' => false, 'customers.export' => false, 'customers.print' => false]]);
        $this->login($employee);
        $this->getJson('/api/v1/customers')->assertOk();
        $this->patchJson('/api/v1/customers/'.$this->customer, ['first_name' => 'Edited', 'last_name' => 'Customer', 'version' => 1])->assertOk();
        $this->postJson('/api/v1/customers', ['first_name' => 'Denied', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertForbidden();
        $this->patchJson('/api/v1/customers/'.$this->customer.'/status', ['status' => 'archived', 'version' => 2, 'reason' => 'Denied archive'])->assertForbidden();
        $this->getJson('/api/v1/record-output/customers?purpose=print')->assertForbidden();
        $this->getJson('/api/v1/record-output/customers?purpose=export')->assertForbidden();
        $this->getJson('/api/v1/customers/'.$this->customer.'?purpose=print')->assertForbidden();
    }

    private function careFixture(string $day): int
    {
        return DB::table('optical_records')->insertGetId(['shop_id' => $this->membership->shop_id, 'customer_id' => $this->customer, 'kind' => 'optometry', 'status' => 'finalized', 'recorded_on' => $day, 'practitioner' => 'Test optician', 'data' => '{}', 'created_by' => $this->owner->id, 'creation_key' => (string) Str::uuid(), 'creation_hash' => str_repeat('b', 64), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_followups_use_completed_visits_latest_examination_and_latest_purchase(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $old = $this->careFixture('2026-04-15');
        [$order] = $this->orderFixture();
        DB::table('orders')->where('id', $order['id'])->update(['fulfilled_at' => '2025-10-15 10:00:00']);
        $appointment = $this->postJson('/api/v1/appointments', ['title' => 'Follow-up source visit', 'customer_id' => $this->customer, 'starts_at' => '2026-09-15T09:00:00+02:00', 'ends_at' => '2026-09-15T09:30:00+02:00', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        foreach (['visit' => [30, 'days'], 'examination' => [6, 'months'], 'inactive' => [12, 'months']] as $trigger => [$offset, $unit]) {
            $this->postJson('/api/v1/followup-rules', ['name' => $trigger.' rule', 'trigger' => $trigger, 'offset' => $offset, 'unit' => $unit, 'starts_on' => '2026-10-01', 'active' => true, 'lead_days' => 0, 'creation_key' => (string) Str::uuid()])->assertOk();
        }
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseCount('reminders', 2); // A scheduled appointment is not a visit.
        $this->patchJson('/api/v1/appointments/'.$appointment.'/status', ['status' => 'completed', 'version' => 1, 'reason' => 'Customer attended'])->assertOk();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseCount('reminders', 3);
        $this->getJson('/api/v1/operations?filter=followup')->assertOk()->assertJsonPath('counts.followup', 1)->assertJsonPath('activities.total', 3);
        $this->assertSame(3, DB::table('reminders')->where('due_on', '2026-10-15')->where('status', 'open')->count());
        $new = $this->careFixture('2026-10-15');
        [$newOrder] = $this->orderFixture();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $this->assertDatabaseHas('reminders', ['optical_record_id' => $old, 'status' => 'cancelled']);
        $this->assertDatabaseHas('reminders', ['order_id' => $order['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('reminders', ['optical_record_id' => $new, 'status' => 'open', 'due_on' => '2027-04-15']);
        $this->assertDatabaseHas('reminders', ['order_id' => $newOrder['id'], 'status' => 'open', 'due_on' => '2027-10-15']);
        $this->assertDatabaseHas('reminders', ['appointment_id' => $appointment, 'status' => 'open']);
    }

    public function test_clinical_followup_permission_cannot_be_bypassed_by_known_reminder_id(): void
    {
        $care = $this->careFixture(now()->subMonths(6)->toDateString());
        $this->postJson('/api/v1/followup-rules', ['name' => 'Clinical follow-up', 'trigger' => 'examination', 'offset' => 6, 'unit' => 'months', 'starts_on' => now()->subDay()->toDateString(), 'active' => true, 'lead_days' => 0, 'creation_key' => (string) Str::uuid()])->assertOk();
        $this->artisan('optical:run-operations')->assertSuccessful();
        $id = DB::table('reminders')->where('optical_record_id', $care)->value('id');
        $employee = User::factory()->create(['password' => self::PASS]);
        ShopMembership::create(['shop_id' => $this->membership->shop_id, 'user_id' => $employee->id, 'role_id' => Role::where('name', 'store_administrator')->value('id'), 'permission_overrides' => ['optical.read' => false, 'reminders.read' => true, 'reminders.write' => true, 'followups.read' => true, 'communications.send' => true, 'customers.read' => true]]);
        $this->login($employee);
        $this->getJson('/api/v1/reminders')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/followup-rules')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/reminders/'.$id.'/status', ['status' => 'completed', 'version' => 1, 'reason' => 'Guessed protected reminder'])->assertForbidden();
        $this->postJson('/api/v1/communications/messages', ['customer_id' => $this->customer, 'reminder_id' => $id, 'channel' => 'sms', 'purpose' => 'service', 'body' => '{{due_date}}', 'creation_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_throttled_delivery_retries_once_due_and_revoked_sender_cannot_deliver(): void
    {
        config(['communications.enabled' => true, 'communications.public_url' => 'https://optical.example.test', 'communications.twilio.account_sid' => 'AC'.str_repeat('a', 32), 'communications.twilio.auth_token' => 'test-secret', 'communications.twilio.messaging_service_sid' => 'MG'.str_repeat('b', 32)]);
        Http::fake(['https://api.twilio.com/*' => Http::sequence()->push(['code' => 20429], 429)->push(['sid' => 'SM'.str_repeat('d', 32)], 201)]);
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', ['channel' => 'sms', 'purpose' => 'service', 'granted' => true, 'evidence' => 'Signed consent'])->assertOk();
        $d = ['customer_id' => $this->customer, 'channel' => 'sms', 'purpose' => 'service', 'body' => 'Safe retry test', 'creation_key' => (string) Str::uuid()];
        $m = $this->postJson('/api/v1/communications/messages', $d)->assertCreated()->json('data');
        $job = new SendCustomerMessage($this->membership->shop_id, $this->owner->id, $m['id']);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('customer_messages', ['id' => $m['id'], 'status' => 'retry_wait']);
        $this->travel(3)->minutes();
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('customer_messages', ['id' => $m['id'], 'status' => 'accepted', 'attempts' => 2]);
        $m2 = $this->postJson('/api/v1/communications/messages', [...$d, 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->membership->update(['status' => 'suspended']);
        app()->call([new SendCustomerMessage($this->membership->shop_id, $this->owner->id, $m2['id']), 'handle']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('customer_messages', ['id' => $m2['id'], 'status' => 'cancelled', 'error_code' => 'access_revoked']);
    }

    public function test_safe_csv_printed_ledger_and_shop_local_payment_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T23:30:00Z'));
        $r = $this->postJson('/api/v1/receivables', ['customer_id' => $this->customer, 'reference' => '=FORMULA()', 'amount' => '120.50', 'due_on' => '2026-10-16', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', ['amount' => '20.50', 'method' => 'cash', 'paid_on' => '2026-10-16', 'version' => 1, 'creation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/receivables/'.$r['id'].'/payments', ['amount' => '1.00', 'method' => 'cash', 'paid_on' => '2026-10-17', 'version' => 2, 'creation_key' => (string) Str::uuid()])->assertUnprocessable();
        $csv = $this->getJson('/api/v1/record-output/payments?purpose=export&record_id='.$r['id'])->assertOk()->json('csv');
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->assertStringContainsString('12050', $csv);
        $this->getJson('/api/v1/record-output/payments?purpose=print&record_id='.$r['id'])->assertOk()->assertJsonPath('rows.0.4', 'EUR 120.50')->assertJsonPath('ledger.0.2', 'EUR 20.50')->assertJsonPath('ledger.0.0', '2026-10-16');
        $this->getJson('/api/v1/operations?filter=payment&bucket=today')->assertOk()->assertJsonPath('activities.total', 1);
    }
}
