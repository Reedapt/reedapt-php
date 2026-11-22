<?php

declare(strict_types=1);

namespace Reedapt\Resources;

/**
 * Reading and editing a finished dub's lines. Early access.
 *
 * @phpstan-import-type DubLineList from \Reedapt\Types
 * @phpstan-import-type LineEdit from \Reedapt\Types
 * @phpstan-import-type LinesEdited from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class DubLines extends AbstractResource
{
    /**
     * Every line with its timing, speaker, original and dubbed words, and delivery notes.
     *
     * @param RequestOptions $options
     * @return DubLineList
     */
    public function list(string $dubId, array $options = []): array
    {
        /** @var DubLineList */
        return $this->client->request('GET', '/dubs/'.self::id($dubId).'/lines', ['retry' => 'safe'], $options);
    }

    /**
     * Changes up to 100 lines. They are voiced again in the background; the dub's files update when that finishes.
     *
     * @param array{lines: list<LineEdit>} $params
     * @param RequestOptions $options
     * @return LinesEdited
     */
    public function edit(string $dubId, array $params, array $options = []): array
    {
        /** @var LinesEdited */
        return $this->client->request('POST', '/dubs/'.self::id($dubId).'/lines/edit', ['body' => $params, 'retry' => 'unsafe'], $options);
    }
}
