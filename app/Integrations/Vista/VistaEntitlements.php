<?php

namespace App\Integrations\Vista;

use App\Models\Vista360Entitlement;
use App\Models\VistaConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Maintains a short-lived, signed-provider entitlement cache for CRM access. */
class VistaEntitlements
{
    public function snapshot(VistaConnection $connection): ?Vista360Entitlement
    {
        return Vista360Entitlement::withoutGlobalScope('shop')
            ->where('vista_connection_id', $connection->id)
            ->where('shop_id', $connection->shop_id)
            ->first();
    }

    public function fresh(VistaConnection $connection): bool
    {
        $snapshot = $this->snapshot($connection);
        return $snapshot && $snapshot->checked_at && $snapshot->checked_at->gte(now()->subSeconds(config('vista.entitlement_ttl_seconds')));
    }

    /** @return array<string,mixed> */
    public function refresh(VistaConnection $connection, VistaClient $client): array
    {
        $payload = $client->send('GET', 'subscription', [], $connection)['data'] ?? null;
        abort_unless(is_array($payload) && is_bool($payload['active'] ?? null) && is_string($payload['status'] ?? null), 502, 'Vista returned an invalid subscription entitlement.');
        return $this->save($connection, $payload, null, null);
    }

    /** @return array<string,mixed> */
    public function save(VistaConnection $connection, array $payload, ?string $eventId, ?string $error): array
    {
        abort_unless((string) ($payload['subscription_id'] ?? '') !== '' || ! ($payload['active'] ?? false), 502, 'Vista subscription identity is missing.');
        $expiresAt = ! empty($payload['expires_at']) ? Carbon::parse($payload['expires_at']) : null;
        $startsAt = ! empty($payload['starts_at']) ? Carbon::parse($payload['starts_at']) : null;
        $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : null;
        $current = $this->snapshot($connection);
        // The seller-facing renewal URL is supplied by Vista, not guessed by
        // the CRM. Preserve it inside the JSON snapshot so a later expiry
        // (including one delivered by webhook) still has a safe handoff.
        $renewalUrl = $payload['renewal_url'] ?? data_get($current?->plan, 'renewal_url');
        if (is_string($renewalUrl) && $renewalUrl !== '') {
            $plan = array_merge($plan ?? [], ['renewal_url' => $renewalUrl]);
        }
        // An out-of-order webhook must never overwrite a newer provider view.
        $version = max((int) ($payload['entitlement_version'] ?? 0), (int) ($current?->entitlement_version ?? 0));
        if ($current && (int) ($payload['entitlement_version'] ?? 0) < $current->entitlement_version) {
            return $this->payload($current);
        }
        DB::table('vista360_entitlements')->updateOrInsert(
            ['vista_connection_id' => $connection->id],
            [
                'shop_id' => $connection->shop_id,
                'subject' => $connection->subject,
                'store_id' => $connection->store_id,
                'status' => (string) ($payload['status'] ?? 'unknown'),
                'plan' => $plan ? json_encode($plan, JSON_THROW_ON_ERROR) : null,
                'features' => json_encode($features, JSON_THROW_ON_ERROR),
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'entitlement_version' => $version,
                'checked_at' => now(),
                'last_remote_at' => now(),
                'source_event_id' => $eventId,
                'last_error' => $error,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $saved = $this->snapshot($connection);
        return $saved ? $this->payload($saved) : $payload;
    }

    public function markUnavailable(VistaConnection $connection, VistaFailure $failure): void
    {
        $payload = $failure->reason === 'vista360_subscription_required'
            ? (data_get($failure->details, 'entitlement') ?: ['active' => false, 'status' => 'inactive', 'features' => []])
            : ['active' => false, 'status' => 'unavailable', 'features' => []];
        $this->save($connection, $payload, null, $failure->getMessage());
    }

    public function allows(array $entitlement, string $feature): bool
    {
        if (! ($entitlement['active'] ?? false)) {
            return false;
        }
        foreach ((array) ($entitlement['features'] ?? []) as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : $item;
            if ($key === $feature) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public function payload(Vista360Entitlement $snapshot): array
    {
        return [
            'active' => $snapshot->status === 'active' && ($snapshot->expires_at === null || $snapshot->expires_at->isFuture()),
            'status' => $snapshot->status,
            'plan' => $snapshot->plan,
            'features' => $snapshot->features ?? [],
            'starts_at' => $snapshot->starts_at?->toIso8601String(),
            'expires_at' => $snapshot->expires_at?->toIso8601String(),
            'entitlement_version' => $snapshot->entitlement_version,
            'checked_at' => $snapshot->checked_at?->toIso8601String(),
            'renewal_url' => data_get($snapshot->plan, 'renewal_url'),
        ];
    }
}
