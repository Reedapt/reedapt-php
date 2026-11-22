<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Reedapt\Client;
use Reedapt\Resources\Dubs;
use Reedapt\Resources\Languages;
use Reedapt\Resources\Runs;
use Reedapt\Resources\Uploads;
use Reedapt\Resources\Usage;
use Reedapt\Resources\WebhookEndpoints;
use Reedapt\Webhook;

/**
 * The container's `Reedapt\Client`. Resources are methods here:
 * `Reedapt::dubs()->create([...])`, `Reedapt::usage()->retrieve()`.
 *
 * @method static array<string, mixed> dub(mixed $input, array<string, mixed> $params)
 *
 * @see Client
 */
final class Reedapt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    public static function client(): Client
    {
        /** @var Client */
        return static::getFacadeRoot();
    }

    public static function dubs(): Dubs
    {
        return self::client()->dubs;
    }

    public static function uploads(): Uploads
    {
        return self::client()->uploads;
    }

    public static function runs(): Runs
    {
        return self::client()->runs;
    }

    public static function webhookEndpoints(): WebhookEndpoints
    {
        return self::client()->webhookEndpoints;
    }

    public static function languages(): Languages
    {
        return self::client()->languages;
    }

    public static function usage(): Usage
    {
        return self::client()->usage;
    }

    public static function webhooks(): Webhook
    {
        return self::client()->webhooks;
    }
}
