<?php

namespace Tests\Feature;

use App\Models\OpticalRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpticalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeOwner('optical@example.test');
        $this->login($this->owner);
        $this->customer = $this->postJson('/api/v1/customers', ['first_name' => 'Optical', 'last_name' => 'Patient', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function makeOwner(string $email): User
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

    private function path(string $tail = ''): string
    {
        return '/api/v1/customers/'.$this->customer.'/optical'.$tail;
    }

    private function payload(array $data = []): array
    {
        return ['creation_key' => (string) Str::uuid(), 'recorded_on' => '2026-01-01', 'practitioner' => 'Synthetic Optician', 'data' => array_merge(['od_sphere' => -1.25, 'od_cylinder' => -0.5, 'od_axis' => 90, 'prescription_type' => 'single_vision'], $data)];
    }

    public function test_prescription_draft_finalize_amend_is_immutable_and_traceable(): void
    {
        $p = $this->payload();
        $r = $this->postJson($this->path('/prescription'), $p)->assertCreated();
        $id = $r->json('data.id');
        $this->postJson($this->path('/prescription'), $p)->assertOk()->assertJsonPath('data.id', $id);
        $edit = $p;
        unset($edit['creation_key']);
        $edit['version'] = 1;
        $edit['data']['od_sphere'] = -2;
        $this->patchJson($this->path('/'.$id), $edit)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($this->path('/'.$id), $edit)->assertConflict();
        $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 2])->assertOk()->assertJsonPath('data.status', 'finalized');
        $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 2])->assertOk();
        $edit['version'] = 3;
        $this->patchJson($this->path('/'.$id), $edit)->assertConflict();
        $amend = ['creation_key' => (string) Str::uuid(), 'version' => 3, 'reason' => 'Corrected measured sphere'];
        $next = $this->postJson($this->path('/'.$id.'/amend'), $amend)->assertCreated()->assertJsonPath('data.revision', 2)->assertJsonPath('data.amends_id', $id)->json('data.id');
        $this->postJson($this->path('/'.$id.'/amend'), $amend)->assertCreated()->assertJsonPath('data.id', $next);
        $this->getJson($this->path('/'.$id))->assertOk()->assertJsonPath('data.data.od_sphere', -2)->assertJsonPath('data.amendment.id', $next);
        $this->getJson('/api/v1/customers/'.$this->customer.'/history')->assertOk()->assertJsonPath('data.0.action', 'prescription.amended');
    }

    public function test_each_distinct_record_type_has_validated_fields_and_finalization(): void
    {
        $this->getJson('/api/v1/optical/dictionary')->assertOk()->assertJsonCount(5, 'data');
        foreach (['optometry' => ['od_sphere' => 0], 'examination' => ['os_sphere' => 1, 'observations' => 'Synthetic examination'], 'contact_lens' => ['od_power' => -1, 'od_base_curve' => 8.6, 'od_diameter' => 14.2], 'glasses' => ['brand' => 'Test', 'model' => 'Frame', 'color' => 'Blue']] as $kind => $data) {
            $p = $this->payload();
            $p['data'] = $data;
            $id = $this->postJson($this->path('/'.$kind), $p)->assertCreated()->assertJsonPath('data.kind', $kind)->json('data.id');
            $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 1])->assertOk();
        }
        $this->postJson($this->path('/prescription'), $this->payload(['od_axis' => 181, 'od_sphere' => -40, 'invented_field' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors(['data', 'data.od_axis', 'data.od_sphere']);
        $this->postJson($this->path('/prescription'), $this->payload(['expiry_date' => '2025-01-01']))->assertUnprocessable()->assertJsonValidationErrors('data.expiry_date');
        $p = $this->payload();
        $p['data'] = ['notes' => 'Incomplete draft'];
        $id = $this->postJson($this->path('/prescription'), $p)->assertCreated()->json('data.id');
        $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 1])->assertUnprocessable();
    }

    public function test_optical_links_require_same_customer_and_finalized_prescription(): void
    {
        $rx = $this->postJson($this->path('/prescription'), $this->payload())->assertCreated()->json('data.id');
        $p = $this->payload();
        $p['data'] = ['brand' => 'Test'];
        $p['prescription_id'] = $rx;
        $this->postJson($this->path('/glasses'), $p)->assertNotFound();
        $this->postJson($this->path('/'.$rx.'/finalize'), ['version' => 1])->assertOk();
        $this->postJson($this->path('/glasses'), $p)->assertCreated();
        $other = $this->postJson('/api/v1/customers', ['first_name' => 'Other', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $p['creation_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/customers/'.$other.'/optical/glasses', $p)->assertNotFound();
    }

    public function test_optical_endpoints_reject_foreign_tenant_and_missing_permissions(): void
    {
        $id = $this->postJson($this->path('/prescription'), $this->payload())->assertCreated()->json('data.id');
        $this->login($this->makeOwner('other@example.test'));
        $this->getJson($this->path())->assertNotFound();
        $this->getJson($this->path('/'.$id))->assertNotFound();
        $this->postJson($this->path('/prescription'), $this->payload())->assertNotFound();
        $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 1])->assertNotFound();
        $this->postJson($this->path('/'.$id.'/amend'), ['version' => 1, 'reason' => 'Attack', 'creation_key' => (string) Str::uuid()])->assertNotFound();
        $this->login($this->owner);
        $this->owner->memberships()->first()->role->permissions()->detach(Permission::where('name', 'optical.finalize')->value('id'));
        $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 1])->assertForbidden();
    }

    public function test_model_rejects_direct_mutation_of_a_finalized_record(): void
    {
        $id = $this->postJson($this->path('/prescription'), $this->payload())->assertCreated()->json('data.id');
        $this->postJson($this->path('/'.$id.'/finalize'), ['version' => 1])->assertOk();
        app(TenantContext::class)->set($this->owner->memberships()->first());
        try {
            $record = OpticalRecord::findOrFail($id);
            $record->practitioner = 'Overwrite';
            $this->expectException(\LogicException::class);
            $record->save();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_source_examination_and_appointment_links_enforce_same_customer_and_current_revision(): void
    {
        $sourceData = $this->payload();
        $sourceData['data'] = ['od_sphere' => -1];
        $source = $this->postJson($this->path('/optometry'), $sourceData)->assertCreated()->json('data.id');
        $examData = $sourceData;
        $examData['creation_key'] = (string) Str::uuid();
        $examData['source_record_id'] = $source;
        $this->postJson($this->path('/examination'), $examData)->assertNotFound();
        $this->postJson($this->path('/'.$source.'/finalize'), ['version' => 1])->assertOk();
        $exam = $this->postJson($this->path('/examination'), $examData)->assertCreated()->assertJsonPath('data.source_record_id', $source)->json('data.id');
        $this->postJson($this->path('/'.$exam.'/finalize'), ['version' => 1])->assertOk();
        $rx = $this->postJson($this->path('/prescription'), $this->payload() + ['source_record_id' => $exam])->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/customers', ['first_name' => 'Other', 'last_name' => 'Patient', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers/'.$other.'/optical/prescription', $this->payload() + ['source_record_id' => $exam])->assertNotFound();
        $appointment = ['title' => 'Care follow-up', 'starts_at' => '2026-01-05T09:00:00Z', 'ends_at' => '2026-01-05T09:30:00Z', 'creation_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'optical_record_id' => $rx];
        $this->postJson('/api/v1/appointments', $appointment)->assertCreated()->assertJsonPath('data.optical_record_id', $rx);
        $appointment['customer_id'] = $other;
        $appointment['creation_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/appointments', $appointment)->assertNotFound();
        $next = $this->postJson($this->path('/'.$exam.'/amend'), ['version' => 2, 'reason' => 'Corrected examination', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson($this->path('/'.$next.'/finalize'), ['version' => 1])->assertOk();
        $this->postJson($this->path('/'.$rx.'/finalize'), ['version' => 1])->assertConflict();
    }
}
