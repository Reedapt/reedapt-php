<?php

declare(strict_types=1);

namespace Reedapt\Resources;

/**
 * @phpstan-import-type Languages from \Reedapt\Types as LanguageList
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class Languages extends AbstractResource
{
    /**
     * @param RequestOptions $options
     * @return LanguageList
     */
    public function list(array $options = []): array
    {
        /** @var LanguageList */
        return $this->client->request('GET', '/languages', ['retry' => 'safe'], $options);
    }
}
