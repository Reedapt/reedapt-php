<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Events;

/** `dub.failed`: a dub failed. `data()['dub']` carries its error. */
final class DubFailed extends WebhookReceived
{
}
