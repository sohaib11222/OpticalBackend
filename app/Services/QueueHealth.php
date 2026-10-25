<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class QueueHealth
{
    public static function active(string $queue): bool
    {
        if (config('queue.default') === 'sync') {
            return true;
        }
        $last = Cache::get('optical:worker-heartbeat:'.config('queue.default').':'.$queue);
        // Import jobs may legitimately take four minutes between worker loops.
        return is_numeric($last) && time() - (int) $last < 360;
    }
}
