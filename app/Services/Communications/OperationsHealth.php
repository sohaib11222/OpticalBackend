<?php

namespace App\Services\Communications;

use Illuminate\Support\Facades\Cache;

class OperationsHealth
{
    public static function status(): array
    {
        $last = Cache::get('optical:worker-heartbeat:'.config('queue.default').':communications');
        $tick = Cache::get('optical:operations-tick');

        return ['worker_active' => config('queue.default') === 'sync' || ($last && time() - $last < 90), 'scheduler_active' => $tick && time() - $tick < 180, 'last_run_at' => $tick ? gmdate(DATE_ATOM, $tick) : null];
    }
}
