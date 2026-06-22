<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `event.*` enrichment — adds action / start / duration alongside the base event object the
 * formatter already emits (kind, module, dataset, created, severity). Immutable; null fields omitted.
 *
 * The formatter merges this additively under the base event, so it can add new sub-keys but can
 * never override the base ones. `start` accepts any DateTimeInterface and is formatted by the
 * formatter; `durationNanos` is ECS `event.duration` in nanoseconds.
 *
 * Example:
 *   new Event(action: 'farm.sync', start: $startedAt);
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-event.html
 */
final class Event implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly ?string $action = null,
        private readonly ?\DateTimeInterface $start = null,
        private readonly ?int $durationNanos = null,
    ) {
    }

    public function toEcs(): array
    {
        $event = array_filter(
            [
                'action' => $this->action,
                'start' => $this->start,
                'duration' => $this->durationNanos,
            ],
            static fn (string|int|\DateTimeInterface|null $value): bool => $value !== null,
        );

        return $event === [] ? [] : ['event' => $event];
    }
}
