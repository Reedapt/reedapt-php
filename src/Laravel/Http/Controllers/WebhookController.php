<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Reedapt\Laravel\Events;
use Reedapt\Laravel\Http\Middleware\VerifyWebhookSignature;

/**
 * Dispatches a Laravel event for each verified delivery, then answers 200.
 * Use it behind the `reedapt.webhook` middleware.
 */
final class WebhookController
{
    /** @var array<string, class-string<Events\WebhookReceived>> */
    public const EVENTS = [
        'dub.completed' => Events\DubCompleted::class,
        'dub.failed' => Events\DubFailed::class,
        'dub.checked' => Events\DubChecked::class,
        'batch.completed' => Events\BatchCompleted::class,
        'webhook.test' => Events\WebhookTest::class,
    ];

    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $payload = $request->attributes->get(VerifyWebhookSignature::ATTRIBUTE);
        if (!is_array($payload)) {
            abort(500, 'Add the reedapt.webhook middleware to this route.');
        }
        /** @var array{id: string, type: string, created_at: string, data: array<string, mixed>} $payload */
        $events->dispatch(new Events\WebhookReceived($payload));
        $specific = self::EVENTS[$payload['type']] ?? null;
        if ($specific !== null) {
            $events->dispatch(new $specific($payload));
        }

        return response('', 200);
    }
}
