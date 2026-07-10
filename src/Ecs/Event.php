<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `event.*` enrichment — adds action / start / duration / outcome / reason alongside the base event
 * object the formatter already emits (kind, module, dataset, created, severity). Immutable; null
 * fields omitted.
 *
 * The formatter merges this additively under the base event, so it can add new sub-keys but can
 * never override the base ones. `start` accepts any DateTimeInterface and is rendered to an ISO-8601
 * string here (so it serialises identically through EcsFieldsFormatter and any other handler);
 * `durationNanos` is ECS `event.duration` in nanoseconds; `outcome` is the ECS `event.outcome`
 * keyword, typed as {@see EventOutcome} since ECS restricts it to success | failure | unknown;
 * `reason` is the ECS `event.reason` keyword — a short, low-cardinality description of why the
 * outcome occurred (e.g. a failure classification like "threshold" or "timeout").
 *
 * Example:
 *   new Event(action: 'farm.sync', outcome: EventOutcome::Failure, reason: 'threshold');
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-event.html
 */
final class Event implements EcsField
{
    use SerializesToEcs;

    /** ISO-8601 with microseconds — the ECS @timestamp precision the formatter emits for every record. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private readonly ?string $action = null,
        private readonly ?\DateTimeInterface $start = null,
        private readonly ?int $durationNanos = null,
        private readonly ?EventOutcome $outcome = null,
        private readonly ?string $reason = null,
    ) {
    }

    public function toEcs(): array
    {
        $event = array_filter(
            [
                'action' => $this->action,
                // Format here rather than leaving a raw DateTime: under a non-ECS handler jsonSerialize()
                // bypasses the formatter, and json_encode would emit {"date":…,"timezone_type":…} instead.
                'start' => $this->start?->format(self::TIMESTAMP_FORMAT),
                'duration' => $this->durationNanos,
                'outcome' => $this->outcome?->value,
                'reason' => $this->reason,
            ],
            static fn (string|int|null $value): bool => $value !== null,
        );

        return $event === [] ? [] : ['event' => $event];
    }
}
