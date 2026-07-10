<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Allowed values for the ECS `event.category` array — a closed set as of ECS 8.11, the schema this
 * formatter targets (ECS extends these sets additively in newer releases). The second level of the
 * ECS categorisation hierarchy (kind > category > type): e.g. outbound API telemetry carries `api`,
 * credential exchanges carry `authentication`.
 *
 * For a dynamic value, use the built-in EventCategory::from()/tryFrom().
 *
 * @see https://www.elastic.co/guide/en/ecs/8.11/ecs-allowed-values-event-category.html
 */
enum EventCategory: string
{
    case Api = 'api';
    case Authentication = 'authentication';
    case Configuration = 'configuration';
    case Database = 'database';
    case Driver = 'driver';
    case Email = 'email';
    case File = 'file';
    case Host = 'host';
    case Iam = 'iam';
    case IntrusionDetection = 'intrusion_detection';
    case Library = 'library';
    case Malware = 'malware';
    case Network = 'network';
    case Package = 'package';
    case Process = 'process';
    case Registry = 'registry';
    case Session = 'session';
    case Threat = 'threat';
    case Vulnerability = 'vulnerability';
    case Web = 'web';
}
