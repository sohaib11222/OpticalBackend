<?php

namespace App\Services\Files;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;

/** Import external photos into the same quarantine/scan pipeline as file uploads. */
class RemoteProductPhoto
{
    private const LIMIT = 5 * 1024 * 1024;

    protected function addresses(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    }

    public function download(string $url): UploadedFile
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            (isset($parts['port']) && $parts['port'] !== 443)) {
            $this->invalid();
        }
        $host = strtolower($parts['host']);
        $addresses = $this->addresses($host);
        // Validate every answer, then pin DNS for the actual TLS connection.
        // Redirects are rejected so they cannot jump to an internal service.
        if (! $addresses || array_filter($addresses, fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE))) {
            $this->invalid();
        }
        $path = tempnam(sys_get_temp_dir(), 'optical-photo-');
        if ($path === false) $this->invalid();
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->setHandler(new CurlHandler())->withOptions([
                'proxy' => '',
                'decode_content' => false,
                'sink' => $path,
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]]],
                'force_ip_resolve' => 'v4',
                'on_headers' => function (ResponseInterface $response) {
                    if ((int) $response->getHeaderLine('Content-Length') > self::LIMIT) $this->invalid();
                },
                'progress' => function ($total, $downloaded) {
                    if ($total > self::LIMIT || $downloaded > self::LIMIT) $this->invalid();
                },
            ])->get($url);
            if (! $response->successful()) $this->invalid();
            clearstatcache(true, $path);
            if (filesize($path) > self::LIMIT) $this->invalid();
            $bytes = $response->body();
            if (! $bytes || strlen($bytes) > self::LIMIT) $this->invalid();
            if (file_put_contents($path, $bytes) === false) $this->invalid();
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $ext = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null };
            if (! $ext) $this->invalid();
            return new UploadedFile($path, 'imported-photo.'.$ext, $mime, null, true);
        } catch (\Throwable $e) {
            @unlink($path);
            // Do not expose a URL containing private query parameters in errors/logs.
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['url' => 'Choose a direct public HTTPS image URL (PNG, JPEG or WebP, up to 5 MB). Redirects and private network addresses are not supported.']);
    }
}
