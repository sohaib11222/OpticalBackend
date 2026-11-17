<?php

namespace App\Services\Communications;

use App\Models\CustomerMessage;
use App\Models\Shop;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class Provider
{
    public function ready(string $channel): bool
    {
        if ($channel === 'email') return $this->emailIssues() === [];
        // WhatsApp is intentionally browser-only click-to-chat and SMS has
        // been retired.  Neither channel may ever be sent by the backend.
        return false;
    }

    /** Safe diagnostics: names of missing settings, never credential values. */
    public function emailIssues(): array
    {
        $issues = [];
        // `null` is treated as the default enabled state. This also keeps
        // a long-running worker safe when an old cached configuration has no
        // explicit key yet; only an intentional boolean false disables mail.
        if (config('communications.email_enabled') === false) $issues[] = 'Enable COMMUNICATIONS_EMAIL_ENABLED=true.';
        foreach (['username' => 'ARUBA_SMTP_USERNAME', 'password' => 'ARUBA_SMTP_PASSWORD'] as $key => $env) {
            if (! config('communications.aruba.'.$key)) $issues[] = 'Missing '.$env.'.';
        }
        if (! filter_var(config('communications.aruba.from_address'), FILTER_VALIDATE_EMAIL)) $issues[] = 'Set a valid ARUBA_FROM_ADDRESS.';
        if (! in_array(config('communications.aruba.host'), ['smtps.aruba.it', 'smtp.aruba.it'], true)) $issues[] = 'ARUBA_SMTP_HOST must be smtps.aruba.it or smtp.aruba.it.';
        if (! in_array((int) config('communications.aruba.port'), [465, 587], true)) $issues[] = 'ARUBA_SMTP_PORT must be 465 (TLS) or 587 (STARTTLS).';
        return $issues;
    }

    public function emailTransport(): EsmtpTransport
    {
        $settings = config('communications.aruba');
        $transport = new EsmtpTransport($settings['host'], (int) $settings['port'], (int) $settings['port'] === 465);
        $transport->setRequireTls(true);
        $transport->setUsername($settings['username']);
        $transport->setPassword($settings['password']);
        $transport->getStream()->setTimeout(20);
        return $transport;
    }

    public function send(CustomerMessage $m): array
    {
        if (! $this->ready($m->channel)) {
            throw new DeliveryFailure('blocked', 'provider_not_ready', $m->channel === 'email' ? implode(' ', $this->emailIssues()) : 'Platform messaging is disabled or missing provider credentials.');
        }
        if ($m->channel === 'email') {
            $settings = config('communications.aruba');
            $transport = $this->emailTransport();
            $shop = Shop::findOrFail($m->shop_id);
            $customer = $m->customer;
            $greeting = $customer
                ? 'Gentile '.$customer->first_name.' '.$customer->last_name.','
                : 'Gentile cliente,';
            $brand = (string) $settings['from_name'];
            $html = view('mail.vista-360', [
                'brand' => $brand,
                'subject' => $m->subject,
                'preheader' => $m->subject,
                'headline' => $m->subject,
                'greeting' => $greeting,
                'body' => $m->body,
                'note' => 'Messaggio inviato da '.$shop->name.'.',
            ])->render();
            $text = trim($m->subject)."\n\n".trim($m->body)."\n\n".$brand;
            $email = (new Email)
                ->from(new Address($settings['from_address'], $brand))
                ->to($m->recipient)
                ->subject($m->subject)
                ->html($html)
                ->text($text);
            $replyTo = $settings['reply_to_address'] ?? null;
            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $email->replyTo(new Address($replyTo, $settings['reply_to_name'] ?? $brand));
            }
            $email->getHeaders()->addIdHeader('Message-ID', $m->public_id.'@'.explode('@', $settings['from_address'])[1]);
            try {
                $this->deliverEmail($email, $transport);
            } catch (TransportExceptionInterface $e) {
                // SMTP 5xx/authentication/recipient failures happen before a
                // provider accepts the message, so they are safe to surface
                // as a retryable failure.  Do not expose Aruba's raw response
                // because it may contain mailbox or infrastructure details.
                $diagnostic = strtolower($e->getMessage());
                if (preg_match('/\\b5\\d{2}\\b|authentication|auth(?:entication)? failed|invalid recipient|recipient address|mailbox/i', $diagnostic)) {
                    throw new DeliveryFailure('failed', 'smtp_rejected', 'Aruba rejected this email. Check the recipient address and SMTP sender configuration, then retry.');
                }

                throw new DeliveryFailure('retry_wait', 'smtp_temporary_unavailable', 'Aruba is temporarily unavailable. The CRM will retry this email automatically.');
            } catch (\Throwable $e) {
                // A connection can fail after SMTP has accepted DATA.  Treat
                // that ambiguous state conservatively to avoid duplicate mail.
                throw new DeliveryFailure('uncertain', 'smtp_submission_unknown', 'Email submission could not be confirmed. Check the mailbox/provider before trying again.');
            }

            // SMTP has accepted the message.  This is the strongest delivery
            // acknowledgement SMTP can give; mailbox-open tracking is not
            // claimed as delivered.
            return ['status' => 'sent', 'provider_id' => 'smtp:'.$m->public_id];
        }

        throw new DeliveryFailure('cancelled', 'channel_retired', 'SMS and automated WhatsApp delivery are not available. Use WhatsApp Web click-to-chat instead.');
    }

    public function poll(CustomerMessage $m): ?array
    {
        return null;
    }

    protected function deliverEmail(Email $email, EsmtpTransport $transport): void
    {
        (new Mailer($transport))->send($email);
    }
}
