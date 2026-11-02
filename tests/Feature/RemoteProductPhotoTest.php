<?php

namespace Tests\Feature;

use App\Services\Files\RemoteProductPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemoteProductPhotoTest extends TestCase
{
    private function downloader(array $addresses = ['93.184.216.34']): RemoteProductPhoto
    {
        return new class($addresses) extends RemoteProductPhoto {
            public function __construct(private array $ips) {}
            protected function addresses(string $host): array { return $this->ips; }
        };
    }

    public function test_private_addresses_unsupported_schemes_and_credentials_are_rejected_before_network_access(): void
    {
        Http::fake();
        foreach (['http://example.test/photo.png', 'https://user:password@example.test/photo.png', 'https://example.test:8443/photo.png', 'file:///etc/passwd'] as $url) {
            try { $this->downloader()->download($url); $this->fail('Unsafe URL accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('url', $e->errors()); }
        }
        foreach ([[], ['127.0.0.1'], ['10.0.0.1'], ['169.254.169.254'], ['100.64.0.1'], ['93.184.216.34', '192.168.1.1']] as $ips) {
            try { $this->downloader($ips)->download('https://example.test/photo.png'); $this->fail('Private address accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('url', $e->errors()); }
        }
        Http::assertNothingSent();
    }

    public function test_import_download_pins_public_dns_and_produces_an_upload_for_scanning(): void
    {
        $image = UploadedFile::fake()->image('photo.png');
        $bytes = file_get_contents($image->getPathname());
        $options = [];
        Http::fake(function ($request, $requestOptions) use ($bytes, &$options) {
            $options = $requestOptions;
            return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
        });
        $file = $this->downloader()->download('https://example.test/photo.png');
        try {
            $this->assertSame('image/png', $file->getMimeType());
            $this->assertSame($bytes, file_get_contents($file->getPathname()));
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['decode_content']);
            $this->assertSame(['example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        } finally { unlink($file->getPathname()); }
    }

    public function test_redirects_non_images_and_large_bodies_are_rejected(): void
    {
        foreach ([Http::response('', 302, ['Location' => 'http://127.0.0.1']), Http::response('<html>error</html>'), Http::response(str_repeat('x', 5 * 1024 * 1024 + 1))] as $response) {
            Http::fake(['*' => $response]);
            try { $this->downloader()->download('https://example.test/photo.png'); $this->fail('Invalid photo accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('url', $e->errors()); }
        }
    }
}
