<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Reedapt\Exception\ReedaptException;
use Reedapt\Exception\WebhookVerificationException;
use Reedapt\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as `reedapt.webhook`. Checks the `Reedapt-Signature` header against
 * the raw body with `reedapt.webhook.secret`, and answers 400 when it doesn't
 * match. On success the parsed event is on `$request->attributes` as `reedapt.event`.
 */
final class VerifyWebhookSignature
{
    public const ATTRIBUTE = 'reedapt.event';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('reedapt.webhook.secret');
        if (!is_string($secret) || $secret === '') {
            // A missing secret is a setup mistake, not a bad delivery: fail loudly.
            throw new ReedaptException('No webhook secret. Set REEDAPT_WEBHOOK_SECRET to the whsec_ secret of your endpoint.');
        }
        $tolerance = config('reedapt.webhook.tolerance', Webhook::DEFAULT_TOLERANCE);

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get('Reedapt-Signature'),
                $secret,
                is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE,
            );
        } catch (WebhookVerificationException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
        $request->attributes->set(self::ATTRIBUTE, $event);

        return $next($request);
    }
}
