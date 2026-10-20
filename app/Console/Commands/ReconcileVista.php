<?php

namespace App\Console\Commands;

use App\Integrations\Vista\ScheduleVistaSync;
use App\Jobs\PublishVistaProduct;
use App\Models\VistaConnection;
use App\Models\VistaOutbox;
use Illuminate\Console\Command;

class ReconcileVista extends Command
{
    protected $signature = 'optical:reconcile-vista';

    protected $description = 'Resume bounded Vista snapshots and retry durable, unconfirmed publications.';

    public function handle(): int
    {
        if (! config('vista.enabled')) {
            $this->info('Vista integration is disabled.');

            return self::SUCCESS;
        }
        $count = 0;
        // Every job rechecks the actor, active shop and current permissions. Expired
        // grants require reconnection; a schedule never invents new authority.
        VistaConnection::withoutGlobalScope('shop')->whereNotIn('status', ['disconnected', 'requires_reconnect'])->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))->orderBy('id')->limit(100)->get()->each(function ($c) use (&$count) {
                $count += (int) app(ScheduleVistaSync::class)->dispatch($c);
            });
        VistaOutbox::withoutGlobalScope('shop')->where(function ($q) {
            $q->whereIn('status', ['pending', 'uncertain'])->where('next_attempt_at', '<=', now())
                ->orWhere(fn ($q) => $q->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(10)));
        })->orderBy('id')->limit(100)->get()->each(function ($op) use (&$count) {
            // Back off the scheduler as well as queue retries during an outage.
            VistaOutbox::withoutGlobalScope('shop')->whereKey($op->id)->update(['next_attempt_at' => now()->addMinutes(15)]);
            PublishVistaProduct::dispatch($op->shop_id, $op->actor_id, $op->id);
            $count++;
        });
        $this->info('Dispatched '.$count.' scoped Vista jobs.');

        return self::SUCCESS;
    }
}
