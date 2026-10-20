<?php

namespace App\Integrations\Vista;

use App\Jobs\SyncVista;
use App\Models\VistaConnection;
use App\Tenancy\TenantContext;

class ScheduleVistaSync
{
    public function dispatch(VistaConnection $connection): bool
    {
        if (! config('vista.enabled')) {
            return false;
        }
        if ($connection->expires_at->isPast() && $connection->status !== 'disconnected') {
            VistaConnection::withoutGlobalScope('shop')->whereKey($connection->id)->update(['status' => 'requires_reconnect', 'last_error' => 'Vista authorization expired. Reconnect to renew seller consent.']);

            return false;
        }
        // Atomic lease deduplicates browser/scheduler dispatch and recovers lost workers.
        $claimed = VistaConnection::withoutGlobalScope('shop')->whereKey($connection->id)
            ->whereNotIn('status', ['disconnected', 'requires_reconnect'])->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->update(['next_sync_at' => now()->addMinutes(10)]);
        if (! $claimed) {
            return false;
        }
        $context = app(TenantContext::class);
        $previous = $context->id() ? $context->membership() : null;
        try {
            SyncVista::dispatch($connection->shop_id, $connection->user_id, $connection->id)->afterCommit();
        } catch (\Throwable) {
            VistaConnection::withoutGlobalScope('shop')->whereKey($connection->id)->update(['status' => 'error', 'last_error' => 'Synchronization will retry. Check the integrations worker.', 'next_sync_at' => now()->addMinute()]);
        } finally {
            if ($previous) {
                $context->set($previous);
            } else {
                $context->clear();
            }
        }

        return true;
    }
}
