<?php

namespace Tests\Feature;

use Tests\TestCase;

class VistaConfigurationTest extends TestCase
{
    public function test_disabled_integration_is_reported_and_cannot_start(): void
    {
        config(['vista.enabled' => false]);
        $this->getJson('/api/v1/auth/vista/config')->assertOk()->assertExactJson(['data' => ['enabled' => false]]);
        $this->postJson('/api/v1/auth/vista/start', ['mode' => 'login'])->assertStatus(503);
        $this->artisan('optical:check-vista')->assertFailed();
    }

    public function test_production_configuration_and_pkce_redirect(): void
    {
        $this->app->instance('env', 'production');
        config(['vista.enabled' => true, 'vista.base_url' => 'https://vista.example.test',
            'vista.client_secret' => str_repeat('s', 48), 'vista.client_id' => 'optical-shop',
            'optical.shop_url' => 'https://crm.example.test/pulse-ui',
            'vista.redirect_uri' => 'https://crm.example.test/pulse-ui/auth/vista/callback']);
        $this->artisan('optical:check-vista')->assertSuccessful();
        $this->withSession(['_token' => 'vista-config-test'])->withHeader('X-CSRF-TOKEN', 'vista-config-test');
        $this->getJson('/api/v1/auth/vista/config')->assertExactJson(['data' => ['enabled' => true]]);
        $url = $this->postJson('/api/v1/auth/vista/start', ['mode' => 'login'])->assertOk()->json('data.authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(config('vista.redirect_uri'), $query['redirect_uri']);
        $this->assertStringNotContainsString(config('vista.client_secret'), $url);
        config(['vista.redirect_uri' => 'https://other.example.test/auth/vista/callback']);
        $this->artisan('optical:check-vista')->assertFailed();
        $this->postJson('/api/v1/auth/vista/start', ['mode' => 'login'])->assertStatus(503);
    }
}
