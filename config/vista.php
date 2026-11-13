<?php

return [
    'enabled' => env('VISTA_ENABLED', false),
    'base_url' => env('VISTA_BASE_URL'),
    'client_id' => env('VISTA_CLIENT_ID', 'optical-shop'),
    'client_secret' => env('VISTA_CLIENT_SECRET'),
    'redirect_uri' => env('VISTA_REDIRECT_URI'),
    'timeout' => 20,
    'sync_interval_seconds' => max(60, (int) env('VISTA_SYNC_INTERVAL_SECONDS', 120)),
    // The CRM refreshes provider entitlement shortly before this cache expires.
    // A failed refresh is fail-closed by default, so a cancelled seller cannot
    // keep using paid APIs during a provider outage.
    'entitlement_ttl_seconds' => max(5, (int) env('VISTA360_ENTITLEMENT_TTL_SECONDS', 15)),
    'entitlement_max_stale_seconds' => max(0, (int) env('VISTA360_ENTITLEMENT_MAX_STALE_SECONDS', 0)),
    'webhook_secret' => env('VISTA360_CRM_WEBHOOK_SECRET'),
];
