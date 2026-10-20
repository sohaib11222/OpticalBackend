<?php

namespace App\Jobs\Middleware;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Tenancy\AdminShopMembership;
use App\Tenancy\TenantContext;
use RuntimeException;

class WithTenant
{
    public function __construct(public int $shopId, public int $userId) {}

    public function handle($job, $next)
    {
        $context = app(TenantContext::class);
        $context->clear();
        $m = ShopMembership::with(['shop', 'user', 'role.permissions'])->where('shop_id', $this->shopId)->where('user_id', $this->userId)->where('status', 'active')->first();
        if (! $m && ($admin = User::find($this->userId))?->account_type === 'admin') {
            $m = AdminShopMembership::forShop($admin, Shop::findOrFail($this->shopId));
        }
        if (! $m || $m->shop->status !== 'active' || $m->user->status !== 'active' || ! $m->user->canUseVerifiedFeatures()) {
            throw new RuntimeException('Tenant job access revoked.');
        }
        $context->set($m);
        try {
            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
