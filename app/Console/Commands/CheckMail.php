<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CheckMail extends Command
{
    protected $signature = 'optical:mail-check {--send-to= : Explicitly send one diagnostic email to this address}';

    protected $description = 'Check SMTP configuration without revealing credentials; optionally send a diagnostic message.';

    public function handle(): int
    {
        $errors = [];
        $smtp = config('mail.mailers.smtp', []);
        if (config('mail.default') !== 'smtp') {
            $errors[] = 'Set MAIL_MAILER=smtp to test real delivery.';
        }
        if (! empty($smtp['url'])) {
            $errors[] = 'Remove MAIL_URL for this check; use the individual MAIL_* settings to avoid hidden overrides.';
        }
        foreach (['host' => 'MAIL_HOST', 'username' => 'MAIL_USERNAME', 'password' => 'MAIL_PASSWORD'] as $key => $env) {
            if (trim((string) ($smtp[$key] ?? '')) === '') {
                $errors[] = "Set {$env} in the backend .env.";
            }
        }
        if (! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Set a valid MAIL_FROM_ADDRESS.';
        }
        if (! extension_loaded('openssl')) {
            $errors[] = 'Enable the PHP OpenSSL extension for encrypted SMTP.';
        }
        if (strtolower((string) ($smtp['host'] ?? '')) === 'smtp.gmail.com') {
            if (! in_array([$smtp['scheme'] ?? null, (int) ($smtp['port'] ?? 0)], [['smtp', 587], ['smtps', 465]], true)) {
                $errors[] = 'Gmail requires MAIL_SCHEME=smtp / MAIL_PORT=587 or smtps / 465.';
            }
            if (! ($smtp['require_tls'] ?? false)) {
                $errors[] = 'Set MAIL_REQUIRE_TLS=true for Gmail.';
            }
            if (! filter_var($smtp['username'] ?? '', FILTER_VALIDATE_EMAIL)
                || strcasecmp((string) config('mail.from.address'), (string) ($smtp['username'] ?? '')) !== 0) {
                $errors[] = 'Use the same testing Gmail address for MAIL_USERNAME and MAIL_FROM_ADDRESS.';
            }
            if (preg_match('/\s/', (string) ($smtp['password'] ?? ''))) {
                $errors[] = 'Remove display spaces from MAIL_PASSWORD; use the Google App Password.';
            }
        }
        $recipient = $this->option('send-to');
        if ($recipient !== null && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Supply a valid --send-to email address.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $this->info('SMTP configuration is complete. Credentials are not displayed.');
        $this->line('Verification and password-reset notifications still require a running queue worker.');
        if ($recipient === null) {
            $this->line('No network connection or email send was attempted. Use --send-to to test SMTP acceptance.');

            return self::SUCCESS;
        }
        try {
            Mail::mailer('smtp')->raw(
                'Vista Express 360 CRM SMTP test. This diagnostic message does not verify an account. Request verification from the account page.',
                fn (Message $message) => $message->to($recipient)->subject('Vista Express 360 CRM SMTP test'),
            );
        } catch (Throwable) {
            // SMTP exception transcripts can include credentials. Do not print or log them here.
            $this->error('SMTP send failed. Check the App Password, TLS/CA certificates, host/port and network access. See docs/gmail-smtp-testing.md.');

            return self::FAILURE;
        }
        $this->info('SMTP accepted the diagnostic message. Check the inbox and spam folder; this does not prove inbox delivery or queue processing.');

        return self::SUCCESS;
    }
}
