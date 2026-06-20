<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests;

use Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter;
use Herdwatch\MonologEcsFormatter\Formatter\EcsFormatMode;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Verifies copy-mode behaviour:
 *   - Legacy top-level fields (channel, level_name, level, datetime) are retained.
 *   - ECS fields (event.*, log.*) are added on top.
 *   - Promoted namespaces (labels, metric, text, tags) appear at top-level AND the
 *     original context/extra are preserved.
 *   - service/error from extra/context are promoted to top-level in copy mode.
 *   - Output is a single valid NDJSON line.
 */
class EcsFieldsFormatterCopyModeTest extends TestCase
{
    private EcsFieldsFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new EcsFieldsFormatter(EcsFormatMode::Copy);
    }

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

    private function formatAndDecode(LogRecord $record): array
    {
        return json_decode($this->formatter->format($record), true);
    }

    // --- Legacy top-level keys are retained ---

    public function testLegacyTopLevelKeysAreRetained(): void
    {
        $record = $this->createRecord(level: Level::Error, channel: 'worker');
        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('channel', $output);
        self::assertArrayHasKey('level_name', $output);
        self::assertArrayHasKey('level', $output);
        self::assertArrayHasKey('datetime', $output);

        self::assertSame('worker', $output['channel']);
        self::assertSame('ERROR', $output['level_name']);
        self::assertSame(Level::Error->value, $output['level']);
    }

    // --- ECS fields are added ---

    public function testEcsBaseFieldsArePresent(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertSame('Test message', $output['message']);
        self::assertArrayHasKey('event', $output);
        self::assertSame('event', $output['event']['kind']);
        self::assertSame('symfony', $output['event']['module']);
        self::assertSame('symfony.logs', $output['event']['dataset']);
        self::assertArrayHasKey('created', $output['event']);
        self::assertArrayHasKey('severity', $output['event']);

        self::assertArrayHasKey('log', $output);
        self::assertSame('info', $output['log']['level']);
        self::assertSame('app', $output['log']['logger']);
    }

    // --- Original context/extra are preserved alongside promoted namespaces ---

    public function testLabelsPromotedAndOriginalContextRetained(): void
    {
        $record = $this->createRecord(context: [
            'labels' => ['env' => 'prod'],
            'farm_id' => 42,
        ]);

        $output = $this->formatAndDecode($record);

        // Promoted to top-level
        self::assertArrayHasKey('labels', $output);
        self::assertSame(['env' => 'prod'], $output['labels']);

        // Original context retained in full — both non-extractable key AND the original labels entry
        self::assertArrayHasKey('context', $output);
        self::assertSame(42, $output['context']['farm_id']);
        self::assertSame(['env' => 'prod'], $output['context']['labels']);
    }

    public function testMetricPromotedAndOriginalContextRetained(): void
    {
        $record = $this->createRecord(context: [
            'metric' => ['duration_ms' => 150.0],
            'request_id' => 'req-abc',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('metric', $output);
        self::assertSame(150.0, $output['metric']['duration_ms']);

        // Original context retained in full — non-extractable key AND the original metric entry
        self::assertArrayHasKey('context', $output);
        self::assertSame('req-abc', $output['context']['request_id']);
        self::assertSame(['duration_ms' => 150.0], $output['context']['metric']);
    }

    public function testTagsPromotedAndOriginalContextRetained(): void
    {
        $record = $this->createRecord(context: [
            'tags' => ['web', 'api'],
            'user_id' => 7,
        ]);

        $output = $this->formatAndDecode($record);

        // Promoted to top-level
        self::assertSame(['web', 'api'], $output['tags']);

        // Original context retained in full — non-extractable key AND the original tags array
        self::assertSame(7, $output['context']['user_id']);
        self::assertSame(['web', 'api'], $output['context']['tags']);
    }

    public function testTextPromotedAndOriginalContextRetained(): void
    {
        $record = $this->createRecord(context: [
            'text' => ['note' => 'Sync completed successfully'],
            'job_id' => 'job-99',
        ]);

        $output = $this->formatAndDecode($record);

        // Promoted to top-level
        self::assertArrayHasKey('text', $output);
        self::assertSame(['note' => 'Sync completed successfully'], $output['text']);

        // Original context retained in full — non-extractable key AND the original text entry
        self::assertArrayHasKey('context', $output);
        self::assertSame('job-99', $output['context']['job_id']);
        self::assertSame(['note' => 'Sync completed successfully'], $output['context']['text']);
    }

    public function testExtraRemaindersRetainedInCopyMode(): void
    {
        $record = $this->createRecord(extra: [
            'metric' => ['items_count' => 5],
            'pid' => 1234,
        ]);

        $output = $this->formatAndDecode($record);

        // Promoted to top-level
        self::assertArrayHasKey('metric', $output);
        self::assertSame(5, $output['metric']['items_count']);

        // Original extra retained in full — non-extractable key AND the original metric entry
        self::assertArrayHasKey('extra', $output);
        self::assertSame(1234, $output['extra']['pid']);
        self::assertSame(['items_count' => 5], $output['extra']['metric']);
    }

    public function testContextAndExtraRetainedWhenOnlyNonExtractableKeys(): void
    {
        $record = $this->createRecord(
            context: ['user_id' => 99],
            extra: ['pid' => 777],
        );

        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('context', $output);
        self::assertSame(99, $output['context']['user_id']);
        self::assertArrayHasKey('extra', $output);
        self::assertSame(777, $output['extra']['pid']);
    }

    public function testMaxKeysPerNamespaceEnforcedInCopyMode(): void
    {
        // Build 10 labels (cap is 8) and 4 text entries (cap is 2).
        $labels = [];
        for ($i = 1; $i <= 10; $i++) {
            $labels["key$i"] = "val$i";
        }

        $text = [];
        for ($i = 1; $i <= 4; $i++) {
            $text["note$i"] = "text$i";
        }

        $record = $this->createRecord(context: [
            'labels' => $labels,
            'text' => $text,
        ]);

        $output = $this->formatAndDecode($record);

        // Top-level: overflow keys are dropped from the promoted namespace
        self::assertCount(8, $output['labels']);
        self::assertCount(2, $output['text']);

        // Copy semantics: the FULL original arrays are still present under context
        self::assertCount(10, $output['context']['labels']);
        self::assertCount(4, $output['context']['text']);
    }

    // --- service/error promotion in copy mode ---

    public function testServiceFromExtraPromotedToTopLevel(): void
    {
        $record = $this->createRecord(extra: [
            'service' => ['name' => 'my-service', 'language' => 'php'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('service', $output);
        self::assertSame('my-service', $output['service']['name']);
        self::assertSame('php', $output['service']['language']);
    }

    public function testErrorFromExtraPromotedToTopLevel(): void
    {
        $record = $this->createRecord(extra: [
            'error' => ['message' => 'Something failed', 'stack_trace' => '#0 file.php(1)'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('error', $output);
        self::assertSame('Something failed', $output['error']['message']);
        self::assertSame('#0 file.php(1)', $output['error']['stack_trace']);
    }

    public function testServiceAndErrorFromContextWinOverExtra(): void
    {
        $record = $this->createRecord(
            context: ['service' => ['name' => 'context-service']],
            extra: ['service' => ['name' => 'extra-service', 'language' => 'php']],
        );

        $output = $this->formatAndDecode($record);

        // context wins for overlapping key 'name'
        self::assertSame('context-service', $output['service']['name']);
        // extra's 'language' is kept via merge
        self::assertSame('php', $output['service']['language']);
    }

    public function testServiceAndErrorNotInContextOrExtraWhenAbsent(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertArrayNotHasKey('service', $output);
        self::assertArrayNotHasKey('error', $output);
    }

    // --- Output is single-line NDJSON ---

    public function testOutputIsSingleLineNdjson(): void
    {
        $record = $this->createRecord(
            message: 'BCMS upload DB error',
            context: [
                'labels' => ['herd' => 'IE123'],
                'farm_id' => 7,
            ],
            extra: [
                'service' => ['name' => 'my-service', 'language' => 'php'],
                'error' => ['message' => 'DB failed', 'stack_trace' => '#0 ...'],
            ],
        );

        $raw = $this->formatter->format($record);

        // Must end with a newline
        self::assertStringEndsWith("\n", $raw);

        // Must be a single JSON line (NDJSON)
        $lines = array_filter(explode("\n", $raw));
        self::assertCount(1, $lines);

        $output = json_decode($lines[0], true);
        self::assertIsArray($output);

        // Legacy fields present
        self::assertArrayHasKey('channel', $output);
        self::assertArrayHasKey('level_name', $output);
        self::assertArrayHasKey('level', $output);
        self::assertArrayHasKey('datetime', $output);

        // ECS fields present
        self::assertArrayHasKey('event', $output);
        self::assertArrayHasKey('log', $output);
        self::assertArrayHasKey('service', $output);
        self::assertArrayHasKey('error', $output);
        self::assertArrayHasKey('labels', $output);

        // Original context retained
        self::assertArrayHasKey('context', $output);
        self::assertSame(7, $output['context']['farm_id']);
    }

    public function testFormatBatchCopyModeProducesNdjson(): void
    {
        $records = [
            $this->createRecord('First'),
            $this->createRecord('Second'),
        ];

        $raw = $this->formatter->formatBatch($records);
        $lines = array_filter(explode("\n", $raw));

        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('channel', $decoded);
            self::assertArrayHasKey('event', $decoded);
        }
    }

    // --- No existing move-mode tests are affected (default is still move) ---

    public function testDefaultConstructorIsMoveMode(): void
    {
        $moveFormatter = new EcsFieldsFormatter();
        $record = $this->createRecord(level: Level::Warning, channel: 'app');

        $output = json_decode($moveFormatter->format($record), true);

        // Move mode must NOT have legacy top-level keys
        self::assertArrayNotHasKey('channel', $output);
        self::assertArrayNotHasKey('level_name', $output);
        self::assertArrayNotHasKey('level', $output);
        self::assertArrayNotHasKey('datetime', $output);

        // But must have ECS fields
        self::assertArrayHasKey('event', $output);
        self::assertArrayHasKey('log', $output);
    }
}
