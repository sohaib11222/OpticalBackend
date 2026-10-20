<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Cache;

class UploadProcessing
{
    public static function status(): array
    {
        $last = Cache::get('optical:worker-heartbeat:'.config('queue.default').':uploads');
        $synchronous = config('queue.default') === 'sync';
        $active = $synchronous || ($last && (time() - $last) < 90);

        return ['queue' => config('queue.default'), 'worker_active' => (bool) $active,
            'message' => $active ? 'Files become ready after a security scan. Photos are then processed for display; customer files can then be mapped and imported.' : 'Your file is saved safely and waiting to be processed. Contact your administrator to start upload processing.',
            'scanner' => config('documents.scanner', 'clamav')];
    }
}
