<?php

declare(strict_types=1);

namespace Reedapt;

use Reedapt\Exception\WebhookVerificationException;

/**
 * Webhook signature checks. The methods are static, and also reachable as
 * `$client->webhooks->verify(...)`.
 *
 * @phpstan-import-type WebhookEvent from Types
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Checks a delivery's `Reedapt-Signature` header against the raw request
     * body, exactly as it arrived. Throws if it doesn't match or is older than
     * `$tolerance` seconds.
     *
     * @throws WebhookVerificationException
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): void
    {
        if ($header === null || $header === '') {
            throw new WebhookVerificationException('The Reedapt-Signature header is missing.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            $at = strpos($item, '=');
            if ($at === false) {
                continue;
            }
            $name = trim(substr($item, 0, $at));
            $value = trim(substr($item, $at + 1));
            if ($name === 't') {
                $timestamp = $value;
            } elseif ($name === 'v1') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || preg_match('/^\d+$/', $timestamp) !== 1 || $signatures === []) {
            throw new WebhookVerificationException('The Reedapt-Signature header is malformed.');
        }
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('The signature is too old. Check your server\'s clock.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $given) {
            if (hash_equals($expected, $given)) {
                return;
            }
        }
        throw new WebhookVerificationException(
            'The signature doesn\'t match. Use the endpoint\'s secret and the raw body, not re-encoded JSON.'
        );
    }

    /**
     * Verifies a delivery and returns its parsed event.
     *
     * @return WebhookEvent
     *
     * @throws WebhookVerificationException
     */
    public static function constructEvent(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): array
    {
        self::verify($payload, $header, $secret, $tolerance);
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookVerificationException('The delivery is signed but its body is not valid JSON.', 0, $e);
        }
        if (!is_array($event)) {
            throw new WebhookVerificationException('The delivery is signed but its body is not a JSON object.');
        }

        /** @var WebhookEvent $event */
        return $event;
    }
}
