<?php

declare(strict_types=1);

namespace Reedapt\Resources;

/**
 * Requests for changes to dubs. Early access.
 *
 * @phpstan-import-type Run from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class Runs extends AbstractResource
{
    /**
     * @param RequestOptions $options
     * @return Run
     */
    public function retrieve(string $runId, array $options = []): array
    {
        /** @var Run */
        return $this->client->request('GET', '/runs/'.self::id($runId), ['retry' => 'safe'], $options);
    }

    /**
     * Answers a run that is `needs_input`: `apply` the proposal or `decline` it.
     *
     * @param array{choice: 'apply'|'decline'} $params
     * @param RequestOptions $options
     * @return Run
     */
    public function answer(string $runId, array $params, array $options = []): array
    {
        /** @var Run */
        return $this->client->request('POST', '/runs/'.self::id($runId).'/answer', ['body' => $params, 'retry' => 'unsafe'], $options);
    }
}
