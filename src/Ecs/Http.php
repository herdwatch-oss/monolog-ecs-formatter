<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `http.*` fields. Immutable; construct with named arguments. Null fields are omitted.
 *
 * Request and response details are emitted as nested objects (`http.request.*` / `http.response.*`),
 * so two separate Http objects deep-merge cleanly instead of overwriting each other.
 *
 * Example:
 *   new Http(statusCode: 200, method: 'POST', requestBodyBytes: 12, responseBodyBytes: 340,
 *            requestMimeType: 'application/json', responseMimeType: 'application/json');
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-http.html
 */
final class Http implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly ?int $statusCode = null,
        private readonly ?string $method = null,
        private readonly ?int $requestBodyBytes = null,
        private readonly ?int $responseBodyBytes = null,
        private readonly ?string $requestMimeType = null,
        private readonly ?string $responseMimeType = null,
    ) {
    }

    public function toEcs(): array
    {
        $request = array_filter(
            [
                'method' => $this->method !== null ? strtoupper($this->method) : null,
                'body' => $this->requestBodyBytes !== null ? ['bytes' => $this->requestBodyBytes] : null,
                'mime_type' => $this->requestMimeType,
            ],
            static fn (string|int|array|null $value): bool => $value !== null,
        );

        $response = array_filter(
            [
                'status_code' => $this->statusCode,
                'body' => $this->responseBodyBytes !== null ? ['bytes' => $this->responseBodyBytes] : null,
                'mime_type' => $this->responseMimeType,
            ],
            static fn (string|int|array|null $value): bool => $value !== null,
        );

        $http = array_filter(
            ['request' => $request, 'response' => $response],
            static fn (array $value): bool => $value !== [],
        );

        return $http === [] ? [] : ['http' => $http];
    }
}
