<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PasswordRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\SessionResource;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Audit;
use App\Services\EmailVerification;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function kind(Request $r): string
    {
        return $r->is('api/v1/admin/*') ? 'admin' : 'shop';
    }

    private function establish(Request $r, User $u): void
    {
        Auth::guard('web')->login($u);
        $r->session()->regenerate();
        $r->session()->put(['auth_version' => $u->auth_version, 'authenticated_at' => now()->timestamp, 'shop_id' => $u->memberships()->where('status', 'active')->value('shop_id')]);
    }

    public function register(RegisterRequest $r)
    {
        abort_if($r->user(), 409, 'Sign out before creating another account.');
        $u = DB::transaction(function () use ($r) {
            $u = User::create($r->safe()->only(['name', 'email', 'password']));
            $shop = new Shop(['name' => $r->validated('shop_name'), ...$r->safe()->only(Shop::BUSINESS_FIELDS)]);
            $shop->status = config('optical.registration_requires_approval') ? 'pending' : 'active';
            $shop->save();
            ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->firstOrFail()->id]);
            Audit::record('account.registered', $shop->id, $u->id);
            $u->sendEmailVerificationNotification();

            return $u;
        });
        $u->refresh();
        $this->establish($r, $u);

        return (new SessionResource($u))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $r)
    {
        $u = User::where('email', $r->validated('email'))->first();
        // Always perform an expensive hash check; no role, existence or suspension disclosure.
        $hash = $u?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $matches = Hash::check($r->validated('password'), $hash);
        if (! $u || ! $matches || $u->account_type !== $this->kind($r) || $u->status !== 'active') {
            throw ValidationException::withMessages(['email' => ['The credentials are invalid or the account is unavailable.']]);
        }
        if ($u->account_type === 'shop' && ! $u->memberships()->where('status', 'active')->whereHas('shop', fn ($q) => $q->whereIn('status', ['active', 'pending']))->exists()) {
            throw ValidationException::withMessages(['email' => ['The credentials are invalid or the account is unavailable.']]);
        }
        $this->establish($r, $u);
        Audit::record('account.login', null, $u->id);

        return new SessionResource($u);
    }

    public function me(Request $r)
    {
        return new SessionResource($r->user());
    }

    public function logout(Request $r)
    {
        Audit::record('account.logout', null, $r->user()->id);
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgot(Request $r)
    {
        $data = $r->validate(['email' => 'required|email:rfc|max:254']);
        $email = strtolower(trim($data['email']));
        if (User::where('email', $email)->where('account_type', $this->kind($r))->exists()) {
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json(['message' => 'If this account exists, a password reset link will be sent.']);
    }

    public function reset(PasswordRequest $r)
    {
        $d = $r->validated();
        $d['email'] = strtolower(trim($d['email']));
        if (! User::where('email', $d['email'])->where('account_type', $this->kind($r))->exists()) {
            throw ValidationException::withMessages(['email' => ['This reset link is invalid or expired.']]);
        }
        $status = Password::reset($d, function (User $u, string $password) {
            DB::transaction(function () use ($u, $password) {
                $u->password = $password;
                $u->save();
                Sessions::revoke($u);
                Audit::record('account.password_reset', null, $u->id);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This reset link is invalid or expired.']]);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }

    public function changePassword(PasswordRequest $r)
    {
        $u = $r->user();
        DB::transaction(function () use ($r, $u) {
            $u->password = $r->validated('password');
            $u->save();
            Sessions::revoke($u);
            Audit::record('account.password_changed', null, $u->id);
        });

        return $this->logout($r);
    }

    public function changeEmail(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['email' => 'required|email:rfc|max:254|unique:users,email', 'current_password' => 'required|current_password:web']);
        $u = $r->user();
        DB::transaction(function () use ($u, $d) {
            $u->forceFill(['email' => $d['email'], 'email_verified_at' => null])->save();
            Sessions::revoke($u);
            $u->sendEmailVerificationNotification();
            Audit::record('account.email_changed', null, $u->id);
        });

        return $this->logout($r);
    }

    public function resend(Request $r)
    {
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => EmailVerification::required() ? 'Verification email requested. Check your inbox.' : 'Email verification is disabled for development.']);
    }

    public function verify(Request $r, string $id, string $hash)
    {
        $u = User::findOrFail($id);
        abort_unless(hash_equals(sha1($u->getEmailForVerification()), $hash), 403, 'Invalid verification link.');
        if (! $u->hasVerifiedEmail()) {
            $u->markEmailAsVerified();
            Audit::record('account.email_verified', null, $u->id);
        }

        return response()->json(['message' => 'Email verified. You can continue to your account.']);
    }
}
