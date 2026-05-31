<?php

namespace App\Providers;

use App\Services\Odoo\OdooClient;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class OdooServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OdooClient::class, function ($app) {
            $cfg = $app['config']['odoo'];

            foreach (['url', 'db', 'login', 'api_key'] as $key) {
                if (empty($cfg[$key])) {
                    throw new RuntimeException("Odoo config missing: {$key} (revisa .env)");
                }
            }

            return new OdooClient(
                url: $cfg['url'],
                db: $cfg['db'],
                login: $cfg['login'],
                apiKey: $cfg['api_key'],
                timeout: (int) ($cfg['timeout'] ?? 30),
            );
        });
    }
}
