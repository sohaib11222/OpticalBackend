<?php

use App\Jobs\ProcessProductImage;
use App\Jobs\ScanPrivateFile;
use App\Models\PrivateFile;
use App\Models\ProductImage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:clear-resets')->daily();

Artisan::command('optical:retry-file-scans', function () {
    $files = PrivateFile::withoutGlobalScope('shop')->whereIn('scan_status', ['pending', 'failed'])->whereNull('archived_at')->whereNotNull('uploaded_by')->where('updated_at', '<', now()->subMinutes(10))->whereHas('uploader', fn ($q) => $q->where('status', 'active'))->limit(100)->get();
    foreach ($files as $file) {
        ScanPrivateFile::dispatch($file->shop_id, $file->uploaded_by, $file->id);
    }
    $photos = ProductImage::withoutGlobalScope('shop')->whereIn('scan_status', ['pending', 'failed'])->whereNull('archived_at')->where('updated_at', '<', now()->subMinutes(10))->limit(100)->get();
    foreach ($photos as $photo) {
        ProcessProductImage::dispatch($photo->shop_id, $photo->uploaded_by, $photo->id);
    }
    $this->info('Queued '.$files->count().' file scans and '.$photos->count().' photo scans.');
})->purpose('Retry quarantined file scans; each job rechecks tenant access.');
Schedule::command('optical:retry-file-scans')->everyTenMinutes()->withoutOverlapping();
Schedule::command('optical:reconcile-vista')->everyMinute()->withoutOverlapping();

Schedule::command('optical:run-operations')->everyMinute()->withoutOverlapping();
