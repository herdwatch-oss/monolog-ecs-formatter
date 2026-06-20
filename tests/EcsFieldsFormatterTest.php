<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests;

use Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class EcsFieldsFormatterTest extends TestCase
{
    private EcsFieldsFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new EcsFieldsFormatter();
    }

    private function createRecord(
        string $message = 'Test message',
        array $context = [],
        array $extra = [],
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    private function formatAndDecode(LogRecord $record): array
    {
        return json_decode($this->formatter->format($record), true);
    }

    // --- Base structure ---

    public function testBaseFieldsArePresent(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertSame('Test message', $output['message']);
        self::assertSame(['level' => 'info', 'logger' => 'app'], $output['log']);
        self::assertSame('event', $output['event']['kind']);
        self::assertSame('symfony', $output['event']['module']);
        self::assertSame('symfony.logs', $output['event']['dataset']);
        self::assertArrayHasKey('created', $output['event']);
        self::assertArrayHasKey('severity', $output['event']);
    }

    public function testOutputEndsWithNewline(): void
    {
        $raw = $this->formatter->format($this->createRecord());

        self::assertStringEndsWith("\n", $raw);
    }

    // --- Extraction of known keys ---

    public function testLabelsExtractedFromContext(): void
    {
        $record = $this->createRecord(context: [
            'labels' => ['env' => 'prod', 'version' => '1.2'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['env' => 'prod', 'version' => '1.2'], $output['labels']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testLabelsExtractedFromExtra(): void
    {
        $record = $this->createRecord(extra: [
            'labels' => ['source' => 'processor'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['source' => 'processor'], $output['labels']);
        self::assertArrayNotHasKey('extra', $output);
    }

    public function testContextLabelsOverrideExtraLabels(): void
    {
        $record = $this->createRecord(
            context: ['labels' => ['env' => 'prod']],
            extra: ['labels' => ['env' => 'staging', 'source' => 'processor']],
        );

        $output = $this->formatAndDecode($record);

        // context wins for overlapping key 'env', extra's 'source' is kept via array_merge
        self::assertSame('prod', $output['labels']['env']);
        self::assertSame('processor', $output['labels']['source']);
    }

    public function testCoercibleKeysArePromoted(): void
    {
        $record = $this->createRecord(context: [
            'labels' => ['k' => 'v'],
            'metric' => ['duration_ms' => 100, 'is_retry' => true],
            'text' => ['note' => 'hello'],
        ]);

        $output = $this->formatAndDecode($record);

        foreach (['labels', 'metric', 'text'] as $key) {
            self::assertArrayHasKey($key, $output, "Expected top-level key '$key'");
        }
        self::assertArrayNotHasKey('context', $output);
    }

    // --- Remaining keys stay in place ---

    public function testNonExtractableContextKeysStayUnderContext(): void
    {
        $record = $this->createRecord(context: [
            'labels' => ['env' => 'prod'],
            'user_id' => 42,
            'request_path' => '/api/v1/sync',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('labels', $output);
        self::assertSame(['user_id' => 42, 'request_path' => '/api/v1/sync'], $output['context']);
    }

    public function testNonExtractableExtraKeysStayUnderExtra(): void
    {
        $record = $this->createRecord(extra: [
            'metric' => ['duration_ms' => 50],
            'pid' => 1234,
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayHasKey('metric', $output);
        self::assertSame(['pid' => 1234], $output['extra']);
    }

    public function testBothContextAndExtraRemaindersPreserved(): void
    {
        $record = $this->createRecord(
            context: ['labels' => ['env' => 'prod'], 'user_id' => 42],
            extra: ['labels' => ['source' => 'proc'], 'pid' => 1234],
        );

        $output = $this->formatAndDecode($record);

        self::assertSame(['user_id' => 42], $output['context']);
        self::assertSame(['pid' => 1234], $output['extra']);
    }

    public function testNoContextOrExtraKeyWhenNothingRemains(): void
    {
        $record = $this->createRecord(
            context: ['labels' => ['env' => 'prod']],
            extra: ['text' => ['note' => 'hello']],
        );

        $output = $this->formatAndDecode($record);

        self::assertArrayNotHasKey('context', $output);
        self::assertArrayNotHasKey('extra', $output);
    }

    // --- Coercion: labels ---

    public function testLabelValuesCoercedToString(): void
    {
        $record = $this->createRecord(context: [
            'labels' => ['count' => 42, 'active' => true, 'ratio' => 3.14],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame('42', $output['labels']['count']);
        self::assertSame('1', $output['labels']['active']);
        self::assertSame('3.14', $output['labels']['ratio']);
    }

    public function testLabelsNestedValuesStayInContext(): void
    {
        $record = $this->createRecord(context: [
            'labels' => [
                'env' => 'prod',
                'tags' => ['web', 'api'],
                'meta' => ['nested' => 'value'],
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame(['web', 'api'], $output['context']['labels']['tags']);
        self::assertSame(['nested' => 'value'], $output['context']['labels']['meta']);
    }

    // --- Coercion: metric ---

    public function testMetricLongSuffixesCastToInt(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'retry_count' => '3',
                'items_count' => '7',
                'batch_count' => 7.9,
                'events_total' => '100',
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(3, $output['metric']['retry_count']);
        self::assertSame(7, $output['metric']['items_count']);
        self::assertSame(100, $output['metric']['events_total']);

        // 7.9 is not an integer — _count requires whole numbers, so it stays in context
        self::assertArrayNotHasKey('batch_count', $output['metric']);
        self::assertSame(7.9, $output['context']['metric']['batch_count']);
    }

    public function testMetricNumericValuesDefaultToDouble(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'duration_ms' => '150.5',
                'latency_ms' => '0.85',
                'success_rate' => '12.5',
                'payload_bytes' => 2048,
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(150.5, $output['metric']['duration_ms']);
        self::assertSame(0.85, $output['metric']['latency_ms']);
        self::assertSame(12.5, $output['metric']['success_rate']);

        // _bytes is not a long suffix, so it defaults to double
        self::assertSame(2048.0, $output['metric']['payload_bytes']);
    }

    public function testMetricUnrecognizedSuffixDefaultsToDouble(): void
    {
        $record = $this->createRecord(context: [
            'metric' => ['custom_value' => '42'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(42.0, $output['metric']['custom_value']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testMetricNonNumericStringsStayInContext(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'duration_ms' => 'not-a-number',
                'items_count' => 'abc',
                'success_rate' => 'N/A',
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayNotHasKey('metric', $output);
        self::assertSame('not-a-number', $output['context']['metric']['duration_ms']);
        self::assertSame('abc', $output['context']['metric']['items_count']);
        self::assertSame('N/A', $output['context']['metric']['success_rate']);
    }

    public function testMetricNullValuesStayInContext(): void
    {
        $record = $this->createRecord(context: [
            'metric' => ['duration_ms' => null, 'items_count' => 5],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertCount(1, $output['metric']);
        self::assertSame(5, $output['metric']['items_count']);

        self::assertArrayNotHasKey('duration_ms', $output['metric']);
        self::assertNull($output['context']['metric']['duration_ms']);
    }

    // --- Coercion: text ---

    public function testTextValuesCoercedToString(): void
    {
        $record = $this->createRecord(context: [
            'text' => ['code' => 404, 'active' => true],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame('404', $output['text']['code']);
        self::assertSame('1', $output['text']['active']);
    }

    public function testTextNestedValuesStayInContext(): void
    {
        $record = $this->createRecord(context: [
            'text' => [
                'summary' => 'All good',
                'payload' => ['key' => 'value'],
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['summary' => 'All good'], $output['text']);
        self::assertSame(['key' => 'value'], $output['context']['text']['payload']);
    }

    public function testMetricNestedValuesStayInContext(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'duration_ms' => 150,
                'breakdown' => ['read_ms' => 50, 'write_ms' => 100],
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertCount(1, $output['metric']);
        self::assertSame(150.0, $output['metric']['duration_ms']);

        self::assertSame(['read_ms' => 50, 'write_ms' => 100], $output['context']['metric']['breakdown']);
    }

    // --- formatBatch ---

    public function testFormatBatchConcatenatesRecords(): void
    {
        $records = [
            $this->createRecord('First'),
            $this->createRecord('Second'),
        ];

        $raw = $this->formatter->formatBatch($records);
        $lines = array_filter(explode("\n", $raw));

        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertSame('First', $first['message']);
        self::assertSame('Second', $second['message']);
    }

    public function testFormatBatchWithNewlinesInMessage(): void
    {
        $records = [
            $this->createRecord("Line one\nLine two\nLine three"),
            $this->createRecord('Simple message'),
        ];

        $raw = $this->formatter->formatBatch($records);

        // Each record must be valid JSON on a single line (NDJSON)
        $lines = array_filter(explode("\n", $raw));

        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertSame("Line one\nLine two\nLine three", $first['message']);
        self::assertSame('Simple message', $second['message']);
    }

    // --- Empty context and extra ---

    public function testEmptyContextAndExtraProduceCleanOutput(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertArrayNotHasKey('context', $output);
        self::assertArrayNotHasKey('extra', $output);
        self::assertArrayNotHasKey('labels', $output);
        self::assertArrayNotHasKey('metric', $output);
    }

    // --- Key hygiene ---

    public function testKeyFormatValidation(): void
    {
        $record = $this->createRecord(context: [
            'labels' => [
                'env' => 'prod',
                'User_Id' => 'x',
                '123_code' => 'y',
                'a_b_c_d' => 'z',
            ],
            'text' => [
                'note' => 'hello',
                'SHOUT' => 'world',
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame(['note' => 'hello'], $output['text']);

        self::assertSame('x', $output['context']['labels']['User_Id']);
        self::assertSame('y', $output['context']['labels']['123_code']);
        self::assertSame('z', $output['context']['labels']['a_b_c_d']);
        self::assertSame('world', $output['context']['text']['SHOUT']);
    }

    public function testMetricKeyFormatRejectsInvalidKeys(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'duration_ms' => 100.0,
                'InvalidKey_ms' => 50.0,
                '123_count' => 30,
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertCount(1, $output['metric']);
        self::assertSame(100.0, $output['metric']['duration_ms']);

        self::assertSame(50.0, $output['context']['metric']['InvalidKey_ms']);
        self::assertSame(30, $output['context']['metric']['123_count']);
    }

    public function testMaxKeysPerNamespaceEnforced(): void
    {
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

        self::assertCount(8, $output['labels']);
        self::assertCount(2, $output['text']);

        self::assertCount(2, $output['context']['labels']);
        self::assertCount(2, $output['context']['text']);
    }

    // --- Dot-notation unflattening ---

    public function testDotNotationLabelsUnflattenedFromContext(): void
    {
        $record = $this->createRecord(context: [
            'labels.env' => 'prod',
            'labels.version' => '1.2',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['env' => 'prod', 'version' => '1.2'], $output['labels']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testDotNotationMetricUnflattenedFromContext(): void
    {
        $record = $this->createRecord(context: [
            'metric.duration_ms' => 150.5,
            'metric.items_count' => 42,
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(150.5, $output['metric']['duration_ms']);
        self::assertSame(42, $output['metric']['items_count']);

        self::assertArrayNotHasKey('context', $output);
    }

    public function testDotNotationMergesWithExistingNestedKey(): void
    {
        $record = $this->createRecord(context: [
            'labels' => ['env' => 'prod'],
            'labels.version' => '1.2',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame('prod', $output['labels']['env']);
        self::assertSame('1.2', $output['labels']['version']);
    }

    public function testDotNotationIgnoresNonExtractablePrefixes(): void
    {
        $record = $this->createRecord(context: [
            'custom.key' => 'value',
            'labels.env' => 'prod',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame('value', $output['context']['custom.key']);
    }

    public function testDotNotationIgnoresExtraDots(): void
    {
        $record = $this->createRecord(context: [
            'labels.nested.key' => 'value',
            'metric.deep.nested.key' => 42,
            'labels.env' => 'prod',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame('value', $output['context']['labels.nested.key']);
        self::assertSame(42, $output['context']['metric.deep.nested.key']);
    }

    public function testDotNotationContextWinsOverExtra(): void
    {
        $record = $this->createRecord(
            context: ['labels.env' => 'prod'],
            extra: ['labels.env' => 'staging', 'labels.source' => 'processor'],
        );

        $output = $this->formatAndDecode($record);

        self::assertSame('prod', $output['labels']['env']);
        self::assertSame('processor', $output['labels']['source']);
    }

    public function testAllMetricTypesAreAccepted(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'duration_ms' => 100.5,
                'latency_ms' => 50.0,
                'items_count' => 42,
                'retry_count' => 3,
                'events_total' => 500,
                'success_rate' => 0.95,
                'is_retry' => true,
                'is_cached' => false,
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertCount(8, $output['metric']);

        self::assertSame(100.5, $output['metric']['duration_ms']);
        self::assertSame(50.0, $output['metric']['latency_ms']);
        self::assertSame(42, $output['metric']['items_count']);
        self::assertSame(3, $output['metric']['retry_count']);
        self::assertSame(500, $output['metric']['events_total']);
        self::assertSame(0.95, $output['metric']['success_rate']);
        self::assertTrue($output['metric']['is_retry']);
        self::assertFalse($output['metric']['is_cached']);

        self::assertArrayNotHasKey('context', $output);
    }

    public function testMetricBooleanPrefixCastToBool(): void
    {
        $record = $this->createRecord(context: [
            'metric' => [
                'is_retry' => 1,
                'is_cached' => '',
                'is_active' => 'yes',
            ],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertCount(3, $output['metric']);

        self::assertTrue($output['metric']['is_retry']);
        self::assertFalse($output['metric']['is_cached']);
        self::assertTrue($output['metric']['is_active']);
    }

    // --- Tags (ECS base field) ---

    public function testTagsExtractedFromContext(): void
    {
        $record = $this->createRecord(context: [
            'tags' => ['web', 'production'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['web', 'production'], $output['tags']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testTagsExtractedFromExtra(): void
    {
        $record = $this->createRecord(extra: [
            'tags' => ['background'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['background'], $output['tags']);
        self::assertArrayNotHasKey('extra', $output);
    }

    public function testTagsMergedAndDeduplicated(): void
    {
        $record = $this->createRecord(
            context: ['tags' => ['web', 'production']],
            extra: ['tags' => ['web', 'internal']],
        );

        $output = $this->formatAndDecode($record);

        self::assertContains('web', $output['tags']);
        self::assertContains('production', $output['tags']);
        self::assertContains('internal', $output['tags']);
        self::assertCount(3, $output['tags']);
    }

    public function testTagsNonStringValuesStayInRemainder(): void
    {
        $record = $this->createRecord(context: [
            'tags' => ['valid', 42, '', ['nested'], null, 'also_valid'],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertSame(['valid', 'also_valid'], $output['tags']);
        self::assertSame([42, '', ['nested'], null], $output['context']['tags']);
    }

    public function testTagsMaxEnforced(): void
    {
        $tags = [];
        for ($i = 1; $i <= 10; $i++) {
            $tags[] = "tag$i";
        }

        $record = $this->createRecord(context: ['tags' => $tags]);

        $output = $this->formatAndDecode($record);

        self::assertCount(8, $output['tags']);
        self::assertCount(2, $output['context']['tags']);
    }

    public function testTagsScalarValueWrappedInRemainder(): void
    {
        $record = $this->createRecord(context: [
            'tags' => 'not-an-array',
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayNotHasKey('tags', $output);
        self::assertSame(['not-an-array'], $output['context']['tags']);
    }

    public function testEmptyTagsArrayOmitted(): void
    {
        $record = $this->createRecord(context: [
            'tags' => [],
        ]);

        $output = $this->formatAndDecode($record);

        self::assertArrayNotHasKey('tags', $output);
        self::assertArrayNotHasKey('context', $output);
    }

    // --- Dot-notation: flags ---

    public function testDotNotationFlagsNoLongerExtracted(): void
    {
        $record = $this->createRecord(
            context: ['flags.is_retry' => true],
            extra: ['flags.debug' => false],
        );

        $output = $this->formatAndDecode($record);

        self::assertArrayNotHasKey('flags', $output);
        self::assertTrue($output['context']['flags.is_retry']);
        self::assertFalse($output['extra']['flags.debug']);
    }
}
