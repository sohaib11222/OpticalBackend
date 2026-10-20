<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifiedAccount
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()->canUseVerifiedFeatures(), 403, 'Verify your email before continuing.');

        return $next($r);
    }
}
