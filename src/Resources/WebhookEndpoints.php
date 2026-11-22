<?php

declare(strict_types=1);

namespace Reedapt\Resources;

/**
 * @phpstan-import-type WebhookEndpoint from \Reedapt\Types
 * @phpstan-import-type WebhookEndpointList from \Reedapt\Types
 * @phpstan-import-type WebhookDelivery from \Reedapt\Types
 * @phpstan-import-type WebhookDeliveryList from \Reedapt\Types
 * @phpstan-import-type RequestOptions from \Reedapt\Types
 */
final class WebhookEndpoints extends AbstractResource
{
    /**
     * Adds an endpoint. The answer's `secret` is shown only this once.
     *
     * @param array{url: string, description?: string} $params
     * @param RequestOptions $options
     * @return WebhookEndpoint
     */
    public function create(array $params, array $options = []): array
    {
        // Not retried after a network failure: it could add the endpoint twice.
        /** @var WebhookEndpoint */
        return $this->client->request('POST', '/webhook_endpoints', ['body' => $params, 'retry' => 'unsafe'], $options);
    }

    /**
     * @param RequestOptions $options
     * @return WebhookEndpointList
     */
    public function list(array $options = []): array
    {
        /** @var WebhookEndpointList */
        return $this->client->request('GET', '/webhook_endpoints', ['retry' => 'safe'], $options);
    }

    /** @param RequestOptions $options */
    public function delete(string $endpointId, array $options = []): void
    {
        $this->client->request('DELETE', '/webhook_endpoints/'.self::id($endpointId), ['retry' => 'safe'], $options);
    }

    /**
     * Sends a `webhook.test` event to the endpoint.
     *
     * @param RequestOptions $options
     */
    public function sendTest(string $endpointId, array $options = []): void
    {
        $this->client->request('POST', '/webhook_endpoints/'.self::id($endpointId).'/test', ['retry' => 'unsafe'], $options);
    }

    /**
     * Recent deliveries to the endpoint, with what it answered.
     *
     * @param array{limit?: int} $params
     * @param RequestOptions $options
     * @return WebhookDeliveryList
     */
    public function deliveries(string $endpointId, array $params = [], array $options = []): array
    {
        /** @var WebhookDeliveryList */
        return $this->client->request('GET', '/webhook_endpoints/'.self::id($endpointId).'/deliveries', ['query' => $params, 'retry' => 'safe'], $options);
    }

    /**
     * Sends a past delivery's event to the endpoint again, now. It keeps its event id,
     * so a receiver that skips repeats by event id skips it too.
     *
     * @param RequestOptions $options
     * @return WebhookDelivery
     */
    public function resendDelivery(string $endpointId, string $deliveryId, array $options = []): array
    {
        /** @var WebhookDelivery */
        return $this->client->request(
            'POST',
            '/webhook_endpoints/'.self::id($endpointId).'/deliveries/'.self::id($deliveryId).'/resend',
            ['retry' => 'unsafe'],
            $options,
        );
    }
}
