<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgendaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner('agenda@example.test');
        $this->login($this->owner);
    }

    private function owner(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'password' => 'TestingPassword123!']);
        $s = new Shop(['name' => $email, 'timezone' => 'Europe/Berlin']);
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
        return array_merge(['creation_key' => (string) Str::uuid(), 'title' => 'Eye appointment', 'starts_at' => '2026-01-05T09:00:00+01:00', 'ends_at' => '2026-01-05T09:30:00+01:00'], $extra);
    }

    public function test_timezone_storage_idempotency_overlap_and_rescheduling_history(): void
    {
        $p = $this->data();
        $id = $this->postJson('/api/v1/appointments', $p)->assertCreated()->assertJsonPath('data.starts_at', '2026-01-05T08:00:00.000000Z')->json('data.id');
        $this->postJson('/api/v1/appointments', $p)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/appointments', $this->data())->assertConflict();
        $this->postJson('/api/v1/appointments', $this->data(['allow_overlap' => true]))->assertCreated();
        $edit = $this->data(['starts_at' => '2026-01-05T10:00:00+01:00', 'ends_at' => '2026-01-05T10:30:00+01:00', 'reason' => 'Customer requested another time', 'version' => 1]);
        unset($edit['creation_key']);
        $this->patchJson('/api/v1/appointments/'.$id, $edit)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/appointments/'.$id, $edit)->assertConflict();
        $this->getJson('/api/v1/appointments?from=2026-01-05&to=2026-01-06')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('timezone', 'Europe/Berlin');
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.action', 'rescheduled')->assertJsonPath('data.0.previous.starts_at', '2026-01-05T08:00:00.000000Z');
    }

    public function test_terminal_statuses_cancel_release_overlap_and_keep_customer_history(): void
    {
        $customer = $this->postJson('/api/v1/customers', ['first_name' => 'Agenda', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $id = $this->postJson('/api/v1/appointments', $this->data(['customer_id' => $customer]))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/appointments/'.$id.'/status', ['status' => 'cancelled', 'version' => 1, 'reason' => 'Customer cancelled'])->assertOk();
        $this->postJson('/api/v1/appointments', $this->data())->assertCreated();
        $this->patchJson('/api/v1/appointments/'.$id.'/status', ['status' => 'completed', 'version' => 2, 'reason' => 'Invalid change'])->assertConflict();
        $this->getJson('/api/v1/customers/'.$customer.'/history')->assertOk()->assertJsonPath('data.0.action', 'appointment.cancelled');
        $next = $this->postJson('/api/v1/appointments', $this->data(['starts_at' => '2026-01-05T11:00:00+01:00', 'ends_at' => '2026-01-05T11:30:00+01:00']))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/appointments/'.$next.'/status', ['status' => 'completed', 'version' => 1, 'reason' => 'Visit completed'])->assertOk();
    }

    public function test_foreign_appointments_customers_permissions_and_invalid_ranges_are_rejected(): void
    {
        $id = $this->postJson('/api/v1/appointments', $this->data())->assertCreated()->json('data.id');
        $customer = $this->postJson('/api/v1/customers', ['first_name' => 'Agenda', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->login($this->owner('foreign@example.test'));
        $this->getJson('/api/v1/appointments/'.$id)->assertNotFound();
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertNotFound();
        $this->postJson('/api/v1/appointments', $this->data(['customer_id' => $customer]))->assertNotFound();
        $this->getJson('/api/v1/appointments?from=2026-01-05&to=2026-01-06')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/appointments', $this->data(['starts_at' => '2026-01-05T09:00']))->assertUnprocessable();
        $this->postJson('/api/v1/appointments', $this->data(['ends_at' => '2026-01-04T09:00:00Z']))->assertUnprocessable();
        $this->getJson('/api/v1/appointments?from=2026-01-01&to=2027-01-01')->assertUnprocessable();
        $this->login($this->owner);
        $this->owner->memberships()->first()->role->permissions()->detach(Permission::where('name', 'agenda.write')->value('id'));
        $this->postJson('/api/v1/appointments', $this->data())->assertForbidden();
    }
}
