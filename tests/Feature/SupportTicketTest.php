<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Notifications\SupportTicketUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'TestingPassword123!';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'support-owner@example.test', 'password' => self::PASS]);
        $shop = Shop::factory()->create(['name' => 'Support Optical', 'status' => 'active']);
        ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $this->owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    }

    private function login(User $user): void
    {
        Auth::forgetGuards();
        $prefix = $user->account_type === 'admin' ? 'admin/auth' : 'auth';
        $this->postJson('/api/v1/'.$prefix.'/login', ['email' => $user->email, 'password' => self::PASS])->assertOk();
        Auth::forgetGuards();
    }

    public function test_shop_and_platform_can_track_a_ticket_conversation_with_isolated_notifications(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['account_type' => 'admin', 'email' => 'support-admin@example.test', 'password' => self::PASS]);
        $this->login($this->owner);
        $ticket = $this->postJson('/api/v1/support/tickets', [
            'subject' => 'Import needs help',
            'category' => 'technical',
            'priority' => 'high',
            'description' => 'The source file is valid but the import needs a review.',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data');

        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();

        $this->login($admin);
        $this->getJson('/api/v1/admin/support/tickets?status=open')->assertOk()->assertJsonPath('data.0.id', $ticket['id']);
        $this->patchJson('/api/v1/admin/support/tickets/'.$ticket['id'], [
            'version' => $ticket['version'], 'assigned_to' => $admin->id, 'status' => 'in_progress', 'priority' => 'urgent',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson('/api/v1/admin/support/tickets/'.$ticket['id'].'/messages', ['body' => 'We are checking the source mapping now.'])
            ->assertOk()->assertJsonPath('data.status', 'waiting_for_response');
        $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        Auth::forgetGuards();

        $this->login($this->owner);
        // The shop receives one notification for the assignment/status change
        // and a second one for the administrator's reply.
        $this->getJson('/api/v1/support/notifications')->assertOk()->assertJsonPath('unread', 2);
        $detail = $this->getJson('/api/v1/support/tickets/'.$ticket['id'])->assertOk()->assertJsonPath('data.status', 'waiting_for_response')->json('data');
        $this->postJson('/api/v1/support/tickets/'.$ticket['id'].'/messages', ['body' => 'Thank you, please continue.'])->assertOk();
        $this->patchJson('/api/v1/support/tickets/'.$ticket['id'].'/status', ['status' => 'closed', 'version' => $detail['version'] + 1])
            ->assertOk()->assertJsonPath('data.status', 'closed');

        // Only the shop user who created this ticket receives email updates;
        // administrators retain their in-app workflow and are not copied.
        Notification::assertSentTo($this->owner, SupportTicketUpdated::class, 2);
        Notification::assertNotSentTo($admin, SupportTicketUpdated::class);
    }
}
