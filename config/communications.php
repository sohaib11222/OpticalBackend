<?php

return [
    'enabled' => (bool) env('COMMUNICATIONS_ENABLED', false),
    'public_url' => rtrim((string) env('COMMUNICATIONS_PUBLIC_URL', env('APP_URL')), '/'),
    'per_minute' => (int) env('COMMUNICATIONS_PER_MINUTE', 30),
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'), 'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'sms_from' => env('TWILIO_SMS_FROM'), 'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],
    'aruba' => [
        'host' => env('ARUBA_SMTP_HOST', 'smtps.aruba.it'), 'port' => 465,
        'username' => env('ARUBA_SMTP_USERNAME'), 'password' => env('ARUBA_SMTP_PASSWORD'),
        'from_address' => env('ARUBA_FROM_ADDRESS'), 'from_name' => env('ARUBA_FROM_NAME', 'Vista Express 360'),
    ],
];
