<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Audit;
use App\Tenancy\AdminShopMembership;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class AdminShopContext
{
    public function handle(Request $r, Closure $next)
    {
        $context = app(TenantContext::class);
        $context->clear();
        $shop = Shop::findOrFail($r->route('managedShop'));
        $membership = AdminShopMembership::forShop($r->user(), $shop);
        abort_if($r->has('shop_id') && (string) $r->input('shop_id') !== (string) $shop->id, 403, 'Invalid administrative shop scope.');
        abort_if($r->header('X-Shop-ID') && $r->header('X-Shop-ID') !== (string) $shop->id, 403, 'Invalid administrative shop scope.');
        // Suspended/pending shops are inspectable; operational writes require reactivation first.
        abort_if(! $r->isMethodSafe() && $shop->status !== 'active', 409, 'Activate this shop before changing its operational records.');
        $r->route()->forgetParameter('managedShop');
        $context->set($membership);
        try {
            $response = $next($r);
            if ($response->getStatusCode() < 400) {
                Audit::record($r->isMethodSafe() ? 'admin.workspace.read' : 'admin.workspace.write', $shop->id, $r->user()->id, 'shop', $shop->id);
            }

            return $response;
        } finally {
            $context->clear();
        }
    }
}
