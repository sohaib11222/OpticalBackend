<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Tenancy\AdminShopMembership;
use App\Tenancy\TenantContext;

class CustomerPolicy
{
    public function viewAny(User $u): bool
    {
        return ($u->account_type === 'shop' || app(TenantContext::class)->membership() instanceof AdminShopMembership) && app(TenantContext::class)->membership()->can('customers.read');
    }

    public function view(User $u, Customer $c): bool
    {
        return $this->viewAny($u) && $c->shop_id === app(TenantContext::class)->id();
    }

    public function create(User $u): bool
    {
        return $this->viewAny($u) && app(TenantContext::class)->membership()->can('customers.write');
    }

    public function update(User $u, Customer $c): bool
    {
        return $this->view($u, $c) && $this->create($u);
    }
}
