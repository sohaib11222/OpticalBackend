<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerMessage;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contract coverage for the current customer-contact policy:
 * email is sent by the CRM; WhatsApp is opened by the browser; SMS is not a
 * CRM delivery channel.  Older Twilio callback tests intentionally do not
 * belong here because that integration was retired.
 */
class ClientExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'TestingPassword123!';

    private User $owner;
    private ShopMembership $membership;
    private int $customer;
    private int $sent = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['optical.email_verification_required' => false, 'communications.email_sync' => true]);
        $this->owner = User::factory()->create(['password' => self::PASS]);
        $shop = Shop::factory()->create(['status' => 'active']);
        $this->membership = ShopMembership::create([
            'shop_id' => $shop->id,
            'user_id' => $this->owner->id,
            'role_id' => Role::where('name', 'owner')->value('id'),
        ]);
        $this->app->instance(Provider::class, new class($this) extends Provider {
            public function __construct(private ClientExtensionTest $test) {}
            public function ready(string $channel): bool { return $channel === 'email'; }
            public function send(\App\Models\CustomerMessage $message): array
            {
                $this->test->recordSend();
                return ['status' => 'sent', 'provider_id' => 'smtp:'.$message->public_id];
            }
        });
        $this->login($this->owner);
        $this->customer = $this->postJson('/api/v1/customers', [
            'first_name' => 'Extension', 'last_name' => 'Customer',
            'email' => 'customer@example.test', 'phone' => '+393331234567',
            'creation_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
    }

    public function recordSend(): void
    {
        $this->sent++;
    }

    private function login(User $user): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASS])->assertOk();
        Auth::forgetGuards();
    }

    public function test_email_sends_immediately_without_a_consent_record_and_sms_is_rejected(): void
    {
        $email = [
            'customer_id' => $this->customer, 'channel' => 'email', 'purpose' => 'service',
            'subject' => 'Your collection is ready', 'body' => 'Hello {{first_name}}',
            'creation_key' => (string) Str::uuid(),
        ];
        $this->postJson('/api/v1/communications/messages', $email)
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.body', 'Hello Extension');
        $this->assertSame(1, $this->sent);
        $this->assertDatabaseCount('communication_consents', 0);

        $this->postJson('/api/v1/communications/messages', [...$email, 'channel' => 'sms', 'creation_key' => (string) Str::uuid()])
            ->assertUnprocessable();
        $this->postJson('/api/v1/communications/messages', [...$email, 'channel' => 'whatsapp', 'creation_key' => (string) Str::uuid()])
            ->assertUnprocessable();
    }

    public function test_scheduled_email_waits_until_due_then_uses_the_same_safe_delivery_job(): void
    {
        $scheduled = now()->addMinutes(10);
        $row = $this->postJson('/api/v1/communications/messages', [
            'customer_id' => $this->customer, 'channel' => 'email', 'purpose' => 'service',
            'subject' => 'Future check-up', 'body' => 'Hello', 'scheduled_at' => $scheduled->toIso8601String(),
            'creation_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');
        $this->assertSame(0, $this->sent);

        $this->travelTo($scheduled->addSecond());
        app()->call([new SendCustomerMessage($this->membership->shop_id, $this->owner->id, $row['id']), 'handle']);
        $this->assertDatabaseHas('customer_messages', ['id' => $row['id'], 'status' => 'sent']);
        $this->assertSame(1, $this->sent);
    }

    public function test_bulk_email_is_personalized_idempotent_and_excludes_duplicate_addresses(): void
    {
        $second = $this->postJson('/api/v1/customers', [
            'first_name' => 'Second', 'last_name' => 'Person', 'email' => 'second@example.test',
            'creation_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $payload = [
            'customer_ids' => [$this->customer, $second], 'channel' => 'email', 'purpose' => 'service',
            'subject' => 'Hello {{first_name}}', 'body' => 'Welcome {{first_name}}',
            'creation_key' => (string) Str::uuid(),
        ];
        $this->postJson('/api/v1/communications/bulk/preview', $payload)->assertOk()->assertJsonPath('data.eligible', 2);
        $batch = $this->postJson('/api/v1/communications/bulk', $payload)
            ->assertCreated()->assertJsonCount(2, 'messages')->json('data');
        $this->postJson('/api/v1/communications/bulk', $payload)->assertCreated()->assertJsonPath('data.id', $batch['id']);
        $this->assertSame(2, $this->sent);
        $this->assertDatabaseHas('customer_messages', ['customer_id' => $second, 'body' => 'Welcome Second', 'status' => 'sent']);

        $duplicate = $this->postJson('/api/v1/customers', [
            'first_name' => 'Duplicate', 'last_name' => 'Address', 'email' => 'customer@example.test',
            'confirm_distinct' => true, 'creation_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/communications/bulk/preview', [...$payload, 'customer_ids' => [$this->customer, $duplicate], 'creation_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.rows.1.reason', 'duplicate_recipient');
    }

    public function test_explicit_email_opt_out_still_blocks_delivery(): void
    {
        $this->postJson('/api/v1/customers/'.$this->customer.'/consents', [
            'channel' => 'email', 'purpose' => 'marketing', 'granted' => false,
            'evidence' => 'Customer asked not to receive marketing email.',
        ])->assertOk();
        $this->postJson('/api/v1/communications/messages', [
            'customer_id' => $this->customer, 'channel' => 'email', 'purpose' => 'marketing',
            'subject' => 'Offer', 'body' => 'Hello', 'creation_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->assertSame(0, $this->sent);
    }
}
