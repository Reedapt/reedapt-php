<?php

declare(strict_types=1);

namespace Reedapt\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Reedapt\Laravel\Facades\Reedapt;
use Reedapt\Laravel\ReedaptServiceProvider;

abstract class LaravelTestCase extends TestCase
{
    protected const SECRET = 'whsec_laravel';

    /** @param \Illuminate\Foundation\Application $app */
    protected function getPackageProviders($app): array
    {
        return [ReedaptServiceProvider::class];
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function getPackageAliases($app): array
    {
        return ['Reedapt' => Reedapt::class];
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('reedapt.api_key', 'rk_test_laravel');
        $app['config']->set('reedapt.webhook.secret', self::SECRET);
    }

    protected static function sign(string $body, ?int $t = null, string $secret = self::SECRET): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }
}
