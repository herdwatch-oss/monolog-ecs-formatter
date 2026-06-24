<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\EcsField;
use Herdwatch\MonologEcsFormatter\Ecs\Event;
use Herdwatch\MonologEcsFormatter\Ecs\Metrics;
use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * EcsField is JsonSerializable, so a typed value object serialises to its real ECS data under any
 * handler — not just EcsFieldsFormatter. Without this, a non-ECS handler renders the object as a
 * useless `{"Fully\\Qualified\\ClassName": {}}` because all its properties are private.
 */
class SerializesToEcsTest extends TestCase
{
    public function testEcsFieldIsJsonSerializableToItsEcsFragment(): void
    {
        $service = new Service('billing');

        self::assertInstanceOf(\JsonSerializable::class, $service);
        self::assertSame($service->toEcs(), $service->jsonSerialize());
        self::assertSame('{"service":{"name":"billing","language":"php"}}', json_encode($service));
    }

    public function testValueObjectRendersItsDataUnderAPlainJsonFormatter(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'm',
            context: [Metrics::create()->count('memory_bytes', 123)],
            extra: ['service' => new Service('billing')],
        );

        $output = json_decode((new JsonFormatter())->format($record), true);

        // Real ECS data, not an empty object keyed by the FQCN.
        self::assertSame(['metric' => ['memory_bytes' => 123]], $output['context'][0]);
        self::assertSame(['service' => ['name' => 'billing', 'language' => 'php']], $output['extra']['service']);
    }

    public function testEventStartSerialisesAsIsoStringUnderPlainJsonFormatter(): void
    {
        // Regression: a raw DateTime would render as {"date":…,"timezone_type":…} (and break an ES
        // date mapping) under a non-ECS handler. Event formats it to an ISO string in toEcs().
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'm',
            context: [new Event(start: new \DateTimeImmutable('2026-06-21T11:59:59.250000+00:00'))],
            extra: [],
        );

        $output = json_decode((new JsonFormatter())->format($record), true);

        self::assertSame('2026-06-21T11:59:59.250000+00:00', $output['context'][0]['event']['start']);
    }

    public function testCustomEcsFieldUsingTheTraitAlsoSerialises(): void
    {
        $field = new class () implements EcsField {
            use \Herdwatch\MonologEcsFormatter\Ecs\SerializesToEcs;

            public function toEcs(): array
            {
                return ['farm' => ['herd_id' => 7]];
            }
        };

        self::assertSame('{"farm":{"herd_id":7}}', json_encode($field));
    }
}
