<?php

declare(strict_types=1);

namespace Reedapt\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * The API answered with an error. Branch on `$errorCode`, not on the message.
 * `getCode()` is the HTTP status.
 *
 * @phpstan-type FieldProblem array{field: string, message: string}
 */
class ApiException extends ReedaptException
{
    /** @var list<array{field: string, message: string}>|null */
    public readonly ?array $fields;

    public readonly string $errorCode;

    public readonly ?string $requestId;

    /**
     * @param array<string, mixed>|null $body the `error` object of the response
     * @param array<string, array<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        ?array $body,
        public readonly array $headers = [],
    ) {
        $code = is_string($body['code'] ?? null) && $body['code'] !== '' ? $body['code'] : 'HTTP_'.$status;
        $message = is_string($body['message'] ?? null) ? $body['message'] : "The API answered {$status}.";
        parent::__construct("{$code}: {$message}", $status);
        $this->errorCode = $code;
        $this->requestId = is_string($body['request_id'] ?? null) ? $body['request_id'] : $this->header('request-id');
        /** @var list<array{field: string, message: string}>|null $fields */
        $fields = is_array($body['fields'] ?? null) ? array_values($body['fields']) : null;
        $this->fields = $fields;
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $body = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        $headers = $response->getHeaders();

        return match (true) {
            $status === 401 => new AuthenticationException($status, $body, $headers),
            $status === 404 => new NotFoundException($status, $body, $headers),
            $status === 409 => new ConflictException($status, $body, $headers),
            $status === 429 => new RateLimitException($status, $body, $headers),
            in_array($status, [400, 411, 413, 422], true) => new InvalidRequestException($status, $body, $headers),
            $status >= 500 => new ServerException($status, $body, $headers),
            default => new self($status, $body, $headers),
        };
    }

    /** A response header's value, whatever the case of its name. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
