<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Typed bag for numeric/boolean metrics, promoted to the `metric.*` namespace.
 *
 * The method you call fixes the JSON type — no key-name convention or formatter-side
 * coercion is involved:
 *   - count()/total() → int
 *   - gauge()         → float
 *   - flag()          → bool
 *
 * Example:
 *   Metrics::create()->total('orders_total', 1200)->gauge('latency_ms', 12.5)->flag('is_retry', false)
 *
 * Key validity and the per-namespace cap are enforced by the formatter, not here: an invalid
 * or over-cap key is demoted to the leftover context (move) or mirrored under context (copy),
 * never dropped and never thrown.
 */
final class Metrics implements EcsField
{
    /** @var array<string, int|float|bool> */
    private array $data = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * Build a bag from a plain array. Non-numeric, non-bool values are ignored — use
     * Labels or Text for strings. Numeric strings are coerced to int/float.
     *
     * @param array<array-key, mixed> $metrics
     */
    public static function fromArray(array $metrics): self
    {
        $bag = new self();

        foreach ($metrics as $key => $value) {
            if (is_int($value) || is_float($value) || is_bool($value)) {
                $bag->set((string) $key, $value);
            } elseif (is_numeric($value)) {
                $bag->set((string) $key, $value + 0);
            }
        }

        return $bag;
    }

    public function count(string $key, int $value): self
    {
        return $this->set($key, $value);
    }

    public function total(string $key, int $value): self
    {
        return $this->set($key, $value);
    }

    public function gauge(string $key, float $value): self
    {
        return $this->set($key, $value);
    }

    public function flag(string $key, bool $value): self
    {
        return $this->set($key, $value);
    }

    public function set(string $key, int|float|bool $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function toEcs(): array
    {
        return $this->data === [] ? [] : ['metric' => $this->data];
    }
}
