<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessage;
use App\Services\Communications\Messages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationWebhookController extends Controller
{

    public function preferences(Request $r, string $uuid, string $hash)
    {
        abort_unless(hash_equals(hash_hmac('sha256', 'unsubscribe:'.$uuid, config('app.key')), $hash), 403);
        $m = CustomerMessage::withoutGlobalScope('shop')->where('public_id', $uuid)->where('channel', 'email')->firstOrFail();
        if ($r->isMethod('POST')) {
            DB::table('messaging_suppressions')->insertOrIgnore(['channel' => 'email', 'recipient_hash' => Messages::recipientHash($m->recipient), 'created_at' => now()]);
            CustomerMessage::withoutGlobalScope('shop')->where('channel', 'email')->where('recipient', $m->recipient)->whereIn('status', Messages::PENDING)->update(['status' => 'cancelled', 'error_code' => 'recipient_opted_out']);
            return response('<!doctype html><html lang="en"><meta charset="utf-8"><title>Preferences</title><p>Email messages disabled / Email disattivate.</p></html>')->header('Content-Type', 'text/html; charset=utf-8');
        }
        return response('<!doctype html><html lang="en"><meta charset="utf-8"><title>Email preferences</title><h1>Email preferences / Preferenze email</h1><form method="post"><p>Stop customer messages from Vista Express 360. / Disattiva i messaggi da Vista Express 360.</p><button type="submit">Unsubscribe / Disiscriviti</button></form></html>')->header('Content-Type', 'text/html; charset=utf-8')->header('Referrer-Policy', 'no-referrer');
    }
}
