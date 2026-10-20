<?php

namespace App\Tenancy;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;

/** Explicit administrative scope; never stored as or impersonated as an owner membership. */
class AdminShopMembership extends ShopMembership
{
    public static function forShop(User $admin, Shop $shop): self
    {
        abort_unless($admin->account_type === 'admin' && $admin->status === 'active' && $admin->canUseVerifiedFeatures() && $admin->platformCan('shops.workspace'), 403);
        $membership = new self(['shop_id' => $shop->id, 'user_id' => $admin->id, 'status' => 'active']);
        $membership->setRelation('user', $admin);
        $membership->setRelation('shop', $shop);

        return $membership;
    }

    public function can(string $permission): bool
    {
        return $this->user->platformCan('shops.'.$permission);
    }

    public function fresh($with = [])
    {
        return self::forShop(User::findOrFail($this->user_id), Shop::findOrFail($this->shop_id));
    }
}
