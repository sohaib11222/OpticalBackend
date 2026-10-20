<?php

namespace App\Jobs;

use App\Integrations\Vista\PublishVista;
use App\Jobs\Middleware\WithTenant;
use App\Models\VistaOutbox;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PublishVistaProduct implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $shopId, public int $actorId, public int $operationId)
    {
        $this->onQueue('integrations');
    }

    public function middleware(): array
    {
        return [new WithTenant($this->shopId, $this->actorId), (new WithoutOverlapping('vista-publication:'.$this->operationId))->releaseAfter(60)->expireAfter(300)];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PublishVista $service): void
    {
        foreach (['integrations.publish', 'products.write', 'stock.write'] as $permission) {
            abort_unless(app(TenantContext::class)->membership()->can($permission), 403);
        }
        $service->run(VistaOutbox::findOrFail($this->operationId));
    }
}
