<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Cookies ignore ports: the admin and shop must never share a login cookie. */
class SessionAudience
{
    public function handle(Request $request, Closure $next)
    {
        $original = config('session.cookie');
        if ($request->is('api/v1/admin/*')) {
            config(['session.cookie' => $original.'_admin']);
        }
        app('session')->driver()->setName(config('session.cookie'));
        try {
            return $next($request);
        } finally {
            config(['session.cookie' => $original]);
            app('session')->driver()->setName($original);
        }
    }
}
