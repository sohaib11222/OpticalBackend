<?php

namespace App\Console\Commands;

use App\Integrations\Vista\VistaClient;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckVista extends Command
{
    protected $signature = 'optical:check-vista';

    protected $description = 'Check seller sign-in configuration without contacting Vista or displaying secrets';

    public function handle(VistaClient $client): int
    {
        try {
            $client->base();
        } catch (HttpException $e) {
            $this->error($e->getMessage());
            $this->line('Check VISTA_ENABLED, VISTA_BASE_URL and VISTA_CLIENT_SECRET; then rebuild the configuration cache.');
            return self::FAILURE;
        }
        $callback = (string) config('vista.redirect_uri');
        $shop = rtrim((string) config('optical.shop_url'), '/');
        if (! config('vista.client_id') || ! $shop || $callback !== $shop.'/auth/vista/callback'
            || (app()->environment('production') && ! str_starts_with($callback, 'https://'))) {
            $this->error('Set SHOP_URL to the public frontend URL and VISTA_REDIRECT_URI to SHOP_URL/auth/vista/callback. Production requires HTTPS and a client ID.');
            return self::FAILURE;
        }
        $this->info('Local configuration is valid. Confirm matching client ID, secret and exact callback on Vista, then test seller authorization in the browser.');
        return self::SUCCESS;
    }
}
