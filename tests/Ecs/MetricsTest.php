<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\Metrics;
use PHPUnit\Framework\TestCase;

class MetricsTest extends TestCase
{
    public function testTypedMethodsFixJsonType(): void
    {
        $metrics = Metrics::create()
            ->count('retry_count', 3)
            ->total('events_total', 500)
            ->gauge('duration_ms', 12.5)
            ->flag('is_cached', true);

        self::assertSame(
            ['metric' => ['retry_count' => 3, 'events_total' => 500, 'duration_ms' => 12.5, 'is_cached' => true]],
            $metrics->toEcs(),
        );
    }

    public function testEmptyBagProducesNoFragment(): void
    {
        self::assertSame([], Metrics::create()->toEcs());
    }

    public function testSetOverwritesSameKey(): void
    {
        self::assertSame(
            ['metric' => ['x_ms' => 2.0]],
            Metrics::create()->gauge('x_ms', 1.0)->gauge('x_ms', 2.0)->toEcs(),
        );
    }

    public function testFromArrayCoercesNumericStringsAndSkipsNonNumeric(): void
    {
        $metrics = Metrics::fromArray([
            'rows_total' => 1200,
            'avg_ms' => '8.3',
            'label' => 'nope',
            'is_ok' => true,
        ]);

        self::assertSame(
            ['metric' => ['rows_total' => 1200, 'avg_ms' => 8.3, 'is_ok' => true]],
            $metrics->toEcs(),
        );
    }
}
