<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Shop;
use App\Services\Files\UploadProcessing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOperationsController extends Controller
{
    public function audit(Request $r)
    {
        abort_unless($r->user()->platformCan('platform.audit'), 403);
        $d = $r->validate(['page' => 'nullable|integer|min:1', 'action' => 'nullable|string|max:100', 'shop_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'request_id' => 'nullable|uuid']);
        if (! empty($d['shop_id'])) {
            Shop::findOrFail($d['shop_id']);
        }
        $q = AuditEvent::select('id', 'shop_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'request_id', 'created_at');
        if (! empty($d['action'])) {
            $q->whereRaw("action LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $d['action']).'%']);
        }
        $q->when($d['shop_id'] ?? null, fn ($q, $v) => $q->where('shop_id', $v))->when($d['request_id'] ?? null, fn ($q, $v) => $q->where('request_id', $v));
        if (! empty($d['from'])) {
            $q->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $d['from'], 'UTC'));
        }
        if (! empty($d['to'])) {
            $q->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $d['to'], 'UTC')->addDay());
        }

        return $q->orderByDesc('id')->paginate(25);
    }

    public function health(Request $r)
    {
        abort_unless($r->user()->platformCan('platform.health'), 403);
        DB::select('SELECT 1');
        $driver = config('queue.default');
        $queue = null;
        if ($driver === 'database') {
            $connection = config('queue.connections.database.connection');
            $q = DB::connection($connection)->table(config('queue.connections.database.table', 'jobs'));
            $oldest = (clone $q)->min('created_at');
            $queue = ['pending' => (clone $q)->count(), 'oldest_age_seconds' => $oldest ? max(0, time() - (int) $oldest) : null];
        }

        // Deliberate platform aggregates only: never return payloads, filenames, customer records, or exception text.
        return ['data' => ['generated_at' => now()->toIso8601String(), 'database' => 'reachable', 'communications' => ['processing' => \App\Services\Communications\OperationsHealth::status(), 'providers' => ['sms' => app(\App\Services\Communications\Provider::class)->ready('sms'), 'whatsapp' => app(\App\Services\Communications\Provider::class)->ready('whatsapp'), 'email' => app(\App\Services\Communications\Provider::class)->ready('email')], 'messages' => DB::table('customer_messages')->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->get()], 'queue_driver' => $driver, 'queue' => $queue, 'failed_jobs' => DB::table('failed_jobs')->count(), 'scanner_configured' => in_array(config('documents.scanner'), ['clamav', 'defender']), 'scanner_verification' => 'not_checked', 'worker_verification' => $driver === 'sync' ? 'synchronous' : (UploadProcessing::status()['worker_active'] ? 'recent_heartbeat' : 'not_running'), 'files' => DB::table('private_files')->whereNull('archived_at')->select('scan_status')->selectRaw('COUNT(*) AS count')->groupBy('scan_status')->get(), 'product_photos' => DB::table('product_images')->whereNull('archived_at')->select('scan_status')->selectRaw('COUNT(*) AS count')->groupBy('scan_status')->get(), 'imports' => DB::table('customer_imports')->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->get()]];
    }
}
