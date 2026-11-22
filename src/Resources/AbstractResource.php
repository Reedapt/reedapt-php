<?php

declare(strict_types=1);

namespace Reedapt\Resources;

use Reedapt\Client;

/** @internal */
abstract class AbstractResource
{
    public function __construct(protected readonly Client $client)
    {
    }

    protected static function id(string $id): string
    {
        return rawurlencode($id);
    }
}
