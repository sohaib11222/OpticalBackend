<?php

// Reuse the standard mail settings only when they explicitly target Aruba.
// A Gmail authentication mailbox must never silently become the customer sender.
$mailHost = strtolower(trim((string) env('MAIL_HOST', '')));
$useMail = in_array($mailHost, ['smtps.aruba.it', 'smtp.aruba.it'], true);
return [
    // Email is a first-class CRM feature.  It must not inherit the old
    // SMS/WhatsApp platform switch: a configured Aruba mailbox is usable
    // unless an operator explicitly turns email off.
    'email_enabled' => filter_var(env('COMMUNICATIONS_EMAIL_ENABLED', true), FILTER_VALIDATE_BOOL),
    // Keep the switch for backwards-compatible diagnostics only.  Customer
    // communication no longer sends Twilio SMS or WhatsApp provider traffic.
    'enabled' => false,
    // Immediate messages are delivered in the request after their database
    // transaction commits.  Scheduled mail continues through the scheduler
    // and named communications queue.
    'email_sync' => filter_var(env('COMMUNICATIONS_EMAIL_SYNC', true), FILTER_VALIDATE_BOOL),
    'public_url' => rtrim((string) env('COMMUNICATIONS_PUBLIC_URL', env('APP_URL')), '/'),
    'per_minute' => (int) env('COMMUNICATIONS_PER_MINUTE', 30),
    'aruba' => [
        'host' => strtolower(trim((string) env('ARUBA_SMTP_HOST', $useMail ? $mailHost : 'smtps.aruba.it'))), 'port' => (int) env('ARUBA_SMTP_PORT', $useMail ? env('MAIL_PORT', 465) : 465),
        'username' => env('ARUBA_SMTP_USERNAME') ?: ($useMail ? env('MAIL_USERNAME') : null), 'password' => env('ARUBA_SMTP_PASSWORD') ?: ($useMail ? env('MAIL_PASSWORD') : null),
        'from_address' => env('ARUBA_FROM_ADDRESS') ?: ($useMail ? env('MAIL_FROM_ADDRESS') : null), 'from_name' => env('ARUBA_FROM_NAME', 'Vista Express 360'),
    ],
];
