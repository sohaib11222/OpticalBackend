<?php

// Authentication and system notifications use Laravel's mailer, whereas
// customer mail also has Aruba-specific diagnostics.  Keep both paths on the
// same mailbox when only ARUBA_SMTP_* is configured.
$arubaHost = env('ARUBA_SMTP_HOST');
$arubaPort = (int) env('ARUBA_SMTP_PORT', 465);
$arubaUsername = env('ARUBA_SMTP_USERNAME');
$usesAruba = filter_var(
    env('MAIL_USE_ARUBA', ! empty($arubaHost) && ! empty($arubaUsername) ? 'true' : 'false'),
    FILTER_VALIDATE_BOOL,
);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => $usesAruba ? 'smtp' : env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => $usesAruba ? env('ARUBA_SMTP_SCHEME', $arubaPort === 465 ? 'smtps' : 'smtp') : env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => $usesAruba ? $arubaHost : env('MAIL_HOST', '127.0.0.1'),
            'port' => $usesAruba ? $arubaPort : env('MAIL_PORT', 2525),
            'username' => $usesAruba ? $arubaUsername : env('MAIL_USERNAME'),
            'password' => $usesAruba ? env('ARUBA_SMTP_PASSWORD') : env('MAIL_PASSWORD'),
            'timeout' => (int) env('MAIL_TIMEOUT', 30),
            'require_tls' => (bool) env('MAIL_REQUIRE_TLS', true),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => $usesAruba ? env('ARUBA_FROM_ADDRESS') : env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => $usesAruba ? env('ARUBA_FROM_NAME', env('APP_DISPLAY_NAME', 'Vista Express 360')) : env('MAIL_FROM_NAME', env('APP_DISPLAY_NAME', 'Vista Express 360')),
    ],

    'reply_to' => [
        'address' => $usesAruba ? env('ARUBA_REPLY_TO_ADDRESS', env('ARUBA_FROM_ADDRESS')) : env('MAIL_REPLY_TO_ADDRESS'),
        'name' => $usesAruba ? env('ARUBA_REPLY_TO_NAME', env('ARUBA_FROM_NAME', env('APP_DISPLAY_NAME', 'Vista Express 360'))) : env('MAIL_REPLY_TO_NAME', env('APP_DISPLAY_NAME', 'Vista Express 360')),
    ],

];
