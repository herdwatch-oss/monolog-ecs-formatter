<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `network.*` fields. Immutable; construct with named arguments. Currently carries
 * `network.direction` (the `NetworkDirection` closed-set enum); the fragment grows additively
 * as further network fields are needed.
 *
 * Example:
 *   new Network(direction: NetworkDirection::Outbound);   // HTTP-client telemetry
 *   new Network(direction: NetworkDirection::Inbound);    // request logging
 *
 * @see https://www.elastic.co/guide/en/ecs/8.11/ecs-network.html
 */
final class Network implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly ?NetworkDirection $direction = null,
    ) {
    }

    public function toEcs(): array
    {
        if ($this->direction === null) {
            return [];
        }

        return ['network' => ['direction' => $this->direction->value]];
    }
}
