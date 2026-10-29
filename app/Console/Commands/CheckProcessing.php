<?php

namespace App\Console\Commands;

use App\Services\Files\FileScanner;
use App\Services\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CheckProcessing extends Command
{
    protected $signature = 'optical:check-processing';
    protected $description = 'Check private storage, image support, security scanner and queue consumers without exposing customer data';

    public function handle(FileScanner $scanner): int
    {
        $ok = true;
        $key = 'diagnostics/'.Str::uuid().'.txt';
        try {
            $disk = Storage::disk('private');
            $disk->put($key, 'Vista Express 360 storage probe');
            if ($disk->get($key) !== 'Vista Express 360 storage probe') {
                throw new \RuntimeException;
            }
            $this->info('Private storage: read/write OK');
        } catch (\Throwable) {
            $ok = false;
            $this->error('Private storage: FAILED. Check the configured private path and PHP/worker user permissions.');
        } finally {
            try { Storage::disk('private')->delete($key); } catch (\Throwable) { $ok = false; }
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, 'Harmless Vista Express 360 scanner connectivity probe.');
            rewind($stream);
            if (! $scanner->scan($stream)) { throw new \RuntimeException; }
            $this->info('Security scanner: clean verdict OK');
        } catch (\Throwable) {
            $ok = false;
            $this->error('Security scanner: FAILED. Check daemon, virus definitions and CLAMD_ADDRESS. Never bypass scanning.');
        } finally { fclose($stream); }
        foreach (['gd', 'fileinfo', 'zip'] as $extension) {
            if (! extension_loaded($extension)) { $ok = false; $this->error('PHP extension missing: '.$extension); }
        }
        foreach (['uploads', 'integrations', 'communications', 'default'] as $queue) {
            $active = QueueHealth::active($queue);
            $required = in_array($queue, ['uploads', 'default']) || ($queue === 'integrations' && config('vista.enabled')) || ($queue === 'communications' && (config('communications.enabled') || config('communications.email_enabled')));
            if (! $active && $required) { $ok = false; }
            $this->line($queue.': '.($active ? 'consumer heartbeat OK' : 'no recent consumer heartbeat'));
        }
        if (config('queue.default') === 'database') {
            $connection = DB::connection(config('queue.connections.database.connection'));
            foreach ($connection->table(config('queue.connections.database.table', 'jobs'))->select('queue')->selectRaw('COUNT(*) as total')->groupBy('queue')->get() as $row) {
                $this->line('Queued '.$row->queue.': '.$row->total);
            }
        }
        $this->line('Run this command as the same OS user as PHP-FPM and the workers. Heartbeats require workers using the same cache configuration.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
