<?php

namespace App\Services\Files;

class UploadProcessing
{
    public static function status(): array
    {
        $active = \App\Services\QueueHealth::active('uploads');

        return ['queue' => config('queue.default'), 'worker_active' => (bool) $active,
            'message' => $active ? 'Files become ready after a security scan. Photos are then processed for display; customer files can then be mapped and imported.' : 'Your file is saved safely and waiting to be processed. Contact your administrator to start upload processing.',
            'scanner' => config('documents.scanner', 'clamav')];
    }
}
