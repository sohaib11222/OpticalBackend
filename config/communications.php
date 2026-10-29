<?php

// Reuse the standard mail settings only when they explicitly target Aruba.
// A Gmail authentication mailbox must never silently become the customer sender.
$mailHost = strtolower(trim((string) env('MAIL_HOST', '')));
$useMail = in_array($mailHost, ['smtps.aruba.it', 'smtp.aruba.it'], true);
return [
    'enabled' => (bool) env('COMMUNICATIONS_ENABLED', false),
    'email_enabled' => env('COMMUNICATIONS_EMAIL_ENABLED'),
    'public_url' => rtrim((string) env('COMMUNICATIONS_PUBLIC_URL', env('APP_URL')), '/'),
    'per_minute' => (int) env('COMMUNICATIONS_PER_MINUTE', 30),
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'), 'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'sms_from' => env('TWILIO_SMS_FROM'), 'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],
    'aruba' => [
        'host' => strtolower(trim((string) env('ARUBA_SMTP_HOST', $useMail ? $mailHost : 'smtps.aruba.it'))), 'port' => (int) env('ARUBA_SMTP_PORT', $useMail ? env('MAIL_PORT', 465) : 465),
        'username' => env('ARUBA_SMTP_USERNAME') ?: ($useMail ? env('MAIL_USERNAME') : null), 'password' => env('ARUBA_SMTP_PASSWORD') ?: ($useMail ? env('MAIL_PASSWORD') : null),
        'from_address' => env('ARUBA_FROM_ADDRESS') ?: ($useMail ? env('MAIL_FROM_ADDRESS') : null), 'from_name' => env('ARUBA_FROM_NAME', 'Vista Express 360'),
    ],
];
