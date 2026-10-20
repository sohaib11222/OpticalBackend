<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessage;
use App\Models\ShopMembership;
use App\Services\Communications\Messages;
use App\Services\Communications\Provider;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Twilio\Security\RequestValidator;

class CommunicationWebhookController extends Controller
{
    private function validateSignature(Request $r, string $url): void
    {
        $token = config('communications.twilio.auth_token');
        abort_unless($token && str_starts_with($url, 'https://') && str_starts_with($r->header('Content-Type', ''), 'application/x-www-form-urlencoded'), 403);
        // Original form values must not be trimmed; configured URL avoids Host/proxy spoofing.
        abort_unless((new RequestValidator($token))->validate($r->header('X-Twilio-Signature', ''), $url, $r->request->all()), 403);
        abort_unless(hash_equals((string) config('communications.twilio.account_sid'), (string) $r->input('AccountSid')), 403);
    }

    public function status(Request $r, Provider $provider, Messages $messages, string $uuid)
    {
        $this->validateSignature($r, $provider->callback($uuid));
        $m = CustomerMessage::withoutGlobalScope('shop')->where('public_id', $uuid)->firstOrFail();
        $sid = (string) $r->input('MessageSid');
        abort_unless(preg_match('/^(SM|MM)[a-f0-9]{32}$/i', $sid), 422);
        abort_if($m->provider_id && ! hash_equals($m->provider_id, $sid), 403);
        abort_unless($r->input('To') === ($m->channel === 'whatsapp' ? 'whatsapp:' : '').$m->recipient, 403);
        $context = app(TenantContext::class);
        $context->set(new ShopMembership(['shop_id' => $m->shop_id]));
        try {
            if (! $m->provider_id && in_array($m->status, ['sending', 'uncertain'])) { $m->forceFill(['provider_id' => $sid, 'submitted_at' => now()])->save(); }
            $messages->providerStatus($m, (string) $r->input('MessageStatus'), $r->filled('ErrorCode') ? (string) $r->input('ErrorCode') : null);
        } finally { $context->clear(); }
        return response()->noContent();
    }

    public function inbound(Request $r)
    {
        $this->validateSignature($r, config('communications.public_url').'/api/v1/communications/inbound');
        $from = (string) $r->input('From');
        $channel = str_starts_with($from, 'whatsapp:') ? 'whatsapp' : 'sms';
        $recipient = preg_replace('/^whatsapp:/', '', $from);
        abort_unless(preg_match('/^\+[1-9][0-9]{7,14}$/D', $recipient), 422);
        $optout = strtoupper((string) $r->input('OptOutType'));
        $body = strtoupper(trim((string) $r->input('Body')));
        if ($optout === 'STOP' || in_array($body, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'])) {
            DB::table('messaging_suppressions')->insertOrIgnore(['channel' => $channel, 'recipient_hash' => Messages::recipientHash($recipient), 'created_at' => now()]);
            CustomerMessage::withoutGlobalScope('shop')->where('channel', $channel)->where('recipient', $recipient)->whereIn('status', Messages::PENDING)->update(['status' => 'cancelled', 'error_code' => 'recipient_opted_out']);
        } elseif ($optout === 'START') {
            DB::table('messaging_suppressions')->where('channel', $channel)->where('recipient_hash', Messages::recipientHash($recipient))->delete();
        }
        return response('<Response/>', 200)->header('Content-Type', 'text/xml');
    }

    public function preferences(Request $r, string $uuid, string $hash)
    {
        abort_unless(hash_equals(hash_hmac('sha256', 'unsubscribe:'.$uuid, config('app.key')), $hash), 403);
        $m = CustomerMessage::withoutGlobalScope('shop')->where('public_id', $uuid)->where('channel', 'email')->firstOrFail();
        if ($r->isMethod('POST')) {
            DB::table('messaging_suppressions')->insertOrIgnore(['channel' => 'email', 'recipient_hash' => Messages::recipientHash($m->recipient), 'created_at' => now()]);
            CustomerMessage::withoutGlobalScope('shop')->where('channel', 'email')->where('recipient', $m->recipient)->whereIn('status', Messages::PENDING)->update(['status' => 'cancelled', 'error_code' => 'recipient_opted_out']);
            return response('<!doctype html><html lang="en"><meta charset="utf-8"><title>Preferences</title><p>Email messages disabled / Email disattivate.</p></html>')->header('Content-Type', 'text/html; charset=utf-8');
        }
        return response('<!doctype html><html lang="en"><meta charset="utf-8"><title>Email preferences</title><h1>Email preferences / Preferenze email</h1><form method="post"><p>Stop customer messages from Vista Express Business. / Disattiva i messaggi da Vista Express Business.</p><button type="submit">Unsubscribe / Disiscriviti</button></form></html>')->header('Content-Type', 'text/html; charset=utf-8')->header('Referrer-Policy', 'no-referrer');
    }
}
