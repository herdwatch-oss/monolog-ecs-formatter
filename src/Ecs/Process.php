<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `process.*` fields. Immutable; construct with named arguments. Null fields are omitted.
 *
 * Example:
 *   new Process(pid: getmypid(), commandLine: implode(' ', $argv));
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-process.html
 */
final class Process implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly ?int $pid = null,
        private readonly ?string $commandLine = null,
        private readonly ?string $name = null,
    ) {
    }

    public function toEcs(): array
    {
        $process = array_filter(
            [
                'pid' => $this->pid,
                'command_line' => $this->commandLine,
                'name' => $this->name,
            ],
            static fn (string|int|null $value): bool => $value !== null,
        );

        return $process === [] ? [] : ['process' => $process];
    }
}
