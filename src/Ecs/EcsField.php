<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Marker interface for any value object that contributes ECS-shaped fields to a log record.
 *
 * Pass an EcsField anywhere in a log's context (positionally or under any key) and the
 * EcsFieldsFormatter will detect it by instanceof, lift its fragment to the appropriate
 * ECS location, and remove it from the leftover context. Detection is by interface, not
 * by class name, so project-specific types are picked up automatically with no registration.
 *
 * Implementations return their contribution as a nested array keyed by ECS field path, e.g.
 *   ['metric' => ['orders_total' => 1200]]
 *   ['service' => ['name' => 'billing']]
 *   ['trace' => ['id' => 'abc'], 'transaction' => ['id' => 'xyz']]
 *
 * Fragments targeting a governed namespace (labels, metric, text, tags) are validated, type-safe
 * (the bag's method signatures enforce value types) and capped by the formatter; everything else
 * is passed through. A fragment can never overwrite the base ECS skeleton.
 */
interface EcsField
{
    /**
     * The ECS field fragment this object contributes, already in its final nested shape.
     *
     * @return array<string, mixed>
     */
    public function toEcs(): array;
}
