<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Actor
{
    public function handle(Request $r, Closure $next, string $type)
    {
        // Retire a legacy shared-cookie session from the wrong login area.
        if ($r->user()->account_type !== $type && $r->is('api/v1/auth/me', 'api/v1/admin/auth/me')) {
            Auth::guard('web')->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            abort(401, 'Please sign in to this account area.');
        }
        abort_unless($r->user()->account_type === $type, 403, 'This account cannot access this area.');

        return $next($r);
    }
}
