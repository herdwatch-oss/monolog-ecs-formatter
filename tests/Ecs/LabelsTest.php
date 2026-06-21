<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\Labels;
use PHPUnit\Framework\TestCase;

class LabelsTest extends TestCase
{
    public function testValuesCoercedToString(): void
    {
        $labels = Labels::create()
            ->add('name', 'acme')
            ->add('count', 42)
            ->add('active', true)
            ->add('inactive', false)
            ->add('ratio', 3.14);

        self::assertSame(
            ['labels' => ['name' => 'acme', 'count' => '42', 'active' => 'true', 'inactive' => 'false', 'ratio' => '3.14']],
            $labels->toEcs(),
        );
    }

    public function testEmptyBagProducesNoFragment(): void
    {
        self::assertSame([], Labels::create()->toEcs());
    }

    public function testFromArraySkipsNonScalarValues(): void
    {
        $labels = Labels::fromArray(['env' => 'prod', 'nested' => ['x'], 'count' => 5]);

        self::assertSame(['labels' => ['env' => 'prod', 'count' => '5']], $labels->toEcs());
    }
}
