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
        $error = [
            'type' => $this->throwable::class,
            'message' => $this->throwable->getMessage(),
        ];

        // Most exceptions carry no code (getCode() === 0); omit it rather than emit a noisy "0".
        $code = $this->throwable->getCode();
        if ($code !== 0 && $code !== '') {
            $error['code'] = (string) $code;
        }

        // Full string form captures the throw-site file:line and the previous-exception chain,
        // both of which a bare getTraceAsString() omits.
        $error['stack_trace'] = (string) $this->throwable;

        return ['error' => $error];
    }
}
