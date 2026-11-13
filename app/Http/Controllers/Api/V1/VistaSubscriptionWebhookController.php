<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Vista\VistaEntitlements;
use App\Models\Vista360Entitlement;
use App\Models\VistaConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VistaSubscriptionWebhookController extends Controller
{
    public function __invoke(Request $request, VistaEntitlements $entitlements): JsonResponse
    {
        $secret = (string) config('vista.webhook_secret');
        abort_unless(strlen($secret) >= 32, 503, 'Vista subscription webhook is not configured.');
        $body = $request->getContent();
        $timestamp = (string) $request->header('X-Vista360-Timestamp');
        $signature = (string) $request->header('X-Vista360-Signature');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature), 401, 'Invalid Vista subscription signature.');
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($data) && ($data['event'] ?? null) === 'vista360.subscription.changed', 422, 'Unsupported Vista subscription event.');
        $eventId = $data['event_id'] ?? null;
        abort_unless(is_string($eventId) && preg_match('/^[A-Za-z0-9-]{16,100}$/D', $eventId), 422, 'Invalid Vista subscription event.');
        if (Vista360Entitlement::withoutGlobalScope('shop')->where('source_event_id', $eventId)->exists()) {
            return response()->json(['data' => ['duplicate' => true]]);
        }
        $connection = VistaConnection::withoutGlobalScope('shop')
            ->where('access_mode', 'vista360')
            ->where('subject', (string) ($data['seller_subject'] ?? ''))
            ->where('store_id', (string) ($data['store_id'] ?? ''))
            ->first();
        if (! $connection) {
            // A seller may change a plan before they have ever opened the CRM.
            // A later OAuth login always fetches the provider's live state.
            return response()->json(['data' => ['ignored' => true]]);
        }
        $payload = $data['entitlement'] ?? null;
        abort_unless(is_array($payload) && is_bool($payload['active'] ?? null) && is_string($payload['status'] ?? null), 422, 'Invalid entitlement payload.');
        $entitlements->save($connection, $payload, $eventId, null);
        VistaConnection::withoutGlobalScope('shop')->whereKey($connection->id)->update([
            'status' => $payload['active'] ? 'connected' : 'subscription_required',
            'last_error' => $payload['active'] ? null : 'Vista Express 360 subscription is inactive or expired.',
        ]);
        return response()->json(['data' => ['updated' => true]]);
    }
}
