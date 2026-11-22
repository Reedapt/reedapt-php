<?php

declare(strict_types=1);

namespace Reedapt\Resources;

use Psr\Http\Client\ClientExceptionInterface;
use Reedapt\Client;
use Reedapt\Exception\ConflictException;
use Reedapt\Exception\UploadException;
use Reedapt\Exception\WaitException;
use Reedapt\Internal\RangeStream;

/**
 * @phpstan-import-type Upload from \Reedapt\Types
 * @phpstan-import-type UploadedPart from \Reedapt\Types
 * @phpstan-import-type PartLink from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class Uploads extends AbstractResource
{
    private const SIGN_BATCH = 100;

    private const PART_ATTEMPTS = 4;

    /**
     * Starts an upload. Most code wants `uploadFile()` or `fromUrl()` instead.
     * Sends an `Idempotency-Key`, yours or a random one.
     *
     * @param array{filename?: string, size?: int, source_url?: string} $params
     * @param RequestOptions $options
     * @return Upload
     */
    public function create(array $params, array $options = []): array
    {
        /** @var Upload */
        return $this->client->request('POST', '/uploads', ['body' => $params, 'retry' => 'safe', 'idempotent' => true], $options);
    }

    /**
     * Signs links for up to 100 more parts.
     *
     * @param list<int> $partNumbers
     * @param RequestOptions $options
     * @return array{parts: list<PartLink>}
     */
    public function signParts(string $uploadId, array $partNumbers, array $options = []): array
    {
        /** @var array{parts: list<PartLink>} */
        return $this->client->request('POST', '/uploads/'.self::id($uploadId).'/parts', ['body' => ['part_numbers' => $partNumbers], 'retry' => 'safe'], $options);
    }

    /**
     * @param list<UploadedPart> $parts
     * @param RequestOptions $options
     * @return Upload
     */
    public function complete(string $uploadId, array $parts, array $options = []): array
    {
        /** @var Upload */
        return $this->client->request('POST', '/uploads/'.self::id($uploadId).'/complete', ['body' => ['parts' => $parts], 'retry' => 'safe'], $options);
    }

    /**
     * @param RequestOptions $options
     * @return Upload
     */
    public function retrieve(string $uploadId, array $options = []): array
    {
        /** @var Upload */
        return $this->client->request('GET', '/uploads/'.self::id($uploadId), ['retry' => 'safe'], $options);
    }

    /**
     * Sends a file to storage in parts and completes the upload. Each part is
     * streamed from the file as it is sent, so memory use stays flat however
     * big the file is. Expired links are signed again and failed parts retried.
     *
     * `$input` is a path, or an open, seekable stream (then `filename` is
     * needed unless the stream is a plain file). Options: `filename`,
     * `on_progress` (`callable(int $uploadedBytes, int $totalBytes): void`) and
     * `idempotency_key`. Parts go one after another: PHP requests are
     * synchronous, so the upload is as fast as one connection allows.
     *
     * @param string|resource $input
     * @param array{filename?: string|null, on_progress?: (callable(int, int): void)|null, idempotency_key?: string|null} $options
     * @return Upload
     */
    public function uploadFile(mixed $input, array $options = []): array
    {
        [$handle, $filename, $size, $owned] = $this->open($input, $options['filename'] ?? null);
        try {
            $key = $options['idempotency_key'] ?? null;
            $upload = $this->create(['filename' => $filename, 'size' => $size], $key !== null && $key !== '' ? ['idempotency_key' => $key] : []);
            /** @var array<int, string> $links */
            $links = [];
            foreach ($upload['parts'] ?? [] as $part) {
                $links[$part['part_number']] = $part['url'];
            }
            $linkFor = function (int $n, bool $refresh) use (&$links, $upload): string {
                if ($refresh) {
                    unset($links[$n]);
                }
                if (!isset($links[$n])) {
                    if ($refresh) {
                        $wanted = [$n];
                    } else {
                        $first = intdiv($n - 1, self::SIGN_BATCH) * self::SIGN_BATCH + 1;
                        $last = min($first + self::SIGN_BATCH - 1, $upload['part_count']);
                        $wanted = array_values(array_filter(range($first, $last), static fn (int $p): bool => !isset($links[$p])));
                    }
                    foreach ($this->signParts($upload['id'], $wanted)['parts'] as $part) {
                        $links[$part['part_number']] = $part['url'];
                    }
                }
                if (!isset($links[$n])) {
                    throw new UploadException("No link was signed for part {$n} of {$upload['id']}.");
                }

                return $links[$n];
            };

            $parts = [];
            $uploaded = 0;
            for ($n = 1; $n <= $upload['part_count']; $n++) {
                $start = ($n - 1) * $upload['part_size'];
                $length = min($upload['part_size'], $size - $start);
                $parts[] = ['part_number' => $n, 'etag' => $this->putPart($upload['id'], $n, $handle, $start, $length, $linkFor)];
                $uploaded += $length;
                if (isset($options['on_progress'])) {
                    ($options['on_progress'])($uploaded, $size);
                }
            }

            try {
                return $this->complete($upload['id'], $parts);
            } catch (ConflictException $e) {
                // A retried complete that had already gone through.
                if ($e->errorCode === 'UPLOAD_COMPLETE') {
                    return $this->retrieve($upload['id']);
                }
                throw $e;
            }
        } finally {
            if ($owned) {
                fclose($handle);
            }
        }
    }

    /**
     * Has Reedapt download a public link. Waits until it's ready unless `wait`
     * is false. Options: `filename`, `wait`, `poll_interval` (seconds, default
     * 10), `timeout` (seconds, default 3 hours), `idempotency_key`.
     *
     * @param array{filename?: string|null, wait?: bool, poll_interval?: int|float, timeout?: int|float, idempotency_key?: string|null} $options
     * @return Upload
     */
    public function fromUrl(string $sourceUrl, array $options = []): array
    {
        $params = ['source_url' => $sourceUrl];
        if (isset($options['filename']) && $options['filename'] !== '') {
            $params['filename'] = $options['filename'];
        }
        $key = $options['idempotency_key'] ?? null;
        $upload = $this->create($params, $key !== null && $key !== '' ? ['idempotency_key' => $key] : []);
        if (($options['wait'] ?? true) === false) {
            return $upload;
        }

        return $this->waitUntilReady($upload['id'], $options);
    }

    /**
     * Polls an upload until it can be dubbed. Throws `WaitException` if it failed.
     *
     * @param array{poll_interval?: int|float, timeout?: int|float} $options
     * @return Upload
     */
    public function waitUntilReady(string $uploadId, array $options = []): array
    {
        $pollInterval = (float) ($options['poll_interval'] ?? 10);
        $deadline = microtime(true) + (float) ($options['timeout'] ?? 3 * 3600);
        for (;;) {
            $upload = $this->retrieve($uploadId);
            if ($upload['status'] === 'uploaded') {
                return $upload;
            }
            if ($upload['status'] === 'failed') {
                throw new WaitException("Upload {$uploadId} failed: ".($upload['error'] ?? 'no reason given').'.');
            }
            if ($upload['status'] === 'used') {
                throw new WaitException("Upload {$uploadId} was already dubbed. Upload the file again.");
            }
            if (microtime(true) + $pollInterval > $deadline) {
                throw new WaitException("Upload {$uploadId} was still {$upload['status']} when the wait ran out.");
            }
            $this->client->sleep($pollInterval);
        }
    }

    /**
     * @param resource $handle
     * @param callable(int, bool): string $linkFor
     */
    private function putPart(string $uploadId, int $n, $handle, int $start, int $length, callable $linkFor): string
    {
        $refresh = false;
        $problem = '';
        for ($attempt = 0; $attempt < self::PART_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                $this->client->sleep(Client::backoff($attempt));
            }
            $url = $linkFor($n, $refresh);
            $refresh = false;
            // No API key here: the signed link carries its own permission.
            $request = $this->client->requestFactory()->createRequest('PUT', $url)
                ->withHeader('Content-Length', (string) $length)
                ->withBody(new RangeStream($handle, $start, $length));
            try {
                $response = $this->client->uploadHttpClient()->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $problem = $e->getMessage();

                continue;
            }
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $etag = $response->getHeaderLine('ETag');
                if ($etag === '') {
                    throw new UploadException("Storage accepted part {$n} but sent no ETag.");
                }

                return $etag;
            }
            $problem = "storage answered {$status}";
            // An expired or refused link: sign this part again.
            if ($status === 403 || $status === 400) {
                $refresh = true;
            } elseif ($status < 500 && $status !== 408 && $status !== 429) {
                break;
            }
        }

        throw new UploadException("Part {$n} of upload {$uploadId} failed: {$problem}.");
    }

    /**
     * @return array{resource, string, int, bool} handle, filename, size, whether we opened it
     */
    private function open(mixed $input, ?string $filename): array
    {
        if (is_string($input)) {
            if (!is_file($input) || !is_readable($input)) {
                throw new UploadException("{$input} is not a readable file.");
            }
            $handle = fopen($input, 'rb');
            if ($handle === false) {
                throw new UploadException("{$input} could not be opened.");
            }
            $size = filesize($input);

            return [$handle, $filename ?? basename($input), $size === false ? 0 : $size, true];
        }
        if (!is_resource($input)) {
            throw new UploadException('Pass a file path or an open stream.');
        }
        $meta = stream_get_meta_data($input);
        if (!$meta['seekable']) {
            throw new UploadException('The stream must be seekable. Pass a file path, or copy it to php://temp first.');
        }
        $stat = fstat($input);
        if ($stat === false) {
            throw new UploadException('The size of the stream could not be read.');
        }
        if ($filename === null) {
            $uri = $meta['uri'] ?? '';
            if ($uri === '' || str_starts_with($uri, 'php://')) {
                throw new UploadException('Pass a filename, with its extension, when uploading a stream.');
            }
            $filename = basename($uri);
        }

        return [$input, $filename, $stat['size'], false];
    }
}
