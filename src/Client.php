<?php

declare(strict_types=1);

namespace Reedapt;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Reedapt\Exception\ApiException;
use Reedapt\Exception\ConnectionException;
use Reedapt\Exception\ReedaptException;
use Reedapt\Exception\TimeoutException;
use Reedapt\Resources\Dubs;
use Reedapt\Resources\Languages;
use Reedapt\Resources\Runs;
use Reedapt\Resources\Uploads;
use Reedapt\Resources\Usage;
use Reedapt\Resources\WebhookEndpoints;

/**
 * A client for the Reedapt API.
 *
 * Options:
 * - `base_url`        defaults to `REEDAPT_BASE_URL`, then https://api.reedapt.com/v1
 * - `timeout`         seconds, for the default HTTP client only (default 60)
 * - `max_retries`     retries after a failure worth retrying (default 2)
 * - `default_headers` extra headers on every request
 * - `http_client`     any PSR-18 client; found automatically when omitted
 * - `request_factory`, `stream_factory`   PSR-17 factories; found automatically when omitted
 * - `sleeper`         `callable(float $seconds): void`, used for backoff and polling
 *
 * @phpstan-import-type RequestOptions from Types
 * @phpstan-import-type CreateDubParams from Types
 * @phpstan-import-type DubsCreated from Types
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.reedapt.com/v1';

    public readonly string $baseUrl;

    public readonly int $maxRetries;

    public readonly Uploads $uploads;

    public readonly Dubs $dubs;

    /** Requests for changes to dubs. Early access. */
    public readonly Runs $runs;

    public readonly WebhookEndpoints $webhookEndpoints;

    public readonly Webhook $webhooks;

    public readonly Languages $languages;

    public readonly Usage $usage;

    private readonly string $apiKey;

    /** @var array<string, string> */
    private readonly array $defaultHeaders;

    private readonly ClientInterface $http;

    private readonly ClientInterface $uploadHttp;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    /** @var callable(float): void */
    private $sleeper;

    /**
     * @param array{base_url?: string, timeout?: int|float, max_retries?: int, default_headers?: array<string, string>, http_client?: ClientInterface, request_factory?: RequestFactoryInterface, stream_factory?: StreamFactoryInterface, sleeper?: callable(float): void} $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey = $apiKey !== null && $apiKey !== '' ? $apiKey : self::env('REEDAPT_API_KEY');
        if ($apiKey === null) {
            throw new ReedaptException('No API key. Pass it to the constructor or set the REEDAPT_API_KEY environment variable.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::env('REEDAPT_BASE_URL') ?? self::DEFAULT_BASE_URL, '/');
        $this->maxRetries = max(0, $options['max_retries'] ?? 2);
        $this->defaultHeaders = $options['default_headers'] ?? [];
        $timeout = (float) ($options['timeout'] ?? 60);

        $this->http = $options['http_client'] ?? self::defaultHttpClient($timeout);
        // Parts are large; give the default client a longer limit for them.
        $this->uploadHttp = isset($options['http_client'])
            ? $options['http_client']
            : self::defaultHttpClient(max($timeout, 600.0));
        $this->requests = $options['request_factory'] ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $options['stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->sleeper = $options['sleeper'] ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };

        $this->uploads = new Uploads($this);
        $this->dubs = new Dubs($this);
        $this->runs = new Runs($this);
        $this->webhookEndpoints = new WebhookEndpoints($this);
        $this->webhooks = new Webhook();
        $this->languages = new Languages($this);
        $this->usage = new Usage($this);
    }

    /**
     * Uploads a file (a path or an open stream), or has Reedapt fetch a public
     * `https://` link, and starts a dub for each target language. Returns once
     * the dubs have started, not when they finish.
     *
     * `$params` takes the arguments of `dubs->create()` except `upload_id`, plus
     * `filename`, `on_progress` and `idempotency_key`.
     *
     * @param string|resource $input
     * @param array<string, mixed> $params
     * @return DubsCreated
     */
    public function dub(mixed $input, array $params): array
    {
        $filename = $params['filename'] ?? null;
        $onProgress = $params['on_progress'] ?? null;
        $key = $params['idempotency_key'] ?? null;
        unset($params['filename'], $params['on_progress'], $params['idempotency_key']);
        $suffixed = static fn (string $suffix): ?string => is_string($key) && $key !== '' ? "{$key}:{$suffix}" : null;

        if (is_string($input) && preg_match('#^https?://#i', $input) === 1) {
            $upload = $this->uploads->fromUrl($input, [
                'filename' => $filename,
                'idempotency_key' => $suffixed('upload'),
            ]);
        } else {
            $upload = $this->uploads->uploadFile($input, [
                'filename' => $filename,
                'on_progress' => $onProgress,
                'idempotency_key' => $suffixed('upload'),
            ]);
        }

        /** @var CreateDubParams $dubParams */
        $dubParams = ['upload_id' => $upload['id']] + $params;
        $dubOptions = $suffixed('dubs');

        return $this->dubs->create($dubParams, $dubOptions === null ? [] : ['idempotency_key' => $dubOptions]);
    }

    /**
     * Sends a request, retrying what is worth retrying. `retry` is `safe` for
     * requests that can be sent again after any failure without doing the work
     * twice, and `unsafe` for ones only retried when the API refused them
     * before acting.
     *
     * @internal
     *
     * @param array{query?: array<string, mixed>, body?: array<mixed>, retry?: 'safe'|'unsafe', idempotent?: bool, text?: bool} $req
     * @param RequestOptions $options
     */
    public function request(string $method, string $path, array $req = [], array $options = []): mixed
    {
        $url = $this->baseUrl.$path;
        $query = array_filter($req['query'] ?? [], static fn (mixed $v): bool => $v !== null);
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $key = $options['idempotency_key'] ?? (($req['idempotent'] ?? false) ? self::uuid() : null);
        // A request carrying an idempotency key is safe to send again.
        $retry = $key !== null ? 'safe' : ($req['retry'] ?? 'unsafe');
        $maxRetries = $options['max_retries'] ?? $this->maxRetries;
        $body = array_key_exists('body', $req) ? json_encode($req['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        for ($attempt = 0;; $attempt++) {
            $request = $this->requests->createRequest($method, $url)
                ->withHeader('Accept', 'application/json')
                ->withHeader('User-Agent', 'reedapt-php/'.self::VERSION);
            foreach (($options['headers'] ?? []) + $this->defaultHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $request = $request->withHeader('Authorization', 'Bearer '.$this->apiKey);
            if ($body !== null) {
                $request = $request->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streams->createStream($body));
            }
            if ($key !== null) {
                $request = $request->withHeader('Idempotency-Key', $key);
            }

            try {
                try {
                    $response = $this->http->sendRequest($request);
                } catch (ClientExceptionInterface $e) {
                    throw self::connectionError($method, $path, $e);
                }
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return $this->parse($response, $req['text'] ?? false);
                }
                $error = ApiException::fromResponse($response);
            } catch (ConnectionException $e) {
                $error = $e;
            }
            if ($attempt >= $maxRetries || !self::shouldRetry($error, $retry)) {
                throw $error;
            }
            $this->sleep(self::retryDelay($error, $attempt));
        }
    }

    /** @internal */
    public function httpClient(): ClientInterface
    {
        return $this->http;
    }

    /** @internal Used for the large part uploads to storage. */
    public function uploadHttpClient(): ClientInterface
    {
        return $this->uploadHttp;
    }

    /** @internal */
    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requests;
    }

    /** @internal */
    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streams;
    }

    /** @internal */
    public function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    /** @internal Seconds to wait before retry number `$attempt` (from 0). */
    public static function backoff(int $attempt): float
    {
        return min(0.5 * 2 ** $attempt, 8.0) * (0.75 + mt_rand() / mt_getrandmax() * 0.25);
    }

    /** @internal */
    public static function connectionError(string $method, string $path, \Throwable $e): ConnectionException
    {
        if (preg_match('/timed out|timeout|time-out/i', $e->getMessage()) === 1) {
            return new TimeoutException("{$method} {$path} timed out: {$e->getMessage()}", 0, $e);
        }

        return new ConnectionException("{$method} {$path} failed: {$e->getMessage()}", 0, $e);
    }

    private function parse(ResponseInterface $response, bool $text): mixed
    {
        $content = (string) $response->getBody();
        if ($text) {
            return $content;
        }
        if ($response->getStatusCode() === 204 || $content === '') {
            return null;
        }
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ReedaptException('The API answered with something that is not JSON.', 0, $e);
        }
    }

    private static function shouldRetry(ReedaptException $error, string $retry): bool
    {
        if ($error instanceof ConnectionException) {
            return $retry === 'safe';
        }
        if (!$error instanceof ApiException) {
            return false;
        }
        // These 429s are limits that last minutes or hours, not a busy minute.
        if ($error->status === 429) {
            return $error->errorCode === 'RATE_LIMITED';
        }
        if ($error->status === 503) {
            return true;
        }
        if ($retry === 'unsafe') {
            return false;
        }
        if ($error->status === 409) {
            return $error->errorCode === 'IDEMPOTENCY_IN_PROGRESS';
        }

        return in_array($error->status, [408, 500, 502, 504], true);
    }

    private static function retryDelay(ReedaptException $error, int $attempt): float
    {
        if ($error instanceof ApiException) {
            $value = $error->header('retry-after');
            if ($value !== null && is_numeric($value) && (float) $value >= 0 && (float) $value <= 60) {
                return (float) $value;
            }
        }

        return self::backoff($attempt);
    }

    private static function defaultHttpClient(float $timeout): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client(['timeout' => $timeout, 'connect_timeout' => min($timeout, 10.0), 'http_errors' => false]);
        }
        // Any other PSR-18 client (Symfony's, for one) is used as it is; set its timeout yourself.
        return Psr18ClientDiscovery::find();
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
