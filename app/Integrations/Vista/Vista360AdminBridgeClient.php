<?php

namespace App\Integrations\Vista;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private CRM-admin to Marketplace-admin transport.
 *
 * This is intentionally separate from a seller's OAuth grant: a CRM platform
 * administrator never receives a Marketplace admin token in the browser.
 */
class Vista360AdminBridgeClient
{
    private function base(): string
    {
        $base = rtrim((string) config('vista.admin_bridge_url'), '/');
        $parts = parse_url($base);
        abort_unless(config('vista.admin_bridge_enabled') && strlen((string) config('vista.admin_bridge_secret')) >= 32 && $parts && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']), 503, 'Vista Express 360 subscription management is not configured yet.');
        abort_unless(($parts['scheme'] ?? '') === 'https' || (app()->environment(['local', 'testing']) && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'])), 503, 'Vista Express 360 subscription management requires a secure Marketplace connection.');

        return $base;
    }

    /** @return array<string,mixed> */
    public function send(string $method, string $path, array $data = []): array
    {
        $base = $this->base();
        $method = strtoupper($method);
        $target = $base.'/'.ltrim($path, '/');
        $uri = (string) parse_url($target, PHP_URL_PATH);
        if (($query = parse_url($target, PHP_URL_QUERY)) !== null && $query !== '') {
            $uri .= '?'.$query;
        }
        $body = in_array($method, ['POST', 'PUT', 'PATCH'], true)
            ? json_encode($data, JSON_THROW_ON_ERROR)
            : '';
        $timestamp = (string) time();
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $method, $uri, $nonce, $body]), (string) config('vista.admin_bridge_secret'));
        $request = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->withoutRedirecting()
            ->withHeaders([
                'X-Vista360-Bridge-Timestamp' => $timestamp,
                'X-Vista360-Bridge-Nonce' => $nonce,
                'X-Vista360-Bridge-Signature' => $signature,
            ]);
        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        try {
            $response = $request->send($method, $target);
        } catch (ConnectionException) {
            throw new VistaFailure(503, 'Vista Express 360 subscription management did not respond. Try again shortly.');
        }
        if (! $response->successful()) {
            $status = $response->status();
            throw new VistaFailure($status, match ($status) {
                401, 403 => 'Vista Express 360 subscription management rejected this administrator request.',
                404 => 'Vista Express 360 subscription management is not enabled on the Marketplace yet.',
                409 => 'The subscription changed. Refresh the page and try again.',
                422 => 'Review the subscription details and correct the highlighted values.',
                default => 'Vista Express 360 subscription management is temporarily unavailable. Try again shortly.',
            });
        }
        $payload = $response->json();
        if (! is_array($payload) || ! array_key_exists('data', $payload)) {
            throw new RuntimeException('Vista Express 360 subscription management returned an invalid response.');
        }

        return $payload;
    }
}
