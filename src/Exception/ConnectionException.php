<?php

declare(strict_types=1);

namespace Reedapt\Exception;

/** The request never got an answer: the network failed or it timed out. */
class ConnectionException extends ReedaptException
{
}
