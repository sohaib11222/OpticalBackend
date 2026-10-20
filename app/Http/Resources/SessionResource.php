<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\Shop;
use App\Services\EmailVerification;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    public function toArray($r): array
    {
        $m = $this->account_type === 'shop' ? $this->memberships()->with(['shop', 'role.permissions'])->where('status', 'active')->where('shop_id', $r->session()->get('shop_id'))->first() : null;

        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'account_type' => $this->account_type, 'email_verified' => $this->hasVerifiedEmail(), 'email_verification_required' => EmailVerification::required(), 'shop' => $m ? $m->shop->only(Shop::PROFILE_FIELDS) : null, 'role' => $this->account_type === 'admin' ? 'system_admin' : $m?->role->name, 'permissions' => $this->account_type === 'admin' ? Role::where('name', 'system_admin')->first()?->permissions()->pluck('name')->all() : ($m?->effectivePermissions() ?? [])];
    }
}
