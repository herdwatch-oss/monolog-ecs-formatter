<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests;

use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
use Herdwatch\MonologEcsFormatter\Ecs\Labels;
use Herdwatch\MonologEcsFormatter\Ecs\Metrics;
use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Herdwatch\MonologEcsFormatter\Ecs\Tags;
use Herdwatch\MonologEcsFormatter\Ecs\Text;
use Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter;
use Herdwatch\MonologEcsFormatter\Formatter\EcsFormatMode;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Copy mode = move mode + (a) legacy top-level keys (channel, level_name, level, datetime) are kept,
 * and (b) the full payload of each governed namespace is mirrored under context.<namespace> for
 * dashboard compatibility during migration. Identity fields (service, error, ...) are promoted to
 * top-level only.
 */
class EcsFieldsFormatterCopyModeTest extends TestCase
{
    private EcsFieldsFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new EcsFieldsFormatter(EcsFormatMode::Copy);
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private function createRecord(
        string $message = 'Test message',
        array $context = [],
        array $extra = [],
        Level $level = Level::Info,
        string $channel = 'app',
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-06-20T12:00:00+00:00'),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAndDecode(LogRecord $record): array
    {
        return json_decode($this->formatter->format($record), true);
    }

    public function testLegacyTopLevelKeysAreRetained(): void
    {
        $output = $this->formatAndDecode($this->createRecord(level: Level::Error, channel: 'worker'));

        self::assertSame('worker', $output['channel']);
        self::assertSame('ERROR', $output['level_name']);
        self::assertSame(Level::Error->value, $output['level']);
        self::assertArrayHasKey('datetime', $output);
    }

    public function testEcsBaseFieldsArePresent(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertSame('Test message', $output['message']);
        self::assertSame('info', $output['log.level']);
        self::assertSame('app', $output['log']['logger']);
        self::assertSame('8.11.0', $output['ecs.version']);
        self::assertArrayHasKey('@timestamp', $output);
        self::assertSame('event', $output['event']['kind']);
    }

    public function testLabelsPromotedAndMirroredUnderContext(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Labels::create()->add('env', 'prod'),
            'farm_id' => 42,
        ]));

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame(42, $output['context']['farm_id']);
        self::assertSame(['env' => 'prod'], $output['context']['labels']);
    }

    public function testMetricPromotedAndMirroredUnderContext(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Metrics::create()->gauge('duration_ms', 150.0),
            'request_id' => 'req-abc',
        ]));

        self::assertSame(150.0, $output['metric']['duration_ms']);
        self::assertSame('req-abc', $output['context']['request_id']);
        self::assertSame(['duration_ms' => 150.0], $output['context']['metric']);
    }

    public function testTagsPromotedAndMirroredUnderContext(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [Tags::of('web', 'api')]));

        self::assertSame(['web', 'api'], $output['tags']);
        self::assertSame(['web', 'api'], $output['context']['tags']);
    }

    public function testTextPromotedAndMirroredUnderContext(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Text::create()->add('note', 'Sync completed'),
        ]));

        self::assertSame(['note' => 'Sync completed'], $output['text']);
        self::assertSame(['note' => 'Sync completed'], $output['context']['text']);
    }

    public function testFullPayloadMirroredEvenWhenTopLevelIsCapped(): void
    {
        $labels = Labels::create();
        for ($i = 1; $i <= 10; $i++) {
            $labels->add("key$i", "val$i");
        }

        $output = $this->formatAndDecode($this->createRecord(context: [$labels]));

        // Top-level promotes the capped subset; the full original is mirrored under context.
        self::assertCount(8, $output['labels']);
        self::assertCount(10, $output['context']['labels']);
    }

    public function testExtraEmittedVerbatimInCopyMode(): void
    {
        $output = $this->formatAndDecode($this->createRecord(extra: [
            'metric' => ['items_count' => 5],
            'pid' => 1234,
        ]));

        self::assertArrayNotHasKey('metric', $output);
        self::assertSame(['items_count' => 5], $output['extra']['metric']);
        self::assertSame(1234, $output['extra']['pid']);
    }

    public function testServiceFromExtraPromotedToTopLevelOnly(): void
    {
        $output = $this->formatAndDecode($this->createRecord(extra: [
            'service' => new Service('my-service'),
        ]));

        self::assertSame('my-service', $output['service']['name']);
        self::assertSame('php', $output['service']['language']);
        // Identity objects are not mirrored under context.
        self::assertArrayNotHasKey('service', $output['context'] ?? []);
    }

    public function testErrorPromotedToTopLevel(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            new EcsError(new \RuntimeException('DB failed')),
        ]));

        self::assertSame('DB failed', $output['error']['message']);
        self::assertSame('RuntimeException', $output['error']['type']);
    }

    public function testServiceContextWinsOverExtra(): void
    {
        $output = $this->formatAndDecode($this->createRecord(
            context: [new Service('context-service')],
            extra: ['service' => new Service('extra-service')],
        ));

        self::assertSame('context-service', $output['service']['name']);
        self::assertSame('php', $output['service']['language']);
    }

    public function testOutputIsSingleLineNdjson(): void
    {
        $raw = $this->formatter->format($this->createRecord(
            message: 'Upload failed',
            context: [Labels::create()->add('herd', 'IE123'), 'farm_id' => 7],
            extra: ['service' => new Service('my-service')],
        ));

        self::assertStringEndsWith("\n", $raw);
        $lines = array_filter(explode("\n", $raw));
        self::assertCount(1, $lines);

        $output = json_decode($lines[0], true);
        self::assertSame(7, $output['context']['farm_id']);
        self::assertSame(['herd' => 'IE123'], $output['labels']);
        self::assertArrayHasKey('channel', $output);
        self::assertArrayHasKey('service', $output);
    }

    public function testDefaultConstructorIsMoveMode(): void
    {
        $move = new EcsFieldsFormatter();
        $output = json_decode($move->format($this->createRecord(level: Level::Warning)), true);

        self::assertArrayNotHasKey('channel', $output);
        self::assertArrayNotHasKey('level_name', $output);
        self::assertArrayHasKey('event', $output);
    }
}
