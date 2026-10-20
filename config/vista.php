<?php

return [
    'enabled' => env('VISTA_ENABLED', false),
    'base_url' => env('VISTA_BASE_URL'),
    'client_id' => env('VISTA_CLIENT_ID', 'optical-shop'),
    'client_secret' => env('VISTA_CLIENT_SECRET'),
    'redirect_uri' => env('VISTA_REDIRECT_URI'),
    'timeout' => 20,
    'sync_interval_seconds' => max(60, (int) env('VISTA_SYNC_INTERVAL_SECONDS', 120)),
];
