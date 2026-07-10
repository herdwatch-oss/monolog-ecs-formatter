<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Allowed values for ECS `event.outcome` — a closed set as of ECS 8.11, the schema this formatter
 * targets ("the field value must be one of: failure, success, unknown"). Backed by the string ECS
 * expects.
 *
 * For a dynamic value, use the built-in EventOutcome::from()/tryFrom().
 *
 * @see https://www.elastic.co/guide/en/ecs/8.11/ecs-event.html
 */
enum EventOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Unknown = 'unknown';
}
