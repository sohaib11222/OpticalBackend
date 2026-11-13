<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\Shop;
use App\Models\Vista360Entitlement;
use App\Models\VistaConnection;
use App\Services\EmailVerification;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    public function toArray($r): array
    {
        $m = $this->account_type === 'shop' ? $this->memberships()->with(['shop', 'role.permissions'])->where('status', 'active')->where('shop_id', $r->session()->get('shop_id'))->first() : null;

        $connection = $m ? VistaConnection::withoutGlobalScope('shop')->where('shop_id', $m->shop_id)->where('access_mode', 'vista360')->first() : null;
        $entitlement = $connection ? Vista360Entitlement::withoutGlobalScope('shop')->where('vista_connection_id', $connection->id)->first() : null;

        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'account_type' => $this->account_type, 'email_verified' => $this->hasVerifiedEmail(), 'email_verification_required' => EmailVerification::required($this->resource), 'shop' => $m ? $m->shop->only(Shop::PROFILE_FIELDS) : null, 'role' => $this->account_type === 'admin' ? 'system_admin' : $m?->role->name, 'permissions' => $this->account_type === 'admin' ? Role::where('name', 'system_admin')->first()?->permissions()->pluck('name')->all() : ($m?->effectivePermissions() ?? []), 'vista_subscription' => $entitlement ? [
            'active' => $entitlement->status === 'active' && ($entitlement->expires_at === null || $entitlement->expires_at->isFuture()),
            'status' => $entitlement->status,
            'plan' => $entitlement->plan,
            'features' => $entitlement->features,
            'expires_at' => $entitlement->expires_at?->toIso8601String(),
            'checked_at' => $entitlement->checked_at?->toIso8601String(),
            // The renewal location comes from the signed Vista Express
            // entitlement snapshot.  Returning it with the session means an
            // already-expired account gets a useful recovery path before it
            // makes another protected API request.
            'renewal_url' => data_get($entitlement->plan, 'renewal_url'),
        ] : null];
    }
}
