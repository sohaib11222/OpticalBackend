<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $r, Closure $next)
    {
        $membership = $r->user()->memberships()->with(['shop', 'role.permissions'])->where('status', 'active')->where('shop_id', $r->session()->get('shop_id'))->first();
        abort_unless($membership && $membership->shop->status === 'active', 403, 'This shop is awaiting approval or has been suspended.');
        abort_if($r->has('shop_id') && (string) $r->input('shop_id') !== (string) $membership->shop_id, 403, 'Invalid shop context.');
        abort_if($r->header('X-Shop-ID') && $r->header('X-Shop-ID') !== (string) $membership->shop_id, 403, 'Invalid shop context.');
        $this->context->set($membership);
        try {
            return $next($r);
        } finally {
            $this->context->clear();
        }
    }
}
