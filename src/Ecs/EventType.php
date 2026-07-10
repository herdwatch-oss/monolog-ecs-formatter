<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Allowed values for the ECS `event.type` array — a closed set, per the ECS spec. The third
 * level of the ECS categorisation hierarchy (kind > category > type): e.g. a record about a
 * created entity carries `creation`, and span-style lifecycle markers carry `start` / `end`.
 *
 * For a dynamic value, use the built-in EventType::from()/tryFrom().
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-allowed-values-event-type.html
 */
enum EventType: string
{
    case Access = 'access';
    case Admin = 'admin';
    case Allowed = 'allowed';
    case Change = 'change';
    case Connection = 'connection';
    case Creation = 'creation';
    case Deletion = 'deletion';
    case Denied = 'denied';
    case End = 'end';
    case Error = 'error';
    case Group = 'group';
    case Indicator = 'indicator';
    case Info = 'info';
    case Installation = 'installation';
    case Protocol = 'protocol';
    case Start = 'start';
    case User = 'user';
}
