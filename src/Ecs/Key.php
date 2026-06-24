<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Shared key-name policy for governed namespaces (labels, metric, text).
 *
 * Keys must be lower snake_case of at most three segments — e.g. `env`, `user_id`,
 * `read_write_ms`. This keeps promoted ECS field names predictable and bounded.
 * Keys that fail validation are never dropped: the formatter demotes them to the
 * leftover `context` bucket (see EcsFieldsFormatter).
 */
final class Key
{
    public const string PATTERN = '/^[a-z][a-z0-9]*(_[a-z][a-z0-9]*){0,2}$/';

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }
}
