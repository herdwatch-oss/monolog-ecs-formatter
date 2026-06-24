<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\Tags;
use PHPUnit\Framework\TestCase;

class TagsTest extends TestCase
{
    public function testOfDeduplicatesAndDropsEmptyStrings(): void
    {
        self::assertSame(['tags' => ['web', 'api']], Tags::of('web', 'api', 'web', '')->toEcs());
    }

    public function testEmptyBagProducesNoFragment(): void
    {
        self::assertSame([], Tags::of()->toEcs());
    }

    public function testFromArraySkipsNonStrings(): void
    {
        self::assertSame(['tags' => ['web', 'api']], Tags::fromArray(['web', 42, 'api', null, 'web'])->toEcs());
    }
}
