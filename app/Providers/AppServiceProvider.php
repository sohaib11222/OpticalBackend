<?php

namespace App\Providers;

use App\Services\Files\ClamdScanner;
use App\Services\Files\DefenderScanner;
use App\Services\Files\FileScanner;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(FileScanner::class, fn () => match (config('documents.scanner', 'clamav')) {
            'clamav' => new ClamdScanner,
            'defender' => new DefenderScanner,
            default => throw new \RuntimeException('Unknown file scanner configuration.'),
        });
    }

    public function boot(): void
    {
        Queue::looping(function (Looping $event) {
            foreach (explode(',', $event->queue ?? 'default') as $queue) {
                Cache::put('optical:worker-heartbeat:'.$event->connectionName.':'.$queue, time(), 120);
            }
        });
        // This first-party phase deliberately accepts sessions only, never bearer tokens.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?? $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(20)->by($r->ip()), Limit::perMinute(5)->by($r->ip().'|'.hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('recovery', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('verification', fn (Request $r) => Limit::perMinute(5)->by($r->user()?->id ?? $r->ip()));
    }
}
