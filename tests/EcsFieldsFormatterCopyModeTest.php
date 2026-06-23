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
 * Copy mode differs from move mode in exactly one way: the legacy Monolog top-level keys
 * (channel, level_name, level, datetime) are kept, so dashboards querying the old keys keep working
 * during migration. Everything else — namespace promotion, the never-drop demotion of over-cap/invalid
 * entries as dotted keys, identity-field promotion, and exception consumption — is identical to move:
 * promoted values are NOT mirrored under context.<namespace>.
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

    public function testLabelsPromotedNotMirrored(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Labels::create()->add('env', 'prod'),
            'farm_id' => 42,
        ]));

        self::assertSame(['env' => 'prod'], $output['labels']);
        // Plain context is retained; the promoted namespace is NOT mirrored under context.
        self::assertSame(42, $output['context']['farm_id']);
        self::assertArrayNotHasKey('labels', $output['context']);
    }

    public function testMetricPromotedNotMirrored(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Metrics::create()->gauge('duration_ms', 150.0),
            'request_id' => 'req-abc',
        ]));

        self::assertSame(150.0, $output['metric']['duration_ms']);
        self::assertSame('req-abc', $output['context']['request_id']);
        self::assertArrayNotHasKey('metric', $output['context']);
    }

    public function testTagsPromotedNotMirrored(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [Tags::of('web', 'api')]));

        self::assertSame(['web', 'api'], $output['tags']);
        // Nothing left over — no context bucket is emitted.
        self::assertArrayNotHasKey('context', $output);
    }

    public function testTextPromotedNotMirrored(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Text::create()->add('note', 'Sync completed'),
        ]));

        self::assertSame(['note' => 'Sync completed'], $output['text']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testOverCapEntriesDemotedAsDottedKeys(): void
    {
        $labels = Labels::create();
        for ($i = 1; $i <= 10; $i++) {
            $labels->add("key$i", "val$i");
        }

        $output = $this->formatAndDecode($this->createRecord(context: [$labels]));

        // Top-level promotes the capped subset; the over-cap entries are never dropped — they are
        // demoted as dotted keys, not mirrored as a nested context.labels array.
        self::assertCount(8, $output['labels']);
        self::assertSame('val9', $output['context']['labels.key9']);
        self::assertSame('val10', $output['context']['labels.key10']);
        self::assertArrayNotHasKey('labels', $output['context']);
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

    public function testContextExceptionPromotedAndRemoved(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            'exception' => new \RuntimeException('db down'),
        ]));

        // Promoted to top-level error.*
        self::assertSame('RuntimeException', $output['error']['type']);
        self::assertSame('db down', $output['error']['message']);
        // The raw exception now lives, typed, under error.* — it is dropped from context (same as move).
        self::assertArrayNotHasKey('context', $output);
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

    public function testPlainContextNamespaceRetainedAlongsidePromotedBag(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            'metric' => ['hand_written' => 9],
            Metrics::create()->gauge('dur_ms', 1.5),
        ]));

        // The bag value is promoted to top-level; the plain-context 'metric' is retained as-is and
        // the promoted value is NOT merged back into it.
        self::assertSame(1.5, $output['metric']['dur_ms']);
        self::assertSame(9, $output['context']['metric']['hand_written']);
        self::assertArrayNotHasKey('dur_ms', $output['context']['metric']);
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
