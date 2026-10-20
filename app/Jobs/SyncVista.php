<?php

namespace App\Jobs;

use App\Integrations\Vista\SynchronizeVista;
use App\Integrations\Vista\VistaFailure;
use App\Jobs\Middleware\WithTenant;
use App\Models\VistaConnection;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class SyncVista implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $shopId, public int $actorId, public int $connectionId)
    {
        $this->onQueue('integrations');
    }

    public function middleware(): array
    {
        return [new WithTenant($this->shopId, $this->actorId), (new WithoutOverlapping('vista-sync:'.$this->connectionId))->releaseAfter(30)->expireAfter(120)];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SynchronizeVista $service): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('integrations.write'), 403);
        $c = VistaConnection::findOrFail($this->connectionId);
        if (in_array($c->status, ['disconnected', 'requires_reconnect'])) {
            return;
        }
        try {
            // A sync queue must not recursively enter the same overlap lock or clear
            // its parent tenant context. Database queues continue one durable page per job.
            $pages = 0;
            do {
                $service->run($c);
                $pages++;
            } while ($c->checkpoint && config('queue.default') === 'sync' && $pages < 100);
            if ($c->checkpoint && config('queue.default') !== 'sync') {
                self::dispatch($this->shopId, $this->actorId, $this->connectionId)->afterCommit();
            }
        } catch (\Throwable $e) {
            $retry = DB::transaction(function () use ($c, $e) {
                $current = VistaConnection::lockForUpdate()->findOrFail($c->id);
                if (in_array($current->status, ['disconnected', 'requires_reconnect']) || ! hash_equals((string) $current->access_token, (string) $c->access_token)) {
                    return false;
                }
                $revoked = $e instanceof VistaFailure && in_array($e->status, [401, 403]);
                $current->forceFill(['status' => $revoked ? 'requires_reconnect' : 'error', 'next_sync_at' => now()->addMinutes(5), 'last_error' => $e instanceof VistaFailure ? $e->getMessage() : 'Synchronization failed. Retry after checking connection and worker availability.'])->save();

                return ! $revoked;
            });
            if (! $retry) {
                return;
            }
            throw new \RuntimeException('Vista synchronization failed; see redacted connection status.');
        }
    }
}
