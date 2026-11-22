<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Events;

/** `dub.checked`: Reedapt checked a finished dub. `data()['quality']` lists the lines it fixed and the ones left to review. */
final class DubChecked extends WebhookReceived
{
}
