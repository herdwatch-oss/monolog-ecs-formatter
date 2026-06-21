<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS tracing identity — promotes top-level `trace.id` and (optionally) `transaction.id`
 * so logs correlate with APM traces.
 *
 * Example:
 *   new Tracing($traceId, $transactionId);
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-tracing.html
 */
final class Tracing implements EcsField
{
    public function __construct(
        private readonly string $traceId,
        private readonly ?string $transactionId = null,
    ) {
    }

    public function toEcs(): array
    {
        $out = ['trace' => ['id' => $this->traceId]];

        if ($this->transactionId !== null) {
            $out['transaction'] = ['id' => $this->transactionId];
        }

        return $out;
    }
}
