<?php

declare(strict_types=1);

namespace Reedapt\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Reedapt\Client;

/** A PSR-18 client that records each request and answers from a handler. */
final class MockHttp implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed, raw: string}> */
    public array $calls = [];

    /** @var list<float> */
    public array $sleeps = [];

    /** @param callable(array{method: string, url: string, headers: array<string, string>, body: mixed, raw: string}): (ResponseInterface|\Throwable) $handler */
    public function __construct(private $handler)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        $raw = (string) $request->getBody();
        $decoded = ($headers['content-type'] ?? '') === 'application/json' ? json_decode($raw, true) : null;
        $call = ['method' => $request->getMethod(), 'url' => (string) $request->getUri(), 'headers' => $headers, 'body' => $decoded, 'raw' => $raw];
        $this->calls[] = $call;
        $result = ($this->handler)($call);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    /** @param array<string, mixed> $options */
    public function client(array $options = []): Client
    {
        $factory = new HttpFactory();

        return new Client('rk_test_abc', $options + [
            'http_client' => $this,
            'request_factory' => $factory,
            'stream_factory' => $factory,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }

    /** @param array<mixed>|null $body @param array<string, string> $headers */
    public static function json(int $status, ?array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $headers */
    public static function error(int $status, string $code, array $headers = []): Response
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => "{$code} happened", 'request_id' => 'req_test']], $headers);
    }
}
