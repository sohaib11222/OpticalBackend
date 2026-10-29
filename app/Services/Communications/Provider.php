<?php

namespace App\Services\Communications;

use App\Models\CustomerMessage;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class Provider
{
    public function ready(string $channel): bool
    {
        if (! in_array($channel, ['sms', 'whatsapp', 'email'], true) || ! config('communications.enabled')) {
            return false;
        }
        if ($channel === 'email') {
            return (bool) (config('communications.aruba.username') && config('communications.aruba.password') && filter_var(config('communications.aruba.from_address'), FILTER_VALIDATE_EMAIL) && str_starts_with(config('communications.public_url', ''), 'https://') && in_array(config('communications.aruba.host'), ['smtps.aruba.it', 'smtp.aruba.it']) && in_array((int) config('communications.aruba.port'), [465, 587], true));
        }

        $service = preg_match('/^MG[0-9a-f]{32}$/i', config('communications.twilio.messaging_service_sid', '') ?? '');
        $sender = preg_match('/^\+[1-9][0-9]{7,14}$/D', config('communications.twilio.sms_from', '') ?? '');
        if ($channel === 'sms' && config('communications.twilio.messaging_service_sid') && ! $service) return false;
        return (bool) (preg_match('/^AC[0-9a-f]{32}$/i', config('communications.twilio.account_sid', '') ?? '') && config('communications.twilio.auth_token') && ($channel === 'sms' ? ($service || $sender) : ($service && config('communications.twilio.whatsapp_from'))) && str_starts_with(config('communications.public_url', ''), 'https://'));
    }

    public function callback(string $uuid): string
    {
        return config('communications.public_url').'/api/v1/communications/callback/'.$uuid;
    }

    public function send(CustomerMessage $m): array
    {
        if (! $this->ready($m->channel)) {
            throw new DeliveryFailure('blocked', 'provider_not_ready', 'Platform messaging is disabled or missing provider credentials.');
        }
        if ($m->channel === 'email') {
            $settings = config('communications.aruba');
            $transport = new EsmtpTransport($settings['host'], (int) $settings['port'], (int) $settings['port'] === 465);
            $transport->setRequireTls(true);
            $transport->setUsername($settings['username']);
            $transport->setPassword($settings['password']);
            $transport->getStream()->setTimeout(20);
            $email = (new Email)->from(new Address($settings['from_address'], Shop::findOrFail($m->shop_id)->name.' / '.$settings['from_name']))->to($m->recipient)->subject($m->subject)->text($m->body."\n\nUnsubscribe / Disiscriviti: ".config('communications.public_url').'/api/v1/communications/preferences/'.$m->public_id.'/'.hash_hmac('sha256', 'unsubscribe:'.$m->public_id, config('app.key')));
            $email->getHeaders()->addIdHeader('Message-ID', $m->public_id.'@'.explode('@', $settings['from_address'])[1]);
            try {
                $this->deliverEmail($email, $transport);
            } catch (\Throwable $e) {
                throw new DeliveryFailure('uncertain', 'smtp_submission_unknown', 'Email submission could not be confirmed. Check the mailbox/provider before trying again.');
            }

            return ['status' => 'accepted', 'provider_id' => 'smtp:'.$m->public_id];
        }
        $data = ['To' => ($m->channel === 'whatsapp' ? 'whatsapp:' : '').$m->recipient, 'StatusCallback' => $this->callback($m->public_id)];
        if (config('communications.twilio.messaging_service_sid')) $data['MessagingServiceSid'] = config('communications.twilio.messaging_service_sid');
        if ($m->channel === 'whatsapp') {
            $data += ['From' => config('communications.twilio.whatsapp_from'), 'ContentSid' => $m->content_sid, 'ContentVariables' => json_encode((object) $m->content_variables)];
        } else {
            $data['Body'] = $m->body;
            if (config('communications.twilio.sms_from')) {
                $data['From'] = config('communications.twilio.sms_from');
            }
        }
        try {
            $response = $this->client()->asForm()->post($this->base().'/Messages.json', $data);
        } catch (\Throwable $e) {
            throw new DeliveryFailure('uncertain', 'submission_unknown', 'Provider submission could not be confirmed. Reconcile before sending another message.');
        }
        if ($response->status() === 429) {
            throw new DeliveryFailure('retry_wait', 'provider_throttled', 'Provider rate limit reached. A safe retry is scheduled.');
        }
        if ($response->serverError()) {
            throw new DeliveryFailure('uncertain', 'provider_unavailable', 'Provider submission could not be confirmed. Reconcile before sending another message.');
        }
        if ((int) $response->json('code') === 21610) {
            DB::table('messaging_suppressions')->updateOrInsert(['channel' => $m->channel, 'recipient_hash' => Messages::recipientHash($m->recipient)], ['created_at' => now()]);
        }
        if (! $response->successful()) {
            throw new DeliveryFailure('failed', 'twilio_'.(int) $response->json('code'), 'Provider rejected the request. Check sender, recipient and template configuration.');
        }
        $sid = $response->json('sid');
        if (! is_string($sid) || ! preg_match('/^(SM|MM)[a-f0-9]{32}$/i', $sid)) {
            throw new DeliveryFailure('uncertain', 'invalid_provider_response', 'Provider acceptance could not be verified.');
        }

        return ['status' => 'accepted', 'provider_id' => $sid];
    }

    public function poll(CustomerMessage $m): ?array
    {
        if ($m->channel === 'email' || ! $m->provider_id || ! $this->ready($m->channel)) {
            return null;
        }
        try {
            $r = $this->client()->get($this->base().'/Messages/'.rawurlencode($m->provider_id).'.json');

            return $r->successful() ? ['status' => $r->json('status'), 'code' => $r->json('error_code')] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function deliverEmail(Email $email, EsmtpTransport $transport): void
    {
        (new Mailer($transport))->send($email);
    }
    private function base(): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/'.config('communications.twilio.account_sid');
    }

    private function client()
    {
        return Http::withBasicAuth(config('communications.twilio.account_sid'), config('communications.twilio.auth_token'))->connectTimeout(5)->timeout(20);
    }
}
