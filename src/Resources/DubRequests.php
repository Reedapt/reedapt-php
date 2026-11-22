<?php

declare(strict_types=1);

namespace Reedapt\Resources;

/**
 * Asking Reedapt for changes to a finished dub. Early access.
 *
 * @phpstan-import-type Run from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class DubRequests extends AbstractResource
{
    /**
     * Hands Reedapt a request in plain words, such as "Make line 3 sound calmer".
     * With `autonomy` `ask` (the default) nothing changes until you answer the
     * run with `runs->answer()`. With `decide` Reedapt applies what it judges right.
     *
     * @param array{text: string, autonomy?: 'ask'|'decide'} $params
     * @param RequestOptions $options
     * @return Run
     */
    public function create(string $dubId, array $params, array $options = []): array
    {
        // Not retried after a network failure: it could start the request twice.
        /** @var Run */
        return $this->client->request(
            'POST',
            '/dubs/'.self::id($dubId).'/requests',
            ['body' => array_merge(['autonomy' => 'ask'], $params), 'retry' => 'unsafe'],
            $options,
        );
    }
}
