<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShopMembership;
use App\Models\User;
use App\Rules\PasswordByteLength;
use App\Services\AccessCatalog;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Services\Sessions;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    private function permit(string $permission): void
    {
        abort_unless(app(TenantContext::class)->membership()->can($permission), 403);
    }

    private function row(ShopMembership $m): array
    {
        return ['id' => $m->id, 'invitation_accepted' => (bool) $m->invitation_accepted_at, 'user_id' => $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->name, 'status' => $m->status, 'version' => $m->version, 'permission_overrides' => (object) ($m->permission_overrides ?? []), 'permissions' => $m->effectivePermissions(), 'invitation_expires_at' => $m->invitation_expires_at];
    }

    public function index(Request $r)
    {
        $this->permit('employees.read');
        $d = $r->validate(['search' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1']);
        $q = ShopMembership::where('shop_id', app(TenantContext::class)->id())->with(['user', 'role']);
        if (! empty($d['search'])) {
            $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.addcslashes($d['search'], '%_').'%')->orWhere('email', 'like', '%'.addcslashes($d['search'], '%_').'%'));
        }

        return $q->orderBy('id')->paginate(25)->through(fn ($m) => $this->row($m));
    }

    public function catalog()
    {
        $this->permit('employees.read');
        $allowed = Permission::whereNotIn('name', ['platform.view', 'platform.audit', 'platform.health'])->where('name', 'not like', 'shops.%')->pluck('name')->filter(fn ($p) => app(TenantContext::class)->membership()->can($p))->values();

        return ['permissions' => $allowed, 'roles' => Role::whereIn('name', array_keys(AccessCatalog::presets()))->with('permissions')->get()->map(fn ($role) => ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')])];
    }

    public function save(Request $r, ?int $id = null)
    {
        $this->permit('employees.manage');
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => $id ? 'prohibited' : 'required|email:rfc|max:254', 'role' => ['required', Rule::in(array_keys(AccessCatalog::presets()))], 'permission_overrides' => 'present|array', 'permission_overrides.*' => 'boolean', 'status' => 'required|in:active,suspended', 'version' => $id ? 'required|integer|min:1' : 'prohibited']);

        return DB::transaction(function () use ($r, $d, $id) {
            $shop = ShopLock::acquire();
            $role = Role::where('name', $d['role'])->firstOrFail();
            $valid = Permission::where('name', 'not like', 'shops.%')->where('name', 'not like', 'platform.%')->pluck('name')->all();
            foreach ($d['permission_overrides'] as $p => $value) {
                abort_unless(in_array($p, $valid, true), 422, 'Unknown shop permission.');
            }
            $m = $id ? ShopMembership::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($id) : new ShopMembership(['shop_id' => $shop->id]);
            if ($id) {
                abort_if($m->role->name === 'owner' || $m->user_id === $r->user()->id, 403, 'Owner and self access cannot be changed here.');
                foreach ($m->effectivePermissions() as $permission) {
                    abort_unless(app(TenantContext::class)->membership()->can($permission), 403, 'You cannot manage an employee with permissions you do not hold.');
                }
                abort_unless($m->version === $d['version'], 409, 'Employee access changed. Refresh before saving.');
            }
            $m->setRelation('role', $role);
            $m->role_id = $role->id;
            $m->permission_overrides = array_map(fn ($value) => (bool) $value, $d['permission_overrides']);
            foreach ($m->effectivePermissions() as $permission) {
                abort_unless(app(TenantContext::class)->membership()->can($permission), 403, 'You cannot grant permissions you do not hold.');
            }
            if (! $id) {
                $email = strtolower(trim($d['email']));
                abort_if(User::where('email', $email)->exists(), 422, 'This email is already in use. Choose a separate employee account.');
                $u = User::create(['name' => $d['name'], 'email' => $email, 'password' => Str::random(64)]);
                $m->user_id = $u->id;
                $m->status = $d['status'] === 'suspended' ? 'suspended' : 'invited';
                $m->invitation_expires_at = now()->addDays(7);
            } else {
                $m->user->update(['name' => $d['name']]);
                $m->status = ($m->invitation_accepted_at || ! $m->invitation_expires_at) ? $d['status'] : ($d['status'] === 'suspended' ? 'suspended' : 'invited');
                if ($m->status === 'suspended') {
                    $m->invitation_hash = null;
                }
                $m->version++;
                Sessions::revoke($m->user);
            }
            $m->save();
            $invite = $id || $m->status === 'suspended' ? [] : $this->issue($m);
            Audit::record($id ? 'employee.access_changed' : 'employee.invited', $shop->id, $r->user()->id, 'membership', $m->id);

            return response()->json(['data' => $this->row($m->fresh()), ...$invite], $id ? 200 : 201);
        });
    }

    private function issue(ShopMembership $m): array
    {
        $token = Str::random(64);
        $m->forceFill(['invitation_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(7), 'status' => 'invited'])->save();

        return ['invitation_token' => $token, 'expires_at' => $m->invitation_expires_at];
    }

    public function invite(Request $r, int $id)
    {
        $this->permit('employees.manage');

        return DB::transaction(function () use ($r, $id) {
            ShopLock::acquire();
            $m = ShopMembership::where('shop_id', app(TenantContext::class)->id())->lockForUpdate()->findOrFail($id);
            abort_if($m->role->name === 'owner' || $m->invitation_accepted_at || $m->status === 'suspended', 409, 'Only pending employees can receive an invitation.');
            foreach ($m->effectivePermissions() as $permission) {
                abort_unless(app(TenantContext::class)->membership()->can($permission), 403, 'You cannot manage an employee with permissions you do not hold.');
            }
            $result = $this->issue($m);
            $m->increment('version');
            Audit::record('employee.invitation_rotated', $m->shop_id, $r->user()->id, 'membership', $m->id);

            return $result;
        });
    }

    public function accept(Request $r)
    {
        abort_if($r->user(), 409, 'Sign out before accepting an invitation.');
        $d = $r->validate(['token' => 'required|string|size:64', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), new PasswordByteLength]]);
        DB::transaction(function () use ($d) {
            $m = ShopMembership::where('invitation_hash', hash('sha256', $d['token']))->lockForUpdate()->first();
            abort_unless($m && $m->status === 'invited' && $m->invitation_expires_at?->isFuture() && $m->shop->status === 'active' && $m->user->status === 'active', 422, 'This invitation is invalid or expired.');
            $m->user->update(['password' => $d['password']]);
            $m->forceFill(['status' => 'active', 'invitation_hash' => null, 'invitation_accepted_at' => now(), 'version' => $m->version + 1])->save();
            $m->user->sendEmailVerificationNotification();
            Audit::record('employee.invitation_accepted', $m->shop_id, $m->user_id, 'membership', $m->id);
        });

        return ['message' => 'Account ready. Sign in with your email and new password.'];
    }

    public function audit(Request $r)
    {
        $this->permit('audit.read');
        $d = $r->validate(['action' => 'nullable|string|max:120', 'actor_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'page' => 'nullable|integer|min:1']);

        return AuditEvent::where('shop_id', app(TenantContext::class)->id())->with('actor:id,name')->when($d['action'] ?? null, fn ($q, $v) => $q->where('action', $v))->when($d['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))->when($d['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($d['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))->orderByDesc('id')->paginate(40);
    }
}
