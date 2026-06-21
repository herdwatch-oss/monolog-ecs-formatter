<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `host.*` fields. Immutable; construct with named arguments. Null fields are omitted.
 *
 * Example:
 *   new Host(name: $hostname);
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-host.html
 */
final class Host implements EcsField
{
    public function __construct(
        private readonly ?string $name = null,
        private readonly ?string $ip = null,
    ) {
    }

    public function toEcs(): array
    {
        $host = array_filter(
            ['name' => $this->name, 'ip' => $this->ip],
            static fn (?string $value): bool => $value !== null,
        );

        return $host === [] ? [] : ['host' => $host];
    }
}
