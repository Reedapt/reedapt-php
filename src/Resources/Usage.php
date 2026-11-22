<?php

declare(strict_types=1);

namespace Reedapt\Resources;

/**
 * @phpstan-import-type Usage from \Reedapt\Types as UsageInfo
 * @phpstan-import-type UsageReport from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class Usage extends AbstractResource
{
    /**
     * Plan, key mode and minutes left.
     *
     * @param RequestOptions $options
     * @return UsageInfo
     */
    public function retrieve(array $options = []): array
    {
        /** @var UsageInfo */
        return $this->client->request('GET', '/usage', ['retry' => 'safe'], $options);
    }

    /**
     * A month (`YYYY-MM`, UTC; default this month) of minutes and dubs, per key and per language.
     *
     * @param array{month?: string} $params
     * @param RequestOptions $options
     * @return UsageReport
     */
    public function report(array $params = [], array $options = []): array
    {
        /** @var UsageReport */
        return $this->client->request('GET', '/usage/report', ['query' => $params, 'retry' => 'safe'], $options);
    }
}
