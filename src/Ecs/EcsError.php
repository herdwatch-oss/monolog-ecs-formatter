<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `error.*` identity built from a Throwable. Named EcsError to avoid clashing with \Error.
 *
 * Captures error.type (the exception class), message, code and stack_trace.
 *
 * Example:
 *   $log->error('Sync failed', [new EcsError($exception)]);
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-error.html
 */
final class EcsError implements EcsField
{
    public function __construct(private readonly \Throwable $throwable)
    {
    }

    public function toEcs(): array
    {
        return [
            'error' => [
                'type' => $this->throwable::class,
                'message' => $this->throwable->getMessage(),
                'code' => (string) $this->throwable->getCode(),
                'stack_trace' => $this->throwable->getTraceAsString(),
            ],
        ];
    }
}
