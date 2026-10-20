<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ValidSession;
use App\Http\Resources\SessionResource;
use App\Integrations\Vista\ScheduleVistaSync;
use App\Integrations\Vista\VistaClient;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Models\VistaConnection;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VistaAuthController extends Controller
{
    public function config()
    {
        return ['data' => ['enabled' => (bool) config('vista.enabled')]];
    }

    public function start(Request $r, VistaClient $client)
    {
        $base = $client->base();
        $d = $r->validate(['mode' => 'required|in:login,connect']);
        $u = $r->user();
        if ($d['mode'] === 'connect') {
            app(ValidSession::class)->handle($r, fn (Request $request) => null);
            abort_unless($u && $u->account_type === 'shop' && $u->status === 'active' && $u->canUseVerifiedFeatures(), 403);
            $m = $u->memberships()->with('shop', 'role.permissions')->where('shop_id', $r->session()->get('shop_id'))->where('status', 'active')->first();
            abort_unless($m && $m->shop->status === 'active' && $m->can('integrations.write'), 403);
        } else {
            abort_if($u, 409, 'Sign out or connect Vista Express from your shop settings.');
        }
        $state = Str::random(64);
        $verifier = Str::random(64);
        $redirect = config('vista.redirect_uri');
        abort_unless(is_string($redirect) && str_starts_with($redirect, rtrim(config('optical.shop_url'), '/').'/'), 503, 'Vista callback must belong to this shop frontend.');
        $r->session()->put('vista_flow', ['state' => $state, 'verifier' => $verifier, 'expires' => time() + 600, 'mode' => $d['mode'], 'user_id' => $u?->id, 'auth_version' => $u?->auth_version, 'shop_id' => $d['mode'] === 'connect' ? $m->shop_id : null, 'provider' => $base]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['data' => ['authorize_url' => $base.'/optical/authorize?'.http_build_query(['client_id' => config('vista.client_id'), 'redirect_uri' => $redirect, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'])]];
    }

    public function finish(Request $r, VistaClient $client)
    {
        $d = $r->validate(['code' => 'required|string|size:64', 'state' => 'required|string|size:64']);
        $flow = $r->session()->pull('vista_flow');
        abort_unless($flow && $flow['expires'] > time() && hash_equals($flow['state'], $d['state']) && hash_equals($flow['provider'], $client->base()), 419, 'Vista sign-in expired. Start again.');
        abort_unless($flow['user_id'] === $r->user()?->id && $flow['auth_version'] === $r->user()?->auth_version, 403, 'Your account changed during authorization. Start again.');
        if ($flow['mode'] === 'connect') {
            app(ValidSession::class)->handle($r, fn (Request $request) => null);
        }
        $result = $client->send('POST', 'token', ['client_id' => config('vista.client_id'), 'client_secret' => config('vista.client_secret'), 'code' => $d['code'], 'code_verifier' => $flow['verifier'], 'redirect_uri' => config('vista.redirect_uri')]);
        $validated = Validator::make($result, ['access_token' => 'required|string|regex:/^vos_[A-Za-z0-9]{64}$/D', 'expires_at' => 'required|date|after:now', 'identity.subject' => 'required|string|max:100', 'identity.email' => 'required|email:rfc|max:254', 'identity.name' => 'required|string|max:150', 'identity.email_verified' => 'required|boolean', 'identity.store.id' => 'required|string|max:100', 'identity.store.name' => 'required|string|max:150', 'identity.store.status' => 'required|in:active', 'identity.currency' => 'required|in:EUR'])->validate();
        $identity = $validated['identity'];
        $context = app(TenantContext::class);
        try {
            $user = DB::transaction(function () use ($identity, $validated, $flow, $r, $context) {
                $old = VistaConnection::withoutGlobalScope('shop')->where('provider', $flow['provider'])->where(fn ($q) => $q->where('subject', $identity['subject'])->orWhere('store_id', $identity['store']['id']))->lockForUpdate()->first();
                if ($old) {
                    abort_unless($old->subject === $identity['subject'] && $old->store_id === $identity['store']['id'], 409, 'Vista identity changed. Contact an administrator.');
                    if ($flow['mode'] === 'connect') {
                        abort_unless($old->shop_id === $flow['shop_id'] && $old->user_id === $flow['user_id'], 409, 'This seller is already connected to another Optical Shop account.');
                    }
                    $u = User::findOrFail($old->user_id);
                    $shop = Shop::findOrFail($old->shop_id);
                } elseif ($flow['mode'] === 'connect') {
                    $u = $r->user();
                    $shop = Shop::findOrFail($flow['shop_id']);
                    abort_if(VistaConnection::withoutGlobalScope('shop')->where('shop_id', $shop->id)->exists(), 409, 'This shop already has a different seller identity.');
                } else {
                    abort_if(User::where('email', strtolower($identity['email']))->exists(), 409, 'An Optical Shop account already uses this email. Sign in to that account and explicitly connect Vista Express.');
                    $u = User::create(['name' => $identity['name'], 'email' => strtolower($identity['email']), 'password' => Str::random(100)]);
                    if ($identity['email_verified']) {
                        $u->forceFill(['email_verified_at' => now()])->save();
                    }
                    $shop = Shop::forceCreate(['name' => $identity['store']['name'], 'status' => 'active', 'currency' => 'EUR']);
                    ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
                }
                $u->refresh();
                $m = $u->memberships()->with('shop', 'user', 'role.permissions')->where('shop_id', $shop->id)->where('status', 'active')->first();
                abort_unless($u->status === 'active' && $u->account_type === 'shop' && $m && $m->can('integrations.write') && $shop->status === 'active', 403, 'Optical Shop access is unavailable.');
                $context->set($m);
                $connection = $old ?? new VistaConnection;
                $connection->fill(['provider' => $flow['provider'], 'subject' => $identity['subject'], 'store_id' => $identity['store']['id'], 'store_name' => $identity['store']['name'], 'user_id' => $u->id, 'access_token' => $validated['access_token'], 'expires_at' => $validated['expires_at'], 'status' => 'connected', 'last_error' => null]);
                $connection->save();
                Audit::record('vista.connected', $shop->id, $u->id, 'vista_connection', $connection->id);
                $u->refresh();
                $u->setAttribute('connected_shop_id', $shop->id);

                return $u;
            });
        } finally {
            $context->clear();
        }
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        $r->session()->put(['shop_id' => $user->connected_shop_id, 'auth_version' => $user->auth_version, 'authenticated_at' => time()]);

        $connection = VistaConnection::withoutGlobalScope('shop')->where('shop_id', $user->connected_shop_id)->firstOrFail();
        VistaConnection::withoutGlobalScope('shop')->whereKey($connection->id)->update(['next_sync_at' => null]);
        app(ScheduleVistaSync::class)->dispatch($connection);

        return (new SessionResource($user))->response()->setStatusCode(200);
    }
}
