<?php

namespace App\Jobs;

use App\Jobs\Middleware\WithTenant;
use App\Models\PrivateFile;
use App\Services\Audit;
use App\Services\Files\FileScanner;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ScanPrivateFile implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $shopId, public int $actorId, public int $fileId)
    {
        $this->onQueue('uploads');
    }

    public function middleware(): array
    {
        return [new WithTenant($this->shopId, $this->actorId), (new WithoutOverlapping('scan:'.$this->shopId.':'.$this->fileId))->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(FileScanner $scanner): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('files.write'), 403);
        $file = PrivateFile::findOrFail($this->fileId);
        if (in_array($file->scan_status, ['clean', 'rejected']) || $file->archived_at) {
            return;
        }
        try {
            abort_unless(str_starts_with($file->storage_key, 'shops/'.$this->shopId.'/') && ! str_contains($file->storage_key, '..'), 404);
            if ($file->sha256) {
                abort_unless(hash_equals($file->sha256, hash_file('sha256', Storage::disk('private')->path($file->storage_key))), 409, 'File integrity check failed.');
            }
            $stream = Storage::disk('private')->readStream($file->storage_key);
            try {
                $clean = $scanner->scan($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $file->scan_status = $clean ? 'clean' : 'rejected';
            $file->scan_error = $clean ? null : 'unsafe_content';
            $file->scanned_at = now();
            $file->save();
            Audit::record('document.'.$file->scan_status, $file->shop_id, $this->actorId, 'private_file', $file->id);
        } catch (\Throwable $e) {
            $file->scan_status = 'failed';
            $file->scan_error = 'scan_unavailable';
            $file->save();
            throw $e;
        }
    }
}
