<?php

namespace Tests\Feature;

use App\Services\QueueHealth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProcessingHealthTest extends TestCase
{
    public function test_diagnostic_uses_real_scanner_contract_and_cleans_its_probe(): void
    {
        config(['queue.default' => 'sync']);
        \Illuminate\Support\Facades\Storage::fake('private');
        $this->app->bind(\App\Services\Files\FileScanner::class, fn () => new class implements \App\Services\Files\FileScanner {
            public function scan($stream): bool { throw new \RuntimeException('scanner offline'); }
        });
        $this->artisan('optical:check-processing')->expectsOutput('Security scanner: FAILED. Check daemon, virus definitions and CLAMD_ADDRESS. Never bypass scanning.')->assertFailed();
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('private')->allFiles());
    }

    public function test_default_worker_does_not_claim_upload_or_integration_health(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('optical:worker-heartbeat:database:default', time(), 420);
        $this->assertTrue(QueueHealth::active('default'));
        $this->assertFalse(QueueHealth::active('uploads'));
        $this->assertFalse(QueueHealth::active('integrations'));
        Cache::put('optical:worker-heartbeat:database:uploads', time() - 240, 420);
        $this->assertTrue(QueueHealth::active('uploads'));
        Cache::put('optical:worker-heartbeat:database:uploads', time() - 400, 420);
        $this->assertFalse(QueueHealth::active('uploads'));
    }
}
