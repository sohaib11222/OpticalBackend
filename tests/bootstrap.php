<?php

// Tests must never inherit deployment caches or the developer's database.
require __DIR__.'/../vendor/autoload.php';
$overrides = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'APP_CONFIG_CACHE' => __DIR__.'/../storage/framework/unit-test-config.php',
    'APP_ROUTES_CACHE' => __DIR__.'/../storage/framework/unit-test-routes.php',
    'APP_EVENTS_CACHE' => __DIR__.'/../storage/framework/unit-test-events.php',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'COMMUNICATIONS_ENABLED' => 'false',
    'COMMUNICATIONS_EMAIL_ENABLED' => 'null',
];
foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
    if (is_file($overrides[$key])) {
        throw new RuntimeException('Unexpected test cache: '.$overrides[$key]);
    }
}
foreach ($overrides as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
