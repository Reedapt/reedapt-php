<?php

declare(strict_types=1);

namespace Reedapt\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Reedapt\Client;
use Reedapt\Laravel\Http\Middleware\VerifyWebhookSignature;

final class ReedaptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/reedapt.php', 'reedapt');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $app->make('config');
            $key = $config->get('reedapt.api_key');
            $options = [
                'timeout' => (float) $config->get('reedapt.timeout', 60),
                'max_retries' => (int) $config->get('reedapt.max_retries', 2),
            ];
            $baseUrl = $config->get('reedapt.base_url');
            if (is_string($baseUrl) && $baseUrl !== '') {
                $options['base_url'] = $baseUrl;
            }

            return new Client(is_string($key) && $key !== '' ? $key : null, $options);
        });
        $this->app->alias(Client::class, 'reedapt');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/reedapt.php' => $this->app->configPath('reedapt.php')], 'reedapt-config');
        }

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('reedapt.webhook', VerifyWebhookSignature::class);
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Client::class, 'reedapt'];
    }
}
