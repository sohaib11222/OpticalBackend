<?php

namespace App\Integrations\Vista;

use App\Models\VistaConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VistaClient
{
    public function base(): string
    {
        $base = rtrim((string) config('vista.base_url'), '/');
        $u = parse_url($base);
        abort_unless(config('vista.enabled') && strlen((string) config('vista.client_secret')) >= 32 && $u && ! isset($u['user']) && ! isset($u['pass']) && ! isset($u['query']) && ! isset($u['fragment']), 503, 'Vista Express is not configured. Standalone shop features remain available.');
        abort_unless(($u['scheme'] ?? '') === 'https' || (app()->environment(['local', 'testing']) && ($u['scheme'] ?? '') === 'http' && in_array($u['host'] ?? '', ['localhost', '127.0.0.1'])), 503, 'Vista requires HTTPS or an explicit local development address.');

        return $base;
    }

    public function send(string $method, string $path, array $data = [], ?VistaConnection $connection = null, ?array $image = null): array
    {
        $base = $this->base();
        if ($connection) {
            abort_unless(hash_equals($connection->provider, $base) && $connection->status !== 'disconnected' && $connection->access_token, 409, 'Reconnect this Vista Express account.');
        }
        $request = Http::acceptJson()->connectTimeout(5)->timeout(config('vista.timeout', 20))->withoutRedirecting();
        if ($connection) {
            $request = $request->withToken($connection->access_token);
        }
        if ($image) {
            $request = $request->attach('file', $image['bytes'], $image['filename']);
        }
        try {
            $response = $method === 'GET' ? $request->get($base.'/api/optical/'.$path, $data) : $request->post($base.'/api/optical/'.$path, $data);
        } catch (ConnectionException) {
            throw new VistaFailure(503, 'Vista Express did not respond. Retry safely; reserved allocation is retained until the result is known.');
        }
        if (! $response->successful()) {
            $code = $response->status();
            $invalid = array_filter(array_keys((array) $response->json('errors', [])), fn ($key) => is_string($key) && preg_match('/^(product\.)?[a-z_]+(\.[0-9]+)?$/D', $key));
            $fields = $invalid ? ' Fields: '.implode(', ', array_slice($invalid, 0, 10)).'.' : '';
            // Never log HTTP requests, bearer tokens, OAuth secrets or arbitrary remote exception content.
            throw new VistaFailure($code, match ($code) {
                401,403 => 'Vista access expired, was revoked or the seller store is unavailable. Reconnect after resolving seller access.',409 => 'Vista product changed or this operation conflicts. Refresh the publication and review the mapping.',422 => 'Vista rejected product fields, category configuration, SKU or image. Review the publication details.'.$fields,default => 'Vista Express is temporarily unavailable. Retry the same operation.'
            });
        }
        $body = $response->status() === 204 ? [] : $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Vista Express returned an invalid response.');
        }

        return $body;
    }
}
