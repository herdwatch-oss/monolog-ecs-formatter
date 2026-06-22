<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * Typed bag for the ECS `tags` base field — a flat, de-duplicated array of keyword strings.
 *
 * Example:
 *   Tags::of('billing', 'reconciliation')
 *
 * Empty strings and duplicates are dropped at add time (de-duplication is not data loss).
 * The cap is enforced by the formatter; overflow is preserved in the leftover context.
 */
final class Tags implements EcsField
{
    use SerializesToEcs;

    /** @var list<string> */
    private array $tags = [];

    public static function of(string ...$tags): self
    {
        $bag = new self();

        foreach ($tags as $tag) {
            $bag->add($tag);
        }

        return $bag;
    }

    /**
     * @param array<array-key, mixed> $tags
     */
    public static function fromArray(array $tags): self
    {
        $bag = new self();

        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $bag->add($tag);
            }
        }

        return $bag;
    }

    public function add(string $tag): self
    {
        if ($tag !== '' && !in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    public function toEcs(): array
    {
        return $this->tags === [] ? [] : ['tags' => $this->tags];
    }
}
