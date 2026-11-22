<?php

declare(strict_types=1);

namespace Reedapt\Exception;

/** The request took longer than the HTTP client's timeout. */
class TimeoutException extends ConnectionException
{
}
