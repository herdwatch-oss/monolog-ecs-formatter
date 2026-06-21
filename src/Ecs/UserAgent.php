<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `user_agent.*` fields. Immutable; construct with named arguments. Null fields are omitted.
 *
 * Example:
 *   new UserAgent(device: 'iPhone', version: '4.2.1');
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-user_agent.html
 */
final class UserAgent implements EcsField
{
    public function __construct(
        private readonly ?string $original = null,
        private readonly ?string $version = null,
        private readonly ?string $device = null,
    ) {
    }

    public function toEcs(): array
    {
        $userAgent = array_filter(
            ['original' => $this->original, 'version' => $this->version],
            static fn (?string $value): bool => $value !== null,
        );

        if ($this->device !== null) {
            $userAgent['device'] = ['name' => $this->device];
        }

        return $userAgent === [] ? [] : ['user_agent' => $userAgent];
    }
}
