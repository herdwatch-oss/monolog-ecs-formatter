<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Default {@see \JsonSerializable} bridge for {@see EcsField}: serialise to the ECS fragment.
 *
 * Lets a value object render as its real ECS data under any formatter that JSON-encodes the
 * context/extra it is in — not just EcsFieldsFormatter. Without this a non-ECS handler would emit
 * a useless `{"Fully\\Qualified\\ClassName": {}}` (all properties are private).
 */
trait SerializesToEcs
{
    /** @return array<string, mixed> */
    abstract public function toEcs(): array;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toEcs();
    }
}
