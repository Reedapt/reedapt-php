<?php

declare(strict_types=1);

namespace Reedapt\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reedapt\Exception\UploadException;
use Reedapt\Exception\WaitException;

final class UploadsTest extends TestCase
{
    private const STORAGE = 'https://storage.test/';

    private const API = 'https://api.reedapt.com/v1';

    /**
     * A fake API plus storage for one upload of `$size` bytes in parts of `$partSize`.
     *
     * @param (callable(array<string, mixed>): ?Response)|null $storage
     */
    private static function server(int $size, int $partSize, int $firstLinks = 100, ?callable $storage = null, ?\Closure $log = null): MockHttp
    {
        $partCount = (int) ceil($size / $partSize);
        $firstLinks = min($firstLinks, $partCount);
        $link = static fn (int $n): array => ['part_number' => $n, 'url' => self::STORAGE.'part/'.$n];
        $upload = ['id' => 'job_up', 'filename' => 'talk.mp4', 'size' => $size, 'part_size' => $partSize, 'part_count' => $partCount];

        return new MockHttp(function (array $call) use ($upload, $firstLinks, $link, $storage) {
            if (str_starts_with($call['url'], self::STORAGE)) {
                $custom = $storage ? $storage($call) : null;

                return $custom ?? new Response(200, ['ETag' => '"etag-'.basename($call['url']).'"']);
            }
            $path = substr($call['url'], strlen(self::API));

            return match ($path) {
                '/uploads' => MockHttp::json(201, $upload + ['status' => 'uploading', 'parts' => array_map($link, $firstLinks > 0 ? range(1, $firstLinks) : [])]),
                '/uploads/job_up/parts' => MockHttp::json(200, ['parts' => array_map($link, $call['body']['part_numbers'])]),
                '/uploads/job_up/complete', '/uploads/job_up' => MockHttp::json(200, $upload + ['status' => 'uploaded']),
                default => MockHttp::error(404, 'NOT_FOUND'),
            };
        });
    }

    private function tempFile(string $contents, string $name = 'sermon.mp3'): string
    {
        $dir = sys_get_temp_dir().'/reedapt-php-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $path = "{$dir}/{$name}";
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return list<array<string, mixed>> */
    private static function puts(MockHttp $http): array
    {
        return array_values(array_filter($http->calls, static fn (array $c): bool => $c['method'] === 'PUT'));
    }

    #[Test]
    public function uploads_bytes_in_parts_and_completes_with_every_etag_in_order(): void
    {
        $http = self::server(10, 4);
        $progress = [];
        $path = $this->tempFile('0123456789', 'talk.mp4');
        $upload = $http->client()->uploads->uploadFile($path, ['on_progress' => function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        }]);
        $this->assertSame('uploaded', $upload['status']);
        $this->assertSame(['filename' => 'talk.mp4', 'size' => 10], $http->calls[0]['body']);
        $this->assertNotEmpty($http->calls[0]['headers']['idempotency-key']);
        $puts = self::puts($http);
        $this->assertSame(['0123', '4567', '89'], array_map(static fn (array $c): string => $c['raw'], $puts));
        $this->assertSame(['4', '4', '2'], array_map(static fn (array $c): string => $c['headers']['content-length'], $puts));
        foreach ($puts as $put) {
            $this->assertArrayNotHasKey('authorization', $put['headers'], 'no API key on storage links');
        }
        $complete = array_values(array_filter($http->calls, static fn (array $c): bool => str_ends_with($c['url'], '/complete')))[0];
        $this->assertSame(['parts' => [
            ['part_number' => 1, 'etag' => '"etag-1"'],
            ['part_number' => 2, 'etag' => '"etag-2"'],
            ['part_number' => 3, 'etag' => '"etag-3"'],
        ]], $complete['body']);
        $this->assertSame([[4, 10], [8, 10], [10, 10]], $progress);
    }

