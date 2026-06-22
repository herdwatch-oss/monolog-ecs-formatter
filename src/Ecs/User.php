<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `user.*` identity. Immutable; construct with named arguments. Null fields are omitted.
 *
 * Example:
 *   new User(id: 42, email: 'farmer@example.com')
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-user.html
 */
final class User implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly string|int|null $id = null,
        private readonly ?string $name = null,
        private readonly ?string $email = null,
        private readonly ?string $domain = null,
        private readonly ?string $fullName = null,
        private readonly ?string $hash = null,
    ) {
    }

    public function toEcs(): array
    {
        $user = array_filter(
            [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
                'domain' => $this->domain,
                'full_name' => $this->fullName,
                'hash' => $this->hash,
            ],
            static fn (string|int|null $value): bool => $value !== null,
        );

        return $user === [] ? [] : ['user' => $user];
    }
}
