<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Typed bag for filtering dimensions, promoted to the ECS `labels.*` base field.
 *
 * Values are keyword strings: scalars are coerced to string (booleans become "true"/"false").
 *
 * Example:
 *   Labels::create()->add('tenant', 'acme')->add('env', 'prod')
 *
 * Key validity and the cap are enforced by the formatter; invalid/over-cap keys are demoted
 * to the leftover context (move) or mirrored under context (copy), never dropped.
 */
final class Labels implements EcsField
{
    /** @var array<string, string> */
    private array $data = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param array<array-key, mixed> $labels
     */
    public static function fromArray(array $labels): self
    {
        $bag = new self();

        foreach ($labels as $key => $value) {
            if (is_scalar($value)) {
                $bag->add((string) $key, $value);
            }
        }

        return $bag;
    }

    public function add(string $key, string|int|float|bool $value): self
    {
        $this->data[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this;
    }

    public function toEcs(): array
    {
        return $this->data === [] ? [] : ['labels' => $this->data];
    }
}
