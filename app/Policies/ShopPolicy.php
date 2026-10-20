<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;
use App\Tenancy\AdminShopMembership;
use App\Tenancy\TenantContext;

class ShopPolicy
{
    public function view(User $u, Shop $shop): bool
    {
        $c = app(TenantContext::class);

        return ($u->account_type === 'shop' || app(TenantContext::class)->membership() instanceof AdminShopMembership) && $c->id() === $shop->id && $c->membership()->can('shop.view');
    }

    public function update(User $u, Shop $shop): bool
    {
        return $this->view($u, $shop) && app(TenantContext::class)->membership()->can('shop.update');
    }
}
