<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Expected values for ECS `network.direction` as of ECS 8.11, the schema this formatter targets.
 * `inbound`/`outbound` describe traffic relative to the perimeter (the common application-level
 * choice); `ingress`/`egress` relative to the observing host; `internal`/`external` when the
 * perimeter notion does not apply; `unknown` when the direction cannot be told.
 *
 * For a dynamic value, use the built-in NetworkDirection::from()/tryFrom().
 *
 * @see https://www.elastic.co/guide/en/ecs/8.11/ecs-network.html
 */
enum NetworkDirection: string
{
    case Ingress = 'ingress';
    case Egress = 'egress';
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Internal = 'internal';
    case External = 'external';
    case Unknown = 'unknown';
}
