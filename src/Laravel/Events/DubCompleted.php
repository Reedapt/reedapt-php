<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Events;

/** `dub.completed`: a dub finished. `data()['dub']` is the finished dub. */
final class DubCompleted extends WebhookReceived
{
}
