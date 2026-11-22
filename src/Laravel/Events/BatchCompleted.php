<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Events;

/** `batch.completed`: every dub in a batch is done. `data()['batch']` is the summary. */
final class BatchCompleted extends WebhookReceived
{
}
