<?php

declare(strict_types=1);

namespace Reedapt\Exception;

/** A webhook's signature didn't match, or its timestamp was too old. */
class WebhookVerificationException extends ReedaptException
{
}
