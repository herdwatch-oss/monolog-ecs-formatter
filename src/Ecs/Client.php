<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `client.*` fields — the originating end of a network connection (e.g. the HTTP client).
 * Immutable; construct with named arguments. Null fields are omitted.
 *
 * Example:
 *   new Client(ip: $request->getClientIp());
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-client.html
 */
final class Client implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly ?string $ip = null,
        private readonly ?int $port = null,
    ) {
    }

    public function toEcs(): array
    {
        $client = array_filter(
            ['ip' => $this->ip, 'port' => $this->port],
            static fn (string|int|null $value): bool => $value !== null,
        );

        return $client === [] ? [] : ['client' => $client];
    }
}
