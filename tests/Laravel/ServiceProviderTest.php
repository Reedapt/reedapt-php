<?php

declare(strict_types=1);

namespace Reedapt\Tests\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use Reedapt\Client;
use Reedapt\Exception\ReedaptException;
use Reedapt\Laravel\Facades\Reedapt;
use Reedapt\Laravel\ReedaptServiceProvider;
use Reedapt\Tests\MockHttp;

final class ServiceProviderTest extends LaravelTestCase
{
    #[Test]
    public function the_client_is_a_singleton_built_from_config(): void
    {
        $this->app['config']->set('reedapt.base_url', 'https://example.test/v1');
        $client = $this->app->make(Client::class);
        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($client, $this->app->make(Client::class));
        $this->assertSame($client, $this->app->make('reedapt'));
        $this->assertSame('https://example.test/v1', $client->baseUrl);
    }

    #[Test]
    public function config_is_merged_with_defaults(): void
    {
        $this->assertSame('https://api.reedapt.com/v1', config('reedapt.base_url'));
        $this->assertSame(60.0, config('reedapt.timeout'));
        $this->assertSame(2, config('reedapt.max_retries'));
        $this->assertSame(300, config('reedapt.webhook.tolerance'));
    }

    #[Test]
    public function the_facade_reaches_the_client(): void
    {
        $this->assertSame($this->app->make(Client::class)->dubs, Reedapt::dubs());
        $http = new MockHttp(fn () => MockHttp::json(200, ['target_languages' => ['fr']]));
        $factory = new HttpFactory();
        $this->app->instance(Client::class, new Client('rk_test_x', ['http_client' => $http, 'request_factory' => $factory, 'stream_factory' => $factory]));
        Reedapt::clearResolvedInstances();
        $this->assertSame(['fr'], Reedapt::languages()->list()['target_languages']);
    }

    #[Test]
    public function a_missing_key_fails_when_the_client_is_resolved_not_at_boot(): void
    {
        $this->app['config']->set('reedapt.api_key', null);
        $this->app->forgetInstance(Client::class);
        $this->expectException(ReedaptException::class);
        $this->app->make(Client::class);
    }

    #[Test]
    public function the_config_is_publishable_under_the_reedapt_config_tag(): void
    {
        $paths = ReedaptServiceProvider::pathsToPublish(ReedaptServiceProvider::class, 'reedapt-config');
        $this->assertCount(1, $paths);
        $source = array_key_first($paths);
        $this->assertFileExists($source);
        $this->assertStringEndsWith('config/reedapt.php', $source);
        $this->assertStringEndsWith('config/reedapt.php', (string) reset($paths));
    }

    #[Test]
    public function the_webhook_middleware_alias_is_registered(): void
    {
        $this->assertArrayHasKey('reedapt.webhook', $this->app['router']->getMiddleware());
    }
}
