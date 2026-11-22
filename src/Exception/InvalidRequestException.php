<?php

declare(strict_types=1);

namespace Reedapt\Exception;

/** 400, 411, 413 or 422: the request can't be done as asked. Fix it before sending it again. `$fields` lists invalid fields. */
class InvalidRequestException extends ApiException
{
}
