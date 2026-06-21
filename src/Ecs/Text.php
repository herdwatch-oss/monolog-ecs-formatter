<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Typed bag for free-form text fields (one or two per event is plenty), promoted to `text.*`.
 *
 * Example:
 *   Text::create()->add('reason', 'Missing required field "email"')
 *
 * Key validity and the cap are enforced by the formatter; invalid/over-cap keys are demoted
 * to the leftover context (move) or mirrored under context (copy), never dropped.
 */
final class Text implements EcsField
{
    /** @var array<string, string> */
    private array $data = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param array<array-key, mixed> $text
     */
    public static function fromArray(array $text): self
    {
        $bag = new self();

        foreach ($text as $key => $value) {
            if (is_scalar($value)) {
                $bag->add((string) $key, (string) $value);
            }
        }

        return $bag;
    }

    public function add(string $key, string $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function toEcs(): array
    {
        return $this->data === [] ? [] : ['text' => $this->data];
    }
}
