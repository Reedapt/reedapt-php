<?php

declare(strict_types=1);

namespace Reedapt\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Reedapt\Client;
use Reedapt\Exception\ApiException;
use Reedapt\Exception\AuthenticationException;
use Reedapt\Exception\ConflictException;
use Reedapt\Exception\ConnectionException;
use Reedapt\Exception\InvalidRequestException;
use Reedapt\Exception\RateLimitException;
use Reedapt\Exception\ReedaptException;
use Reedapt\Exception\ServerException;
use Reedapt\Exception\TimeoutException;
use Reedapt\Exception\WaitException;

final class ClientTest extends TestCase
{
    private const DUB = ['id' => 'job_1', 'status' => 'queued', 'progress' => 0, 'audio' => false, 'test' => true, 'created_at' => 'x'];

    private const BASE = 'https://api.reedapt.com/v1';

    private static function down(string $message = 'connection refused'): \Throwable
    {
        return new class ($message) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message)
            {
                parent::__construct($message);
            }

            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return new \GuzzleHttp\Psr7\Request('GET', 'https://x.test');
            }
        };
    }

    #[Test]
    public function refuses_to_start_without_a_key(): void
    {
        $before = getenv('REEDAPT_API_KEY');
        putenv('REEDAPT_API_KEY');
        try {
            $this->expectException(ReedaptException::class);
            new Client();
        } finally {
            if ($before !== false) {
                putenv("REEDAPT_API_KEY={$before}");
            }
        }
    }

    #[Test]
    public function reads_the_key_and_base_url_from_the_environment(): void
    {
        putenv('REEDAPT_API_KEY=rk_test_env');
        putenv('REEDAPT_BASE_URL=https://example.test/v1/');
        try {
            $http = new MockHttp(fn () => MockHttp::json(200, ['target_languages' => ['fr']]));
            $factory = new \GuzzleHttp\Psr7\HttpFactory();
            (new Client(null, ['http_client' => $http, 'request_factory' => $factory, 'stream_factory' => $factory]))->languages->list();
            $this->assertSame('https://example.test/v1/languages', $http->calls[0]['url']);
            $this->assertSame('Bearer rk_test_env', $http->calls[0]['headers']['authorization']);
        } finally {
            putenv('REEDAPT_API_KEY');
            putenv('REEDAPT_BASE_URL');
        }
    }

    #[Test]
    public function sends_the_key_a_user_agent_and_json(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(201, ['dubs' => [self::DUB], 'refused' => []]));
        $created = $http->client()->dubs->create(['upload_id' => 'job_1', 'source_language' => 'en', 'target_languages' => ['fr']]);
        $this->assertSame('job_1', $created['dubs'][0]['id']);
        $call = $http->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE.'/dubs', $call['url']);
        $this->assertSame('Bearer rk_test_abc', $call['headers']['authorization']);
        $this->assertMatchesRegularExpression('#^reedapt-php/\d#', $call['headers']['user-agent']);
        $this->assertSame('application/json', $call['headers']['content-type']);
        $this->assertSame(['upload_id' => 'job_1', 'source_language' => 'en', 'target_languages' => ['fr']], $call['body']);
    }

    #[Test]
    public function creating_dubs_carries_an_idempotency_key_the_same_on_every_retry(): void
    {
        $n = 0;
        $http = new MockHttp(function () use (&$n) {
            return $n++ === 0 ? MockHttp::error(500, 'INTERNAL') : MockHttp::json(201, ['dubs' => []]);
        });
        $http->client()->dubs->create(['upload_id' => 'u', 'source_language' => 'en', 'target_languages' => ['fr']]);
        $this->assertCount(2, $http->calls);
        $key = $http->calls[0]['headers']['idempotency-key'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        $this->assertSame($key, $http->calls[1]['headers']['idempotency-key']);
    }

    #[Test]
    public function a_callers_idempotency_key_is_used_as_given(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(201, ['dubs' => []]));
        $http->client()->dubs->create(['upload_id' => 'u', 'source_language' => 'en', 'target_languages' => ['fr']], ['idempotency_key' => 'order-1042']);
        $this->assertSame('order-1042', $http->calls[0]['headers']['idempotency-key']);
    }

    #[Test]
    public function errors_carry_the_status_code_and_request_id_as_the_right_class(): void
    {
        $http = new MockHttp(fn () => MockHttp::error(401, 'UNAUTHORIZED'));
        try {
            $http->client()->usage->retrieve();
            $this->fail('Expected an exception.');
        } catch (AuthenticationException $e) {
            $this->assertInstanceOf(ApiException::class, $e);
            $this->assertSame(401, $e->status);
            $this->assertSame(401, $e->getCode());
            $this->assertSame('UNAUTHORIZED', $e->errorCode);
            $this->assertSame('req_test', $e->requestId);
            $this->assertStringStartsWith('UNAUTHORIZED: ', $e->getMessage());
        }
    }

    #[Test]
    public function the_request_id_falls_back_to_the_header(): void
    {
        $http = new MockHttp(fn () => new Response(502, ['Request-Id' => 'req_hdr'], 'Bad gateway'));
        try {
            $http->client(['max_retries' => 0])->usage->retrieve();
            $this->fail('Expected an exception.');
        } catch (ServerException $e) {
            $this->assertSame('req_hdr', $e->requestId);
            $this->assertSame('HTTP_502', $e->errorCode);
        }
    }

    #[Test]
    public function every_status_maps_to_its_class(): void
    {
        $map = [404 => \Reedapt\Exception\NotFoundException::class, 409 => ConflictException::class, 400 => InvalidRequestException::class, 411 => InvalidRequestException::class, 413 => InvalidRequestException::class, 422 => InvalidRequestException::class, 429 => RateLimitException::class, 503 => ServerException::class, 403 => ApiException::class];
        foreach ($map as $status => $class) {
            $http = new MockHttp(fn () => MockHttp::error($status, 'X', ['Retry-After' => '0']));
            try {
                $http->client(['max_retries' => 0])->usage->retrieve();
                $this->fail("Expected an exception for {$status}.");
            } catch (ApiException $e) {
                $this->assertSame($class, $e::class, (string) $status);
            }
        }
    }

    #[Test]
    public function validation_errors_list_their_fields(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(422, ['error' => ['code' => 'INVALID_REQUEST', 'message' => 'Some fields are missing or invalid.', 'request_id' => 'req_1', 'fields' => [['field' => 'target_languages', 'message' => 'Field required']]]]));
        try {
            $http->client()->dubs->create(['upload_id' => 'u', 'source_language' => 'en', 'target_languages' => []]);
            $this->fail('Expected an exception.');
        } catch (InvalidRequestException $e) {
            $this->assertSame('target_languages', $e->fields[0]['field']);
        }
        $this->assertCount(1, $http->calls, 'a 422 is not retried');
    }

    #[Test]
    public function a_rate_limit_waits_for_retry_after_then_retries(): void
    {
        $n = 0;
        $http = new MockHttp(function () use (&$n) {
            return $n++ === 0 ? MockHttp::error(429, 'RATE_LIMITED', ['Retry-After' => '3']) : MockHttp::json(200, ['target_languages' => []]);
        });
        $http->client()->languages->list();
        $this->assertCount(2, $http->calls);
        $this->assertSame([3.0], $http->sleeps);
    }

    #[Test]
    public function limits_that_last_longer_than_a_minute_are_not_retried(): void
    {
        $http = new MockHttp(fn () => MockHttp::error(429, 'TOO_MANY_DUBS_RUNNING', ['Retry-After' => '0']));
        try {
            $http->client()->dubs->create(['upload_id' => 'u', 'source_language' => 'en', 'target_languages' => ['fr']]);
            $this->fail('Expected an exception.');
        } catch (RateLimitException $e) {
            $this->assertSame(0.0, $e->retryAfter());
        }
        $this->assertCount(1, $http->calls);
    }

    #[Test]
    public function a_retry_after_over_a_minute_falls_back_to_backoff(): void
    {
        $n = 0;
        $http = new MockHttp(function () use (&$n) {
            return $n++ === 0 ? MockHttp::error(429, 'RATE_LIMITED', ['Retry-After' => '3600']) : MockHttp::json(200, ['target_languages' => []]);
        });
        $http->client()->languages->list();
        $this->assertGreaterThan(0.3, $http->sleeps[0]);
        $this->assertLessThanOrEqual(0.5, $http->sleeps[0]);
    }

    #[Test]
    public function server_errors_are_retried_up_to_max_retries_then_thrown(): void
    {
        $http = new MockHttp(fn () => MockHttp::error(502, 'BAD_GATEWAY'));
        $this->expectException(ServerException::class);
        try {
            $http->client(['max_retries' => 1])->languages->list();
        } finally {
            $this->assertCount(2, $http->calls);
        }
    }

    #[Test]
    public function a_network_failure_is_retried_for_safe_requests(): void
    {
        $n = 0;
        $http = new MockHttp(function () use (&$n) {
            return $n++ === 0 ? self::down() : MockHttp::json(200, ['target_languages' => []]);
        });
        $http->client()->languages->list();
        $this->assertCount(2, $http->calls);
    }

    #[Test]
    public function adding_a_webhook_endpoint_is_not_retried_after_a_network_failure(): void
    {
        $http = new MockHttp(fn () => self::down());
        try {
            $http->client()->webhookEndpoints->create(['url' => 'https://example.com/hook']);
            $this->fail('Expected an exception.');
        } catch (ConnectionException $e) {
            $this->assertNotInstanceOf(TimeoutException::class, $e);
        }
        $this->assertCount(1, $http->calls);
    }

    #[Test]
    public function a_timeout_is_a_timeout_exception(): void
    {
        $http = new MockHttp(fn () => self::down('cURL error 28: Operation timed out after 60001 milliseconds'));
        $this->expectException(TimeoutException::class);
        $http->client(['max_retries' => 0])->languages->list();
    }

    #[Test]
    public function cancel_and_delete_return_nothing_on_204(): void
    {
        $http = new MockHttp(fn () => new Response(204));
        $client = $http->client();
        $client->dubs->cancel('job_1');
        $client->webhookEndpoints->delete('we_1');
        $this->assertSame(self::BASE.'/dubs/job_1/cancel', $http->calls[0]['url']);
        $this->assertSame('DELETE', $http->calls[1]['method']);
    }

    #[Test]
    public function ids_are_url_encoded(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(200, self::DUB));
        $http->client()->dubs->retrieve('a/b c');
        $this->assertSame(self::BASE.'/dubs/a%2Fb%20c', $http->calls[0]['url']);
    }

    #[Test]
    public function list_all_walks_every_page_and_skips_repeats(): void
    {
        $pages = [
            '0' => ['data' => [['id' => 'a'] + self::DUB, ['id' => 'b'] + self::DUB], 'has_more' => true],
            '2' => ['data' => [['id' => 'b'] + self::DUB, ['id' => 'c'] + self::DUB], 'has_more' => false],
        ];
        $http = new MockHttp(function (array $call) use ($pages) {
            parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $q);

            return MockHttp::json(200, $pages[$q['offset']]);
        });
        $ids = [];
        foreach ($http->client()->dubs->listAll(['limit' => 2]) as $dub) {
            $ids[] = $dub['id'];
        }
        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertSame(self::BASE.'/dubs?limit=2&offset=0', $http->calls[0]['url']);
    }

    #[Test]
    public function wait_until_done_polls_until_the_dub_is_final(): void
    {
        $statuses = ['queued', 'processing', 'completed'];
        $http = new MockHttp(function () use (&$statuses) {
            return MockHttp::json(200, ['status' => array_shift($statuses)] + self::DUB);
        });
        $seen = [];
        $dub = $http->client()->dubs->waitUntilDone('job_1', ['poll_interval' => 1, 'on_update' => function (array $d) use (&$seen): void {
            $seen[] = $d['status'];
        }]);
        $this->assertSame('completed', $dub['status']);
        $this->assertSame(['queued', 'processing', 'completed'], $seen);
        $this->assertSame([1.0, 1.0], $http->sleeps);
    }

    #[Test]
    public function wait_until_done_gives_up_when_the_time_runs_out(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(200, self::DUB));
        $this->expectException(WaitException::class);
        $http->client()->dubs->waitUntilDone('job_1', ['poll_interval' => 10, 'timeout' => 5]);
    }

    #[Test]
    public function usage_report_asks_for_the_month_given(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(200, ['month' => '2026-09', 'minutes' => 1, 'keys' => [], 'languages' => []]));
        $client = $http->client();
        $this->assertSame('2026-09', $client->usage->report(['month' => '2026-09'])['month']);
        $this->assertSame(self::BASE.'/usage/report?month=2026-09', $http->calls[0]['url']);
        $client->usage->report();
        $this->assertSame(self::BASE.'/usage/report', $http->calls[1]['url']);
    }

    #[Test]
    public function transcript_returns_the_lines(): void
    {
        $line = ['start_ms' => 1200, 'end_ms' => 3850, 'speaker' => 'SPEAKER_00', 'original_text' => 'Hi.', 'text' => 'Salut.'];
        $http = new MockHttp(fn () => MockHttp::json(200, ['dub_id' => 'job_1', 'lines' => [$line]]));
        $this->assertSame([$line], $http->client()->dubs->transcript('job_1')['lines']);
        $this->assertSame(self::BASE.'/dubs/job_1/transcript', $http->calls[0]['url']);
    }

    #[Test]
    public function subtitles_return_the_files_text(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.200 --> 00:00:03.850\nSalut.\n";
        $http = new MockHttp(fn () => new Response(200, ['Content-Type' => 'text/vtt; charset=utf-8'], $vtt));
        $client = $http->client();
        $this->assertSame($vtt, $client->dubs->subtitles('job_1', ['format' => 'vtt', 'language' => 'source']));
        $this->assertSame(self::BASE.'/dubs/job_1/subtitles?format=vtt&language=source', $http->calls[0]['url']);
        $client->dubs->subtitles('job_1');
        $this->assertSame(self::BASE.'/dubs/job_1/subtitles', $http->calls[1]['url']);
    }

    #[Test]
    public function subtitles_of_an_unfinished_dub_throw_a_conflict(): void
    {
        $http = new MockHttp(fn () => MockHttp::error(409, 'DUB_NOT_READY'));
        try {
            $http->client()->dubs->subtitles('job_1');
            $this->fail('Expected an exception.');
        } catch (ConflictException $e) {
            $this->assertSame('DUB_NOT_READY', $e->errorCode);
        }
    }

    #[Test]
    public function files_and_quality_and_cancel_hit_their_paths(): void
    {
        $quality = ['dub_id' => 'job_1', 'checked' => true, 'listened' => true, 'lines' => 40, 'fixed' => 2, 'to_review' => 1, 'flagged' => [['line' => 3, 'start_ms' => 9000, 'checks' => ['fit'], 'fixed' => 'shortened']]];
        $http = new MockHttp(fn (array $call) => str_ends_with($call['url'], '/quality') ? MockHttp::json(200, $quality) : MockHttp::json(200, ['files' => [['kind' => 'dub', 'filename' => 'a.mp4', 'url' => 'https://f.test/a']], 'expires_at' => 'x']));
        $client = $http->client();
        $this->assertSame($quality, $client->dubs->quality('job_1'));
        $this->assertSame('dub', $client->dubs->files('job_1')['files'][0]['kind']);
        $this->assertSame(self::BASE.'/dubs/job_1/files', $http->calls[1]['url']);
    }

    #[Test]
    public function resending_a_delivery_posts_once_and_is_not_retried_after_a_network_failure(): void
    {
        $delivery = ['id' => 'whd_1', 'event_id' => 'evt_1', 'event_type' => 'dub.completed', 'status' => 'pending', 'attempts' => 0, 'created_at' => 'x'];
        $ok = new MockHttp(fn () => MockHttp::json(201, $delivery));
        $this->assertSame($delivery, $ok->client()->webhookEndpoints->resendDelivery('we_1', 'whd_1'));
        $this->assertSame('POST', $ok->calls[0]['method']);
        $this->assertSame(self::BASE.'/webhook_endpoints/we_1/deliveries/whd_1/resend', $ok->calls[0]['url']);

        $down = new MockHttp(fn () => self::down());
        try {
            $down->client()->webhookEndpoints->resendDelivery('we_1', 'whd_1');
            $this->fail('Expected an exception.');
        } catch (ConnectionException) {
            $this->assertCount(1, $down->calls);
        }
    }

    #[Test]
    public function webhook_endpoints_list_deliveries_and_send_test(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(200, ['data' => []]));
        $client = $http->client();
        $client->webhookEndpoints->list();
        $client->webhookEndpoints->deliveries('we_1', ['limit' => 5]);
        $this->assertSame(self::BASE.'/webhook_endpoints', $http->calls[0]['url']);
        $this->assertSame(self::BASE.'/webhook_endpoints/we_1/deliveries?limit=5', $http->calls[1]['url']);
        $http2 = new MockHttp(fn () => new Response(204));
        $http2->client()->webhookEndpoints->sendTest('we_1');
        $this->assertSame('POST', $http2->calls[0]['method']);
        $this->assertSame(self::BASE.'/webhook_endpoints/we_1/test', $http2->calls[0]['url']);
    }

    #[Test]
    public function dubs_create_passes_instructions_through(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(201, ['dubs' => []]));
        $http->client()->dubs->create(['upload_id' => 'upl_1', 'source_language' => 'en', 'target_languages' => ['fr'], 'instructions' => 'Keep the name Adaeze.']);
        $this->assertSame('Keep the name Adaeze.', $http->calls[0]['body']['instructions']);
    }

    #[Test]
    public function dub_requests_create_defaults_to_ask_and_is_not_retried_after_a_network_failure(): void
    {
        $run = ['id' => 'run_1', 'dub_id' => 'job_1', 'status' => 'done', 'request' => 'Is line 3 natural?', 'reply' => 'Yes.', 'created_at' => 'x'];
        $ok = new MockHttp(fn () => MockHttp::json(200, $run));
        $this->assertSame($run, $ok->client()->dubs->requests->create('job_1', ['text' => 'Is line 3 natural?']));
        $this->assertSame(self::BASE.'/dubs/job_1/requests', $ok->calls[0]['url']);
        $this->assertSame(['autonomy' => 'ask', 'text' => 'Is line 3 natural?'], $ok->calls[0]['body']);
        $this->assertArrayNotHasKey('idempotency-key', $ok->calls[0]['headers']);

        $decide = new MockHttp(fn () => MockHttp::json(200, $run));
        $decide->client()->dubs->requests->create('job_1', ['text' => 'Fix it.', 'autonomy' => 'decide']);
        $this->assertSame('decide', $decide->calls[0]['body']['autonomy']);

        $down = new MockHttp(fn () => self::down());
        try {
            $down->client()->dubs->requests->create('job_1', ['text' => 'x']);
            $this->fail('Expected an exception.');
        } catch (ConnectionException) {
            $this->assertCount(1, $down->calls);
        }
    }

    #[Test]
    public function a_request_that_is_not_released_yet_throws_a_403_with_not_available(): void
    {
        $http = new MockHttp(fn () => MockHttp::error(403, 'NOT_AVAILABLE'));
        try {
            $http->client()->dubs->requests->create('job_1', ['text' => 'x']);
            $this->fail('Expected an exception.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('NOT_AVAILABLE', $e->errorCode);
        }
    }

    #[Test]
    public function runs_retrieve_and_answer(): void
    {
        $run = ['id' => 'run_1', 'dub_id' => 'job_1', 'status' => 'needs_input', 'request' => 'Calmer.', 'reply' => 'I can soften line 3.', 'proposal' => ['kind' => 'edit_lines', 'summary' => 'Soften line 3', 'lines' => 1], 'created_at' => 'x'];
        $http = new MockHttp(fn (array $call) => $call['method'] === 'GET' ? MockHttp::json(200, $run) : MockHttp::json(200, ['status' => 'declined', 'proposal' => null] + $run));
        $client = $http->client();
        $this->assertSame('needs_input', $client->runs->retrieve('run_1')['status']);
        $this->assertSame('declined', $client->runs->answer('run_1', ['choice' => 'decline'])['status']);
        $this->assertSame(['choice' => 'decline'], $http->calls[1]['body']);
        $this->assertSame(self::BASE.'/runs/run_1/answer', $http->calls[1]['url']);
    }

    #[Test]
    public function dub_lines_list_and_edit(): void
    {
        $http = new MockHttp(fn (array $call) => $call['method'] === 'GET'
            ? MockHttp::json(200, ['dub_id' => 'job_1', 'lines' => [['line' => 3, 'start_ms' => 0, 'end_ms' => 1, 'original_text' => 'Hi', 'text' => 'Salut']]])
            : MockHttp::json(200, ['dub_id' => 'job_1', 'lines' => [3], 'status' => 'revoicing']));
        $client = $http->client();
        $this->assertSame(3, $client->dubs->lines->list('job_1')['lines'][0]['line']);
        $edited = $client->dubs->lines->edit('job_1', ['lines' => [['line' => 3, 'text' => 'Bienvenue.', 'delivery_style' => 'warm']]]);
        $this->assertSame('revoicing', $edited['status']);
        $this->assertSame(self::BASE.'/dubs/job_1/lines/edit', $http->calls[1]['url']);
        $this->assertSame('warm', $http->calls[1]['body']['lines'][0]['delivery_style']);
    }

    #[Test]
    public function default_headers_are_sent_and_can_be_overridden_per_request(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(200, ['target_languages' => []]));
        $client = $http->client(['default_headers' => ['X-App' => 'one', 'X-Other' => 'x']]);
        $client->languages->list(['headers' => ['X-App' => 'two']]);
        $this->assertSame('two', $http->calls[0]['headers']['x-app']);
        $this->assertSame('x', $http->calls[0]['headers']['x-other']);
    }

    #[Test]
    public function a_per_request_max_retries_wins(): void
    {
        $http = new MockHttp(fn () => MockHttp::error(500, 'INTERNAL'));
        try {
            $http->client()->languages->list(['max_retries' => 0]);
            $this->fail('Expected an exception.');
        } catch (ServerException) {
            $this->assertCount(1, $http->calls);
        }
    }
}
