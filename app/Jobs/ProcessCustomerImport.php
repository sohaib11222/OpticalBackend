<?php

namespace App\Jobs;

use App\Jobs\Middleware\WithTenant;
use App\Models\CustomerImport;
use App\Services\Imports\CustomerImports;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;

class ProcessCustomerImport implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $shopId, public int $actorId, public int $importId, public int $version)
    {
        $this->onQueue('uploads');
    }

    public function middleware(): array
    {
        return [new WithTenant($this->shopId, $this->actorId), (new WithoutOverlapping('customer-import:'.$this->shopId.':'.$this->importId))->releaseAfter(30)->expireAfter(300)];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CustomerImports $service): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('imports.write') && app(TenantContext::class)->membership()->can('customers.write'), 403);
        $b = CustomerImport::findOrFail($this->importId);
        if ($b->version !== $this->version || ! in_array($b->status, ['parsing', 'previewing', 'committing', 'failed'])) {
            return;
        }
        try {
            match ($b->stage) {
                'parse' => $service->parse($b),'preview' => $service->preview($b),'commit' => $service->process($b, $this->actorId)
            };
        } catch (\Throwable $e) {
            $b->status = 'failed';
            $b->last_error = $e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : 'Processing could not finish. Retry after checking the source file and account access.';
            $b->save();
            throw $e;
        }
    }
}
