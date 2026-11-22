<?php

declare(strict_types=1);

namespace Reedapt\Exception;

/** 429: too many requests, or a limit such as dubs running at once. */
class RateLimitException extends ApiException
{
    /** Seconds to wait, from the `Retry-After` header, when the API sent one. */
    public function retryAfter(): ?float
    {
        $value = $this->header('retry-after');
        if ($value === null || !is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }
}
