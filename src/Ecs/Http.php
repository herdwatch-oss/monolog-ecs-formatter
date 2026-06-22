<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `http.*` fields. Immutable; construct with named arguments. Null fields are omitted.
 *
 * Example:
 *   new Http(statusCode: 500, method: 'POST');
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-http.html
 */
final class Http implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly ?int $statusCode = null,
        private readonly ?string $method = null,
    ) {
    }

    public function toEcs(): array
    {
        $http = [];

        if ($this->statusCode !== null) {
            $http['response'] = ['status_code' => $this->statusCode];
        }

        if ($this->method !== null) {
            $http['request'] = ['method' => strtoupper($this->method)];
        }

        return $http === [] ? [] : ['http' => $http];
    }
}
