<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Events;

/** `webhook.test`: a test delivery, from `webhookEndpoints->sendTest()`. */
final class WebhookTest extends WebhookReceived
{
}
