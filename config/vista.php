<?php

return [
    'enabled' => env('VISTA_ENABLED', false),
    'base_url' => env('VISTA_BASE_URL'),
    'client_id' => env('VISTA_CLIENT_ID', 'optical-shop'),
    'client_secret' => env('VISTA_CLIENT_SECRET'),
    'redirect_uri' => env('VISTA_REDIRECT_URI'),
    'timeout' => 20,
    // A normal CRM publication is deliberately attempted in the request that
    // created it.  This gives the seller an honest success/failure result
    // instead of leaving a small product upload "pending" behind a worker.
    // Very large image batches still use the durable integrations queue.
    'publications_sync' => filter_var(env('VISTA_PUBLICATIONS_SYNC', true), FILTER_VALIDATE_BOOL),
    'publications_sync_max_images' => max(1, min(25, (int) env('VISTA_PUBLICATIONS_SYNC_MAX_IMAGES', 12))),
    'sync_interval_seconds' => max(60, (int) env('VISTA_SYNC_INTERVAL_SECONDS', 120)),
    // The CRM refreshes provider entitlement shortly before this cache expires.
    // A failed refresh is fail-closed by default, so a cancelled seller cannot
    // keep using paid APIs during a provider outage.
    'entitlement_ttl_seconds' => max(5, (int) env('VISTA360_ENTITLEMENT_TTL_SECONDS', 15)),
    'entitlement_max_stale_seconds' => max(0, (int) env('VISTA360_ENTITLEMENT_MAX_STALE_SECONDS', 0)),
    'webhook_secret' => env('VISTA360_CRM_WEBHOOK_SECRET'),
    // The Marketplace remains the source of truth for seller subscriptions.
    // This signed bridge is used only by authenticated CRM platform admins.
    'admin_bridge_enabled' => filter_var(env('VISTA360_ADMIN_BRIDGE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'admin_bridge_url' => env('VISTA360_ADMIN_BRIDGE_URL'),
    'admin_bridge_secret' => env('VISTA360_ADMIN_BRIDGE_SECRET'),
];
