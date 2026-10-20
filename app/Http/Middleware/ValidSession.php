<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ValidSession
{
    public function handle(Request $r, Closure $next)
    {
        $u = $r->user();
        $cap = (int) config('optical.absolute_session_minutes');
        $authenticatedAt = (int) $r->session()->get('authenticated_at');
        $valid = $u && $u->status === 'active' && $r->session()->has('auth_version')
            && (int) $r->session()->get('auth_version') === $u->auth_version
            && $authenticatedAt > 0 && ($cap <= 0 || $authenticatedAt > now()->subMinutes($cap)->timestamp);
        if (! $valid) {
            Auth::guard('web')->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            abort(401, 'Your session has expired. Please sign in again.');
        }

        return $next($r);
    }
}
