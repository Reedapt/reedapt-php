<?php

declare(strict_types=1);

namespace Reedapt\Resources;

use Reedapt\Client;
use Reedapt\Exception\WaitException;

/**
 * @phpstan-import-type CreateDubParams from \Reedapt\Types
 * @phpstan-import-type Dub from \Reedapt\Types
 * @phpstan-import-type DubsCreated from \Reedapt\Types
 * @phpstan-import-type DubList from \Reedapt\Types
 * @phpstan-import-type DubFiles from \Reedapt\Types
 * @phpstan-import-type Transcript from \Reedapt\Types
 * @phpstan-import-type Quality from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class Dubs extends AbstractResource
{
    /** Ask Reedapt for changes to a finished dub. Early access. */
    public readonly DubRequests $requests;

    /** Read and edit a dub's lines. Early access. */
    public readonly DubLines $lines;

    public function __construct(Client $client)
    {
        parent::__construct($client);
        $this->requests = new DubRequests($client);
        $this->lines = new DubLines($client);
    }

    /**
     * Starts one dub per target language. Languages that can't start come back in `refused`.
     * Sends an `Idempotency-Key` (yours, or a random one), so a retry never makes a second dub.
     *
     * @param CreateDubParams $params
     * @param RequestOptions $options
     * @return DubsCreated
     */
    public function create(array $params, array $options = []): array
    {
        /** @var DubsCreated */
        return $this->client->request('POST', '/dubs', ['body' => $params, 'retry' => 'safe', 'idempotent' => true], $options);
    }

    /**
     * @param RequestOptions $options
     * @return Dub
     */
    public function retrieve(string $dubId, array $options = []): array
    {
        /** @var Dub */
        return $this->client->request('GET', '/dubs/'.self::id($dubId), ['retry' => 'safe'], $options);
    }

    /**
     * One page of the account's dubs, newest first.
     *
     * @param array{limit?: int, offset?: int} $params
     * @param RequestOptions $options
     * @return DubList
     */
    public function list(array $params = [], array $options = []): array
    {
        /** @var DubList */
        return $this->client->request('GET', '/dubs', ['query' => $params, 'retry' => 'safe'], $options);
    }

    /**
     * Every dub, newest first, fetching pages as you go:
     * `foreach ($client->dubs->listAll() as $dub)`.
     *
     * @param array{limit?: int} $params
     * @param RequestOptions $options
     * @return \Generator<int, Dub>
     */
    public function listAll(array $params = [], array $options = []): \Generator
    {
        $limit = $params['limit'] ?? 100;
        $seen = [];
        for ($offset = 0;; $offset += $limit) {
            $page = $this->list(['limit' => $limit, 'offset' => $offset], $options);
            foreach ($page['data'] as $dub) {
                // Dubs started while paging shift later pages by one; skip the repeats.
                if (isset($seen[$dub['id']])) {
                    continue;
                }
                $seen[$dub['id']] = true;
                yield $dub;
            }
            if (!($page['has_more'] ?? false) || $page['data'] === []) {
                return;
            }
        }
    }

    /**
     * Stops an unfinished dub and deletes it. Its minutes are returned.
     *
     * @param RequestOptions $options
     */
    public function cancel(string $dubId, array $options = []): void
    {
        $this->client->request('POST', '/dubs/'.self::id($dubId).'/cancel', ['retry' => 'safe'], $options);
    }

    /**
     * Download links for a completed dub's files. Each link lasts an hour.
     *
     * @param RequestOptions $options
     * @return DubFiles
     */
    public function files(string $dubId, array $options = []): array
    {
        /** @var DubFiles */
        return $this->client->request('GET', '/dubs/'.self::id($dubId).'/files', ['retry' => 'safe'], $options);
    }

    /**
     * Every line of a completed dub with its timing, speaker, original words and translation.
     *
     * @param RequestOptions $options
     * @return Transcript
     */
    public function transcript(string $dubId, array $options = []): array
    {
        /** @var Transcript */
        return $this->client->request('GET', '/dubs/'.self::id($dubId).'/transcript', ['retry' => 'safe'], $options);
    }

    /**
     * Reedapt's check of a finished dub: what it listened to, what it fixed and
     * the lines left for you to review. `checked` is false until the check has run;
     * the `dub.checked` webhook announces it.
     *
     * @param RequestOptions $options
     * @return Quality
     */
    public function quality(string $dubId, array $options = []): array
    {
        /** @var Quality */
        return $this->client->request('GET', '/dubs/'.self::id($dubId).'/quality', ['retry' => 'safe'], $options);
    }

    /**
     * A completed dub's subtitles as SRT or WebVTT text.
     *
     * @param array{format?: 'srt'|'vtt', language?: 'target'|'source'} $params
     * @param RequestOptions $options
     */
    public function subtitles(string $dubId, array $params = [], array $options = []): string
    {
        $text = $this->client->request('GET', '/dubs/'.self::id($dubId).'/subtitles', ['query' => $params, 'retry' => 'safe', 'text' => true], $options);

        return is_string($text) ? $text : '';
    }

    /**
     * Polls a dub until it is `completed` or `failed` and returns it. Check
     * `status` on the result. Webhooks are better for anything long-running.
     *
     * Options: `poll_interval` (seconds, default 30), `timeout` (seconds,
     * default 12 hours) and `on_update` (`callable(array $dub): void`).
     *
     * @param array{poll_interval?: int|float, timeout?: int|float, on_update?: callable(Dub): void} $options
     * @return Dub
     */
    public function waitUntilDone(string $dubId, array $options = []): array
    {
        $pollInterval = (float) ($options['poll_interval'] ?? 30);
        $deadline = microtime(true) + (float) ($options['timeout'] ?? 12 * 3600);
        for (;;) {
            $dub = $this->retrieve($dubId);
            if (isset($options['on_update'])) {
                ($options['on_update'])($dub);
            }
            if ($dub['status'] === 'completed' || $dub['status'] === 'failed') {
                return $dub;
            }
            if (microtime(true) + $pollInterval > $deadline) {
                throw new WaitException("Dub {$dubId} was still {$dub['status']} when the wait ran out.");
            }
            $this->client->sleep($pollInterval);
        }
    }
}
