<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `event.*` enrichment — adds action / start / end / duration / sequence / outcome / reason /
 * type / category / url alongside the base event object the formatter already emits (kind,
 * module, dataset, severity). Immutable; null/empty fields omitted.
 *
 * The formatter merges this additively under the base event, so it can add new sub-keys but can
 * never override the base ones. `start`/`end` accept any DateTimeInterface and are rendered to
 * ISO-8601 strings here (so they serialise identically through EcsFieldsFormatter and any other
 * handler); `durationNanos` is ECS `event.duration` in nanoseconds; `sequence` is the monotonic
 * number that orders events sharing a timestamp; `outcome` is the ECS `event.outcome` keyword,
 * typed as {@see EventOutcome} since ECS restricts it to success | failure | unknown; `reason` is
 * the ECS `event.reason` keyword — a short, low-cardinality description of why the outcome
 * occurred (e.g. a failure classification like "threshold" or "timeout"); `type` and `category`
 * are the ECS categorisation arrays, typed as the {@see EventType} and {@see EventCategory}
 * closed sets (de-duplicated on output); `url` is the ECS `event.url` link to an external system
 * where investigation of this event can continue (e.g. a report dashboard).
 *
 * Example:
 *   new Event(action: 'farm-sync', outcome: EventOutcome::Failure, reason: 'threshold');
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-event.html
 */
final class Event implements EcsField
{
    use SerializesToEcs;

    /** ISO-8601 with microseconds — the ECS @timestamp precision the formatter emits for every record. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param list<EventType>     $type
     * @param list<EventCategory> $category
     */
    public function __construct(
        private readonly ?string $action = null,
        private readonly ?\DateTimeInterface $start = null,
        private readonly ?int $durationNanos = null,
        private readonly ?EventOutcome $outcome = null,
        private readonly ?string $reason = null,
        private readonly ?\DateTimeInterface $end = null,
        private readonly ?int $sequence = null,
        private readonly array $type = [],
        private readonly array $category = [],
        private readonly ?string $url = null,
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
                'end' => $this->end?->format(self::TIMESTAMP_FORMAT),
                'duration' => $this->durationNanos,
                'sequence' => $this->sequence,
                'outcome' => $this->outcome?->value,
                'reason' => $this->reason,
                'url' => $this->url,
            ],
            static fn (string|int|null $value): bool => $value !== null,
        );

        if ([] !== $this->type) {
            $event['type'] = array_values(array_unique(array_map(
                static fn (EventType $type): string => $type->value,
                $this->type,
            )));
        }

        if ([] !== $this->category) {
            $event['category'] = array_values(array_unique(array_map(
                static fn (EventCategory $category): string => $category->value,
                $this->category,
            )));
        }

        return $event === [] ? [] : ['event' => $event];
    }
}
