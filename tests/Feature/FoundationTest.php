<?php

namespace Tests\Feature;

use App\Jobs\Middleware\WithTenant;
use App\Models\PrivateFile;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccount;
use App\Services\Commerce\Notices;
use App\Services\Sessions;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'TestingPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function owner(string $email = 'owner@example.test', string $status = 'active', bool $verified = true): User
    {
        $u = User::factory()->create(['email' => $email, 'password' => self::PASS, 'email_verified_at' => $verified ? now() : null]);
        $s = new Shop(['name' => 'Shop '.$email]);
        $s->status = $status;
        $s->save();
        ShopMembership::create(['shop_id' => $s->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

        return $u->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.test', 'password' => self::PASS, 'account_type' => 'admin']);
    }

    private function loginUser(User $u): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/'.($u->account_type === 'admin' ? 'admin/auth' : 'auth').'/login', ['email' => $u->email, 'password' => self::PASS])->assertOk();
        Auth::forgetGuards();
    }

    private function registration(array $extra = []): array
    {
        return array_merge(['phone' => '+39 06 12345678', 'address_line1' => 'Via Roma 1', 'city' => 'Roma', 'postal_code' => '00100', 'country' => 'Italy', 'timezone' => 'Europe/Rome', 'name' => 'Owner', 'shop_name' => 'My Optical', 'email' => 'NEW@example.test', 'password' => self::PASS, 'password_confirmation' => self::PASS], $extra);
    }

    public function test_development_bypass_allows_both_actors_without_faking_verification_or_sending_mail(): void
    {
        config(['optical.email_verification_required' => false]);
        $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated()
            ->assertJsonPath('data.email_verified', false)->assertJsonPath('data.email_verification_required', false);
        Notification::assertNothingSent();
        $this->getJson('/api/v1/shop')->assertForbidden(); // Approval is still required.
        $u = User::first();
        $u->memberships()->first()->shop->forceFill(['status' => 'active'])->save();
        Auth::forgetGuards();
        $this->getJson('/api/v1/shop')->assertOk();
        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson('/api/v1/admin/shops')->assertForbidden();
        $this->assertNull($u->fresh()->email_verified_at);
        $other = $this->owner('other@example.test');
        $this->getJson('/api/v1/shops/'.$other->memberships()->first()->shop_id)->assertNotFound();
        $m = $u->memberships()->first();
        $this->assertSame('ran', (new WithTenant($m->shop_id, $u->id))->handle(new \stdClass, function () {
            app(Notices::class)->emit('stock', 'stock.read', 'dev-test', 'stock.low', 'Stock notice', '/inventory');

            return 'ran';
        }));
        $this->assertDatabaseHas('shop_notices', ['user_id' => $u->id, 'event_key' => 'dev-test']);
        $this->assertNull(app(TenantContext::class)->id());
        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->loginUser($admin);
        $this->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.email_verified', false)->assertJsonPath('data.email_verification_required', false);
        $this->getJson('/api/v1/admin/shops')->assertOk();
        $this->getJson('/api/v1/admin/operations')->assertOk();
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_bypass_is_ignored_in_production_and_staging_and_can_be_disabled_locally(): void
    {
        config(['optical.email_verification_required' => false]);
        $u = $this->owner(verified: false);
        $this->loginUser($u);
        $original = app()->environment();
        try {
            foreach (['production', 'staging'] as $environment) {
                app()->instance('env', $environment);
                $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email_verified', false)->assertJsonPath('data.email_verification_required', true);
                $this->getJson('/api/v1/shop')->assertForbidden();
                $u->sendEmailVerificationNotification();
                Notification::assertSentTo($u, VerifyAccount::class);
            }
        } finally {
            app()->instance('env', $original);
        }
        config(['optical.email_verification_required' => true]);
        $this->getJson('/api/v1/shop')->assertForbidden();
        config(['optical.email_verification_required' => false]);
        $this->getJson('/api/v1/shop')->assertOk();
        $u->memberships()->update(['status' => 'suspended']);
        $this->getJson('/api/v1/shop')->assertForbidden();
    }

    private function link(User $u, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $u->id, 'hash' => sha1($u->email)], false);
    }

    public function test_registration_is_atomic_pending_unverified_and_hashed(): void
    {
        $r = $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated()->assertJsonPath('data.email', 'new@example.test')->assertJsonPath('data.account_type', 'shop')->assertJsonPath('data.shop.status', 'pending')->assertJsonPath('data.email_verified', false);
        $this->assertArrayNotHasKey('password', $r->json('data'));
        $u = User::first();
        $this->assertTrue(Hash::check(self::PASS, $u->password));
        Notification::assertSentTo($u, VerifyAccount::class);
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/shop')->assertForbidden();
        $this->assertDatabaseCount('shops', 1);
        $this->assertDatabaseCount('shop_memberships', 1);
    }

    public function test_business_registration_requires_valid_contact_details_and_timezone(): void
    {
        $invalid = ['phone' => 'letters', 'address_line1' => '', 'city' => '', 'postal_code' => '', 'country' => '', 'timezone' => 'Not/AZone'];
        $this->postJson('/api/v1/auth/register', $this->registration($invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated()->assertJsonPath('data.shop.postal_code', '00100')->assertJsonPath('data.shop.timezone', 'Europe/Rome');
        $this->assertDatabaseHas('shops', ['phone' => '+39 06 12345678', 'address_line1' => 'Via Roma 1', 'postal_code' => '00100']);
    }

    public function test_admin_reviews_business_details_without_private_account_secrets(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated();
        $this->loginUser($this->admin());
        $r = $this->getJson('/api/v1/admin/shops')->assertOk()->assertJsonPath('data.0.postal_code', '00100')->assertJsonPath('data.0.memberships.0.user.email_verified_at', null);
        $this->assertArrayNotHasKey('password', $r->json('data.0.memberships.0.user'));
    }

    public function test_business_profile_updates_are_validated_for_existing_shops(): void
    {
        $this->loginUser($this->owner());
        $this->patchJson('/api/v1/shop', ['name' => 'Updated', 'phone' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/v1/shop', ['name' => 'Updated', 'phone' => '+39 06 12345678', 'timezone' => 'Europe/Rome'])->assertOk()->assertJsonPath('data.phone', '+39 06 12345678');
    }

    public function test_registration_rejects_privilege_injection_without_partial_rows(): void
    {
        foreach (['role' => 'system_admin', 'account_type' => 'admin', 'shop_id' => 999, 'status' => 'active', 'email_verified_at' => '2026-01-01'] as $k => $v) {
            $this->postJson('/api/v1/auth/register', $this->registration([$k => $v]))->assertUnprocessable()->assertJsonValidationErrors($k);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_validation_duplicates_and_configurable_approval(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['password' => 'short', 'password_confirmation' => 'different']))->assertUnprocessable()->assertJsonValidationErrors('password');
        config(['optical.registration_requires_approval' => false]);
        $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated()->assertJsonPath('data.shop.status', 'active');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/register', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_persistence_logout_and_anonymous_protection(): void
    {
        $this->getJson('/api/v1/shop')->assertUnauthorized();
        $u = $this->owner();
        $this->loginUser($u);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $u->id);
        $this->getJson('/api/v1/shop')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/shop')->assertUnauthorized();
    }

    public function test_admin_authority_is_separate_with_no_public_registration(): void
    {
        $u = $this->owner();
        $a = $this->admin();
        $this->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => self::PASS])->assertUnprocessable();
        $this->loginUser($u);
        $this->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->loginUser($a);
        $this->getJson('/api/v1/shop')->assertForbidden();
        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->postJson('/api/v1/admin/auth/register', [])->assertNotFound();
    }

    public function test_signed_verification_checks_tampering_expiry_and_current_email(): void
    {
        $u = $this->owner(verified: false);
        $link = $this->link($u);
        $this->getJson($link.'&tamper=1')->assertForbidden();
        $this->getJson($this->link($u, -1))->assertForbidden();
        $this->getJson($link)->assertOk();
        $this->assertTrue($u->fresh()->hasVerifiedEmail());
        $u->refresh()->forceFill(['email' => 'changed@example.test', 'email_verified_at' => null])->save();
        $this->getJson($link)->assertForbidden();
        $this->assertFalse($u->fresh()->hasVerifiedEmail());
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/verification-notification')->assertUnauthorized();
        $u = $this->owner(verified: false);
        $this->loginUser($u);
        $this->postJson('/api/v1/auth/verification-notification')->assertOk();
        Notification::assertSentTo($u, VerifyAccount::class);
    }

    public function test_reset_is_generic_single_use_and_revokes_sessions(): void
    {
        $u = $this->owner();
        $this->loginUser($u);
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $u->email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'absent@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        $token = Notification::sent($u, ResetAccountPassword::class)->first()->token;
        $d = ['email' => $u->email, 'token' => $token, 'password' => 'ReplacementPassword456!', 'password_confirmation' => 'ReplacementPassword456!'];
        $this->postJson('/api/v1/auth/reset-password', $d)->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(Hash::check($d['password'], $u->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', $d)->assertUnprocessable();
    }

    public function test_reset_rejects_expired_token_and_wrong_panel(): void
    {
        $u = $this->owner();
        $d = ['email' => $u->email, 'token' => Password::createToken($u), 'password' => self::PASS, 'password_confirmation' => self::PASS];
        $this->postJson('/api/v1/admin/auth/reset-password', $d)->assertUnprocessable();
        DB::table('password_reset_tokens')->update(['created_at' => now()->subHours(3)]);
        $this->postJson('/api/v1/auth/reset-password', $d)->assertUnprocessable();
    }

    public function test_password_change_checks_current_password_and_revokes_session(): void
    {
        $u = $this->owner();
        $this->loginUser($u);
        $d = ['current_password' => 'wrong', 'password' => 'NewPassword12345!', 'password_confirmation' => 'NewPassword12345!'];
        $this->postJson('/api/v1/auth/password', $d)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $d['current_password'] = self::PASS;
        $this->postJson('/api/v1/auth/password', $d)->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(Hash::check($d['password'], $u->fresh()->password));
    }

    public function test_email_change_requires_reverification_and_revokes_session(): void
    {
        $u = $this->owner();
        $this->loginUser($u);
        $this->postJson('/api/v1/auth/email', ['email' => 'next@example.test', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/email', ['email' => 'NEXT@example.test', 'current_password' => self::PASS])->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame('next@example.test', $u->fresh()->email);
        $this->assertFalse($u->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($u, VerifyAccount::class);
    }

    public function test_foreign_ids_payload_headers_and_members_are_isolated(): void
    {
        $u = $this->owner();
        $other = $this->owner('other@example.test');
        $foreign = $other->memberships()->first()->shop_id;
        $own = $u->memberships()->first()->shop_id;
        $this->loginUser($u);
        $this->getJson('/api/v1/shops/'.$foreign)->assertNotFound();
        $this->patchJson('/api/v1/shop', ['name' => 'Attack', 'shop_id' => $foreign])->assertForbidden();
        $this->getJson('/api/v1/shop', ['X-Shop-ID' => (string) $foreign])->assertForbidden();
        $this->getJson('/api/v1/shop/members')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', $u->email);
        $this->patchJson('/api/v1/shop', ['name' => 'Updated'])->assertOk();
        $this->assertDatabaseHas('shops', ['id' => $own, 'name' => 'Updated']);
        $this->assertDatabaseMissing('shops', ['id' => $foreign, 'name' => 'Updated']);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_permissions_are_checked_at_backend(): void
    {
        $u = $this->owner();
        $this->loginUser($u);
        $role = Role::where('name', 'owner')->first();
        $role->permissions()->detach($role->permissions()->where('name', 'shop.update')->value('permissions.id'));
        $this->patchJson('/api/v1/shop', ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/v1/shop')->assertOk();
    }

    public function test_admin_approval_suspension_and_stale_status_conflict(): void
    {
        $u = $this->owner(status: 'pending');
        $id = $u->memberships()->first()->shop_id;
        $this->loginUser($u);
        $this->getJson('/api/v1/shop')->assertForbidden();
        $this->loginUser($this->admin());
        $this->patchJson('/api/v1/admin/shops/'.$id.'/status', ['status' => 'active', 'expected_status' => 'pending'])->assertOk();
        $this->patchJson('/api/v1/admin/shops/'.$id.'/status', ['status' => 'suspended', 'expected_status' => 'pending'])->assertConflict();
        $this->getJson('/api/v1/admin/shops?per_page=101')->assertUnprocessable();
        $this->patchJson('/api/v1/admin/shops/'.$id.'/status', ['status' => 'suspended', 'expected_status' => 'active'])->assertOk();
        $this->assertSame(2, $u->fresh()->auth_version);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASS])->assertUnprocessable();
    }

    public function test_revoked_membership_disabled_user_and_absolute_expiry(): void
    {
        $u = $this->owner();
        $this->loginUser($u);
        $u->memberships()->update(['status' => 'suspended']);
        $this->getJson('/api/v1/shop')->assertForbidden();
        $u->memberships()->update(['status' => 'active']);
        $u->forceFill(['status' => 'suspended'])->save();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $u->forceFill(['status' => 'active'])->save();
        $this->loginUser($u);
        config(['optical.absolute_session_minutes' => 480]);
        $this->withSession(['authenticated_at' => now()->subHours(9)->timestamp])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_private_files_fail_closed_and_require_own_clean_path(): void
    {
        Storage::fake('private');
        $u = $this->owner();
        $v = $this->owner('other@example.test');
        $id = $u->memberships()->first()->shop_id;
        $other = $v->memberships()->first()->shop_id;
        foreach ([$id, $other] as $shop) {
            Storage::disk('private')->put('shops/'.$shop.'/a.txt', 'Private data');
            DB::table('private_files')->insert(['shop_id' => $shop, 'storage_key' => 'shops/'.$shop.'/a.txt', 'filename' => 'a.txt', 'mime' => 'text/plain', 'size' => 12, 'scan_status' => 'clean']);
        }
        $this->assertSame(0, PrivateFile::count());
        $this->loginUser($u);
        $own = DB::table('private_files')->where('shop_id', $id)->value('id');
        $foreign = DB::table('private_files')->where('shop_id', $other)->value('id');
        $this->getJson('/api/v1/private-files/'.$foreign)->assertNotFound();
        $this->getJson('/api/v1/private-files/'.$own)->assertOk()->assertDownload('a.txt');
        DB::table('private_files')->where('id', $own)->update(['scan_status' => 'pending']);
        $this->getJson('/api/v1/private-files/'.$own)->assertConflict();
        DB::table('private_files')->where('id', $own)->update(['scan_status' => 'clean', 'storage_key' => 'shops/'.$other.'/foreign.txt']);
        $this->getJson('/api/v1/private-files/'.$own)->assertNotFound();
    }

    public function test_job_context_clears_after_failure_and_revocation_denies_jobs(): void
    {
        $u = $this->owner();
        $m = $u->memberships()->first();
        $ctx = app(TenantContext::class);
        $job = new WithTenant($m->shop_id, $u->id);
        try {
            $job->handle(new \stdClass, function () use ($ctx, $m) {
                $this->assertSame($m->shop_id, $ctx->id());
                throw new \RuntimeException('Job failed');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Job failed', $e->getMessage());
        }
        $this->assertNull($ctx->id());
        $m->update(['status' => 'suspended']);
        $this->expectException(\RuntimeException::class);
        $job->handle(new \stdClass, fn () => null);
    }

    public function test_tenant_write_guard_and_redacted_audit(): void
    {
        $u = $this->owner();
        $m = $u->memberships()->first();
        $ctx = app(TenantContext::class);
        $ctx->set($m);
        $f = new PrivateFile(['filename' => 'x', 'mime' => 'text/plain', 'size' => 1, 'storage_key' => 'x']);
        $f->shop_id = $m->shop_id + 100;
        try {
            $f->save();
            $this->fail('Foreign write accepted');
        } catch (\LogicException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            $ctx->clear();
        }
        $this->loginUser($u);
        $audit = json_encode(DB::table('audit_events')->get());
        $this->assertStringNotContainsString(self::PASS, $audit);
        $this->assertStringNotContainsString($u->email, $audit);
    }

    public function test_login_throttling_and_error_contract(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.test', 'password' => 'wrong'])->assertUnprocessable()->assertJsonStructure(['message', 'code', 'errors', 'request_id']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_admin_reset_and_logout(): void
    {
        $a = $this->admin();
        $this->loginUser($a);
        $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $a->email])->assertOk();
        $token = Notification::sent($a, ResetAccountPassword::class)->first()->token;
        $this->postJson('/api/v1/admin/auth/reset-password', ['email' => $a->email, 'token' => $token, 'password' => 'NewAdminPassword123!', 'password_confirmation' => 'NewAdminPassword123!'])->assertOk();
    }

    public function test_bearer_tokens_never_replace_a_browser_session(): void
    {
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer 1|untrusted-token'])->assertUnauthorized();
    }

    public function test_tenant_keys_and_database_membership_constraint(): void
    {
        $u = $this->owner();
        $v = $this->owner('second@example.test');
        $ctx = app(TenantContext::class);
        $m = $u->memberships()->first();
        $ctx->set($m);
        $a = $ctx->cacheKey('report');
        $ctx->set($v->memberships()->first());
        $this->assertNotSame($a, $ctx->cacheKey('report'));
        $ctx->clear();
        $this->expectException(QueryException::class);
        ShopMembership::create($m->only(['shop_id', 'user_id', 'role_id']));
    }

    public function test_admin_command_provisions_unverified_identity_without_public_signup(): void
    {
        $this->artisan('optical:admin', ['email' => 'provision@example.test', '--name' => 'Provisioned'])->expectsQuestion('Password (at least 12 characters, letters and numbers)', self::PASS)->expectsOutput('Administrator created. A verification email has been queued.')->assertSuccessful();
        $u = User::where('email', 'provision@example.test')->firstOrFail();
        $this->assertSame('admin', $u->account_type);
        $this->assertFalse($u->hasVerifiedEmail());
        Notification::assertSentTo($u, VerifyAccount::class);
    }

    public function test_multibyte_password_cannot_silently_truncate_bcrypt_input(): void
    {
        $p = str_repeat('é', 40).'123A';
        $this->postJson('/api/v1/auth/register', $this->registration(['password' => $p, 'password_confirmation' => $p]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_schema_contract_matches_every_phase_one_api_operation(): void
    {
        $spec = json_decode(file_get_contents(base_path('../docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.substr($route->uri(), 7);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(strtolower($method), $spec['paths'][$path] ?? [], $method.' '.$path);
                $operations[] = $method.' '.$path;
            }
        }
        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $documented[] = strtoupper($method).' '.$path;
                $this->assertNotEmpty($operation['operationId']);
            }
        }
        sort($operations);
        sort($documented);
        $this->assertSame($operations, $documented);
    }

    public function test_admin_search_treats_wildcards_as_literal_and_bounds_results(): void
    {
        Shop::factory()->create(['name' => 'Percent % Optical']);
        Shop::factory()->create(['name' => 'Ordinary Optical']);
        $this->loginUser($this->admin());
        $this->getJson('/api/v1/admin/shops?search=%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Percent % Optical');
        $this->getJson('/api/v1/admin/shops?status=pending&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
    }

    public function test_registration_rolls_back_if_initial_notification_cannot_be_enqueued(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic queue outage'));
        $this->postJson('/api/v1/auth/register', $this->registration())->assertStatus(500)->assertJsonPath('message', 'The request could not be completed. Please try again.');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertDatabaseCount('shop_memberships', 0);
    }

    public function test_api_response_headers_and_cors_reject_unapproved_origin(): void
    {
        $r = $this->get('/api/v1/auth/me');
        $r->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame($r->json('request_id'), $r->headers->get('X-Request-ID'));
        $this->options('/api/v1/auth/login', [], ['Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'POST'])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_active_persistent_sessions_survive_old_absolute_cutoff_and_still_revoke(): void
    {
        config(['optical.absolute_session_minutes' => 0]);
        $u = $this->owner();
        $this->loginUser($u);
        $this->withSession(['authenticated_at' => now()->subDays(20)->timestamp])->getJson('/api/v1/auth/me')->assertOk();
        Sessions::revoke($u);
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertFalse(config('session.expire_on_close'));
        $this->assertTrue(config('session.http_only'));
    }

    public function test_legacy_wrong_audience_session_is_retired_before_federated_sign_in(): void
    {
        $admin = $this->admin();
        $this->loginUser($admin);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