    #[Test]
    public function a_stream_is_uploaded_from_its_start_with_the_filename_given(): void
    {
        $http = self::server(9, 16);
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, 'sermon-ok');
        $upload = $http->client()->uploads->uploadFile($handle, ['filename' => 'sermon.mp3']);
        $this->assertSame('uploaded', $upload['status']);
        $this->assertSame(['filename' => 'sermon.mp3', 'size' => 9], $http->calls[0]['body']);
        $this->assertSame('sermon-ok', self::puts($http)[0]['raw']);
        $this->assertIsResource($handle, 'a stream the caller opened is left open');
        fclose($handle);
    }

    #[Test]
    public function a_stream_needs_a_filename_unless_it_is_a_plain_file(): void
    {
        $http = self::server(1, 1);
        $handle = fopen('php://memory', 'r+b');
        try {
            $http->client()->uploads->uploadFile($handle);
            $this->fail('Expected an exception.');
        } catch (UploadException) {
            $this->assertSame([], $http->calls);
        }
        $path = $this->tempFile('x', 'clip.mp4');
        $file = fopen($path, 'rb');
        $http->client()->uploads->uploadFile($file);
        $this->assertSame('clip.mp4', $http->calls[0]['body']['filename']);
    }

    #[Test]
    public function a_missing_file_is_refused_before_any_request(): void
    {
        $http = self::server(1, 1);
        $this->expectException(UploadException::class);
        try {
            $http->client()->uploads->uploadFile('/nope/missing.mp4');
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    #[Test]
    public function signs_the_parts_the_first_answer_did_not_include_a_batch_at_a_time(): void
    {
        $http = self::server(250, 1, 100);
        $http->client()->uploads->uploadFile($this->tempFile(str_repeat('a', 250), 'a.mp4'));
        $signs = array_values(array_map(
            static fn (array $c): array => $c['body']['part_numbers'],
            array_filter($http->calls, static fn (array $c): bool => str_ends_with($c['url'], '/parts')),
        ));
        $this->assertCount(2, $signs);
        $this->assertSame([101, 200, 201, 250], [$signs[0][0], $signs[0][99], $signs[1][0], $signs[1][49]]);
        $this->assertCount(250, self::puts($http));
    }

    #[Test]
    public function an_expired_link_is_signed_again_and_the_part_resent(): void
    {
        $refused = false;
        $http = self::server(8, 4, 100, function (array $call) use (&$refused): ?Response {
            if (str_ends_with($call['url'], '/2') && !$refused) {
                $refused = true;

                return new Response(403, [], '<Error>Request has expired</Error>');
            }

            return null;
        });
        $upload = $http->client()->uploads->uploadFile($this->tempFile('abcdefgh', 'a.mp4'));
        $this->assertSame('uploaded', $upload['status']);
        $resign = array_values(array_filter($http->calls, static fn (array $c): bool => str_ends_with($c['url'], '/parts')))[0];
        $this->assertSame(['part_numbers' => [2]], $resign['body']);
        $part2 = array_values(array_filter(self::puts($http), static fn (array $c): bool => str_ends_with($c['url'], '/2')));
        $this->assertSame(['efgh', 'efgh'], array_map(static fn (array $c): string => $c['raw'], $part2), 'the part is resent whole');
    }

    #[Test]
    public function a_part_that_keeps_failing_stops_the_upload(): void
    {
        $http = self::server(8, 4, 100, fn (array $call): ?Response => str_ends_with($call['url'], '/1') ? new Response(500, [], 'no') : null);
        try {
            $http->client()->uploads->uploadFile($this->tempFile('abcdefgh', 'a.mp4'));
            $this->fail('Expected an exception.');
        } catch (UploadException $e) {
            $this->assertStringContainsString('Part 1', $e->getMessage());
        }
        $this->assertCount(4, self::puts($http));
        $this->assertCount(0, array_filter($http->calls, static fn (array $c): bool => str_ends_with($c['url'], '/complete')));
    }

    #[Test]
    public function a_storage_network_failure_is_retried(): void
    {
        $failed = false;
        $http = self::server(4, 4);
        $inner = (new \ReflectionProperty($http, 'handler'))->getValue($http);
        $flaky = new MockHttp(function (array $call) use (&$failed, $inner) {
            if (str_starts_with($call['url'], self::STORAGE) && !$failed) {
                $failed = true;

                return new class ('reset') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                    public function getRequest(): \Psr\Http\Message\RequestInterface
                    {
                        return new \GuzzleHttp\Psr7\Request('PUT', 'https://storage.test/');
                    }
                };
            }

            return $inner($call);
        });
        $upload = $flaky->client()->uploads->uploadFile($this->tempFile('abcd', 'a.mp4'));
        $this->assertSame('uploaded', $upload['status']);
        $this->assertCount(2, self::puts($flaky));
    }

    #[Test]
    public function a_part_without_an_etag_is_an_error(): void
    {
        $http = self::server(4, 4, 100, fn (): Response => new Response(200));
        $this->expectException(UploadException::class);
        $http->client()->uploads->uploadFile($this->tempFile('abcd', 'a.mp4'));
    }

    #[Test]
    public function a_complete_that_already_went_through_returns_the_upload(): void
    {
        $upload = ['id' => 'job_up', 'filename' => 'a.mp4', 'size' => 4, 'part_size' => 4, 'part_count' => 1];
        $http = new MockHttp(function (array $call) use ($upload) {
            if (str_starts_with($call['url'], self::STORAGE)) {
                return new Response(200, ['ETag' => '"e"']);
            }

            return match (true) {
                str_ends_with($call['url'], '/complete') => MockHttp::error(409, 'UPLOAD_COMPLETE'),
                $call['method'] === 'POST' => MockHttp::json(201, $upload + ['status' => 'uploading', 'parts' => [['part_number' => 1, 'url' => self::STORAGE.'part/1']]]),
                default => MockHttp::json(200, $upload + ['status' => 'uploaded']),
            };
        });
        $result = $http->client()->uploads->uploadFile($this->tempFile('abcd', 'a.mp4'));
        $this->assertSame('uploaded', $result['status']);
    }

    #[Test]
    public function from_url_waits_until_the_download_is_done(): void
    {
        $statuses = ['fetching', 'uploaded'];
        $http = new MockHttp(function (array $call) use (&$statuses) {
            return $call['method'] === 'POST'
                ? MockHttp::json(202, ['id' => 'job_u', 'status' => 'fetching', 'filename' => '', 'size' => 0, 'part_size' => 0, 'part_count' => 0])
                : MockHttp::json(200, ['id' => 'job_u', 'status' => array_shift($statuses), 'filename' => 'a.mp4', 'size' => 5, 'part_size' => 0, 'part_count' => 0]);
        });
        $upload = $http->client()->uploads->fromUrl('https://example.com/a.mp4', ['poll_interval' => 1]);
        $this->assertSame('uploaded', $upload['status']);
        $this->assertSame(['source_url' => 'https://example.com/a.mp4'], $http->calls[0]['body']);
        $this->assertCount(3, $http->calls);
    }

    #[Test]
    public function from_url_can_skip_waiting(): void
    {
        $http = new MockHttp(fn () => MockHttp::json(202, ['id' => 'job_u', 'status' => 'fetching', 'filename' => '', 'size' => 0, 'part_size' => 0, 'part_count' => 0]));
        $upload = $http->client()->uploads->fromUrl('https://example.com/a.mp4', ['wait' => false, 'filename' => 'a.mp4']);
        $this->assertSame('fetching', $upload['status']);
        $this->assertCount(1, $http->calls);
        $this->assertSame('a.mp4', $http->calls[0]['body']['filename']);
    }

    #[Test]
    public function from_url_throws_when_the_download_failed(): void
    {
        $http = new MockHttp(fn (array $call) => MockHttp::json($call['method'] === 'POST' ? 202 : 200, [
            'id' => 'job_u', 'status' => $call['method'] === 'POST' ? 'fetching' : 'failed', 'error' => 'SOURCE_URL_TOO_SLOW',
            'filename' => '', 'size' => 0, 'part_size' => 0, 'part_count' => 0,
        ]));
        $this->expectException(WaitException::class);
        $this->expectExceptionMessageMatches('/SOURCE_URL_TOO_SLOW/');
        $http->client()->uploads->fromUrl('https://example.com/a.mp4', ['poll_interval' => 1]);
    }

    #[Test]
    public function dub_uploads_and_starts_the_dubs_in_one_call(): void
    {
        $server = self::server(4, 4);
        $inner = (new \ReflectionProperty($server, 'handler'))->getValue($server);
        $http = new MockHttp(fn (array $call) => str_ends_with($call['url'], '/v1/dubs')
            ? MockHttp::json(201, ['dubs' => [['id' => 'job_d']], 'refused' => []])
            : $inner($call));
        $created = $http->client()->dub($this->tempFile('abcd', 'talk.mp4'), [
            'source_language' => 'en',
            'target_languages' => ['fr', 'es'],
            'idempotency_key' => 'order-7',
        ]);
        $this->assertSame('job_d', $created['dubs'][0]['id']);
        $dubs = array_values(array_filter($http->calls, static fn (array $c): bool => str_ends_with($c['url'], '/v1/dubs')))[0];
        $this->assertSame(['upload_id' => 'job_up', 'source_language' => 'en', 'target_languages' => ['fr', 'es']], $dubs['body']);
        $this->assertSame('order-7:dubs', $dubs['headers']['idempotency-key']);
        $this->assertSame('order-7:upload', $http->calls[0]['headers']['idempotency-key']);
    }

    #[Test]
    public function dub_with_a_link_has_reedapt_fetch_it(): void
    {
        $http = new MockHttp(fn (array $call) => match (true) {
            str_ends_with($call['url'], '/v1/dubs') => MockHttp::json(201, ['dubs' => [['id' => 'job_d']]]),
            $call['method'] === 'POST' => MockHttp::json(202, ['id' => 'job_u', 'status' => 'fetching', 'filename' => '', 'size' => 0, 'part_size' => 0, 'part_count' => 0]),
            default => MockHttp::json(200, ['id' => 'job_u', 'status' => 'uploaded', 'filename' => 'a.mp4', 'size' => 5, 'part_size' => 0, 'part_count' => 0]),
        });
        $http->client()->dub('https://example.com/a.mp4', ['source_language' => 'en', 'target_languages' => ['fr']]);
        $this->assertSame(['source_url' => 'https://example.com/a.mp4'], $http->calls[0]['body']);
        $this->assertSame('job_u', $http->calls[2]['body']['upload_id']);
    }
}
