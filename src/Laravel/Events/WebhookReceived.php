<?php

declare(strict_types=1);

namespace Reedapt\Laravel\Events;

/**
 * Dispatched for every verified webhook delivery, whatever its type. The more
 * specific events below are dispatched as well, for the types they cover.
 *
 * @phpstan-import-type WebhookEvent from \Reedapt\Types
 */
class WebhookReceived
{
    /**
     * @param WebhookEvent $payload the event as Reedapt sent it: `id`, `type`, `created_at`, `data`
     */
    public function __construct(public readonly array $payload)
    {
    }

    public function id(): string
    {
        return $this->payload['id'];
    }

    public function type(): string
    {
        return $this->payload['type'];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->payload['data'];
    }
}
