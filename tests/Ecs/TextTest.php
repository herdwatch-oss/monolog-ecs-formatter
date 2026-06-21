<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function testAddStoresText(): void
    {
        self::assertSame(
            ['text' => ['reason' => 'Missing field', 'summary' => 'All good']],
            Text::create()->add('reason', 'Missing field')->add('summary', 'All good')->toEcs(),
        );
    }

    public function testEmptyBagProducesNoFragment(): void
    {
        self::assertSame([], Text::create()->toEcs());
    }

    public function testFromArrayCoercesScalarsToString(): void
    {
        self::assertSame(
            ['text' => ['code' => '404']],
            Text::fromArray(['code' => 404, 'payload' => ['nested']])->toEcs(),
        );
    }
}
