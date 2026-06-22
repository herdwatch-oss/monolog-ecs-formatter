<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests;

use Herdwatch\MonologEcsFormatter\Ecs\Client;
use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
use Herdwatch\MonologEcsFormatter\Ecs\EcsField;
use Herdwatch\MonologEcsFormatter\Ecs\Event;
use Herdwatch\MonologEcsFormatter\Ecs\Http;
use Herdwatch\MonologEcsFormatter\Ecs\Labels;
use Herdwatch\MonologEcsFormatter\Ecs\Metrics;
use Herdwatch\MonologEcsFormatter\Ecs\Process;
use Herdwatch\MonologEcsFormatter\Ecs\SerializesToEcs;
use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Herdwatch\MonologEcsFormatter\Ecs\Tags;
use Herdwatch\MonologEcsFormatter\Ecs\Text;
use Herdwatch\MonologEcsFormatter\Ecs\Tracing;
use Herdwatch\MonologEcsFormatter\Ecs\Url;
use Herdwatch\MonologEcsFormatter\Ecs\User;
use Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter;
use Herdwatch\MonologEcsFormatter\Processor\EcsIdentityProcessor;
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
            datetime: new \DateTimeImmutable(),
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

    // --- Base skeleton + ECS-logging conformance ---

    public function testBaseSkeletonAndConformanceFields(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertArrayHasKey('@timestamp', $output);
        self::assertSame('info', $output['log.level']);
        self::assertSame('Test message', $output['message']);
        self::assertSame('8.11.0', $output['ecs.version']);
        self::assertSame(['logger' => 'app'], $output['log']);
        self::assertSame('event', $output['event']['kind']);
        self::assertSame('symfony', $output['event']['module']);
        self::assertSame('symfony.logs', $output['event']['dataset']);
        self::assertSame(Level::Info->value, $output['event']['severity']);
        self::assertArrayHasKey('created', $output['event']);
    }

    public function testEcsVersionDefaultsToConstant(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertSame(EcsFieldsFormatter::DEFAULT_ECS_VERSION, $output['ecs.version']);
    }

    public function testEcsVersionIsConfigurable(): void
    {
        $output = json_decode(
            (new EcsFieldsFormatter(ecsVersion: '9.0.0'))->format($this->createRecord()),
            true,
        );

        self::assertSame('9.0.0', $output['ecs.version']);
    }

    public function testTimestampUsesIso8601WithMicroseconds(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/',
            $output['@timestamp'],
        );
    }

    public function testFieldOrderFollowsSpec(): void
    {
        $output = $this->formatAndDecode($this->createRecord());
        $keys = array_keys($output);

        // @timestamp, log.level, message, ecs.version lead the record (ecs-logging spec order).
        self::assertSame(['@timestamp', 'log.level', 'message', 'ecs.version'], array_slice($keys, 0, 4));
    }

    public function testMoveModeHasNoLegacyTopLevelKeys(): void
    {
        $output = $this->formatAndDecode($this->createRecord(level: Level::Warning, channel: 'worker'));

        self::assertArrayNotHasKey('channel', $output);
        self::assertArrayNotHasKey('level_name', $output);
        self::assertArrayNotHasKey('level', $output);
        self::assertArrayNotHasKey('datetime', $output);
        self::assertSame('warning', $output['log.level']);
    }

    public function testOutputEndsWithNewline(): void
    {
        self::assertStringEndsWith("\n", $this->formatter->format($this->createRecord()));
    }

    public function testEmptyRecordProducesNoContextOrExtra(): void
    {
        $output = $this->formatAndDecode($this->createRecord());

        self::assertArrayNotHasKey('context', $output);
        self::assertArrayNotHasKey('extra', $output);
        self::assertArrayNotHasKey('labels', $output);
        self::assertArrayNotHasKey('metric', $output);
    }

    // --- Bag detection is by type, not key ---

    public function testLabelsBagPromotedWhenPassedPositionally(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Labels::create()->add('env', 'prod')->add('version', '1.2'),
        ]));

        self::assertSame(['env' => 'prod', 'version' => '1.2'], $output['labels']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testBagDetectedUnderAnyArrayKey(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            'whatever' => Labels::create()->add('env', 'prod'),
        ]));

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testMultipleBagsOfSameNamespaceMerge(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Labels::create()->add('env', 'prod'),
            Labels::create()->add('tenant', 'acme'),
        ]));

        self::assertSame(['env' => 'prod', 'tenant' => 'acme'], $output['labels']);
    }

    // --- Metric typing ---

    public function testMetricTypesArePreserved(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Metrics::create()
                ->count('retry_count', 3)
                ->total('events_total', 500)
                ->gauge('duration_ms', 12.5)
                ->flag('is_cached', true),
        ]));

        self::assertSame(3, $output['metric']['retry_count']);
        self::assertSame(500, $output['metric']['events_total']);
        self::assertSame(12.5, $output['metric']['duration_ms']);
        self::assertTrue($output['metric']['is_cached']);
    }

    // --- Text ---

    public function testTextBagPromoted(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Text::create()->add('reason', 'Missing field'),
        ]));

        self::assertSame(['reason' => 'Missing field'], $output['text']);
    }

    // --- Tags ---

    public function testTagsBagPromotedAndDeduplicated(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Tags::of('web', 'api', 'web'),
        ]));

        self::assertSame(['web', 'api'], $output['tags']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testTagsOverCapPreservedInContext(): void
    {
        $tags = [];
        for ($i = 1; $i <= 10; $i++) {
            $tags[] = "tag$i";
        }

        $output = $this->formatAndDecode($this->createRecord(context: [Tags::of(...$tags)]));

        self::assertCount(8, $output['tags']);
        self::assertSame(['tag9', 'tag10'], $output['context']['tags']);
    }

    // --- Plain context flows through, never promoted ---

    public function testPlainContextStaysUnderContext(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Labels::create()->add('env', 'prod'),
            'user_id' => 42,
            'request_path' => '/api/v1/sync',
        ]));

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame(['user_id' => 42, 'request_path' => '/api/v1/sync'], $output['context']);
    }

    // --- Never-drop: invalid key / over-cap demoted to dotted context keys (move mode) ---

    public function testInvalidKeyDemotedToContextWithDottedKey(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Labels::create()->add('env', 'prod')->add('Bad Key', 'kept'),
        ]));

        self::assertSame(['env' => 'prod'], $output['labels']);
        self::assertSame('kept', $output['context']['labels.Bad Key']);
    }

    public function testOverCapDemotedToContextWithDottedKeys(): void
    {
        $labels = Labels::create();
        for ($i = 1; $i <= 10; $i++) {
            $labels->add("key$i", "val$i");
        }

        $output = $this->formatAndDecode($this->createRecord(context: [$labels]));

        self::assertCount(8, $output['labels']);
        self::assertSame('val9', $output['context']['labels.key9']);
        self::assertSame('val10', $output['context']['labels.key10']);
    }

    // --- Identity types ---

    public function testServiceUserTracingPromoted(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            new Service('billing', version: '1.4.0', environment: 'prod'),
            new User(id: 42, email: 'farmer@example.com'),
            new Tracing('trace-abc', 'txn-xyz'),
        ]));

        self::assertSame(['name' => 'billing', 'language' => 'php', 'version' => '1.4.0', 'environment' => 'prod'], $output['service']);
        self::assertSame(['id' => 42, 'email' => 'farmer@example.com'], $output['user']);
        self::assertSame(['id' => 'trace-abc'], $output['trace']);
        self::assertSame(['id' => 'txn-xyz'], $output['transaction']);
    }

    public function testEcsErrorPromotedWithTypeAndCode(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            new EcsError(new \RuntimeException('boom', 7)),
        ]));

        self::assertSame('RuntimeException', $output['error']['type']);
        self::assertSame('boom', $output['error']['message']);
        self::assertSame('7', $output['error']['code']);
        self::assertIsString($output['error']['stack_trace']);
    }

    public function testContextExceptionPromotedToErrorAndStripped(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            'exception' => new \RuntimeException('db down', 42),
        ]));

        self::assertSame('RuntimeException', $output['error']['type']);
        self::assertSame('db down', $output['error']['message']);
        self::assertSame('42', $output['error']['code']);
        // Move mode strips the consumed exception — no duplicate under context.
        self::assertArrayNotHasKey('context', $output);
    }

    public function testNonThrowableExceptionStaysInContext(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            'exception' => 'just a string',
        ]));

        self::assertArrayNotHasKey('error', $output);
        self::assertSame('just a string', $output['context']['exception']);
    }

    public function testExplicitEcsErrorWinsOverContextException(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            new EcsError(new \LogicException('explicit')),
            'exception' => new \RuntimeException('raw'),
        ]));

        // The explicit EcsError owns error.*.
        self::assertSame('explicit', $output['error']['message']);
        // The raw context exception can't claim error.*, but it must not be silently dropped:
        // it is preserved (Monolog-normalised) under context.
        self::assertSame('RuntimeException', $output['context']['exception']['class']);
        self::assertSame('raw', $output['context']['exception']['message']);
    }

    // --- extra ---

    public function testExtraEmittedVerbatimAndNotScannedForArrays(): void
    {
        $output = $this->formatAndDecode($this->createRecord(extra: [
            'metric' => ['duration_ms' => 50],
            'pid' => 1234,
        ]));

        self::assertArrayNotHasKey('metric', $output);
        self::assertSame(['duration_ms' => 50], $output['extra']['metric']);
        self::assertSame(1234, $output['extra']['pid']);
    }

    public function testEcsFieldInExtraIsPromoted(): void
    {
        // Mirrors what EcsIdentityProcessor does — inject objects into extra.
        $output = $this->formatAndDecode($this->createRecord(extra: [
            'service' => new Service('worker-svc'),
        ]));

        self::assertSame('worker-svc', $output['service']['name']);
        self::assertArrayNotHasKey('extra', $output);
    }

    public function testContextWinsOverExtraForSameNamespace(): void
    {
        $output = $this->formatAndDecode($this->createRecord(
            context: [new Service('context-svc')],
            extra: ['service' => new Service('extra-svc')],
        ));

        self::assertSame('context-svc', $output['service']['name']);
    }

    public function testServiceBagsUnderDistinctExtraKeysAreMerged(): void
    {
        // A second processor can enrich service.* by emitting another Service under a DIFFERENT key:
        // the formatter pulls every EcsField by type and deep-merges by namespace, so all fields land
        // in one service object.
        $output = $this->formatAndDecode($this->createRecord(extra: [
            'service' => new Service('billing'),                                        // e.g. EcsIdentityProcessor
            'svc_extra' => new Service('billing', version: '2.0', environment: 'prod'), // a project processor
        ]));

        self::assertSame(
            ['name' => 'billing', 'language' => 'php', 'version' => '2.0', 'environment' => 'prod'],
            $output['service'],
        );
    }

    public function testServicesUnderTheSameExtraKeyDoNotMergeOnlyTheLastSurvives(): void
    {
        // Two Service objects can't co-exist under one key — the array overwrites before the
        // formatter sees it, so they do NOT merge. Distinct keys (above) are required to combine them.
        $extra = ['service' => new Service('first', version: '1.0')];
        $extra['service'] = new Service('second'); // a processor reusing the 'service' key

        $output = $this->formatAndDecode($this->createRecord(extra: $extra));

        self::assertSame(['name' => 'second', 'language' => 'php'], $output['service']);
    }

    public function testEcsIdentityProcessorServiceMergesWithAProjectServiceBag(): void
    {
        // EcsIdentityProcessor adds extra['service']; a project processor had already added its own
        // Service under a different key. Both are promoted and merged into one service object — on a
        // key clash the later-scanned 'service' (the processor's) wins, the project's extra fields survive.
        $record = (new EcsIdentityProcessor('identity-svc'))(
            $this->createRecord(extra: ['app_service' => new Service('app', version: '4.0', environment: 'prod')]),
        );

        $output = json_decode($this->formatter->format($record), true);

        self::assertSame(
            ['name' => 'identity-svc', 'language' => 'php', 'version' => '4.0', 'environment' => 'prod'],
            $output['service'],
        );
    }

    public function testPositionalServiceBagsInExtraMergeWithoutColliding(): void
    {
        // Detection is by type, not key — positional appends ($extra[] = …) from different processors
        // get distinct integer indices, so none clobbers another (unlike reusing 'service'); all merge.
        $extra = [];
        $extra[] = new Service('billing');                                      // processor A
        $extra[] = new Service('billing', version: '2.0', environment: 'prod'); // processor B

        $output = $this->formatAndDecode($this->createRecord(extra: $extra));

        self::assertSame(
            ['name' => 'billing', 'language' => 'php', 'version' => '2.0', 'environment' => 'prod'],
            $output['service'],
        );
        self::assertArrayNotHasKey('extra', $output); // both pulled out — nothing left behind
    }

    public function testKeyedAndPositionalServiceBagsBothMerge(): void
    {
        // A keyed bag (e.g. EcsIdentityProcessor's extra['service']) and a positionally-appended one
        // are both detected by type and merged into a single service object.
        $extra = ['service' => new Service('billing')];
        $extra[] = new Service('billing', version: '3.0');

        $output = $this->formatAndDecode($this->createRecord(extra: $extra));

        self::assertSame(['name' => 'billing', 'language' => 'php', 'version' => '3.0'], $output['service']);
    }

    // --- Project-specific EcsField is auto-detected ---

    public function testCustomEcsFieldIsPromotedAutomatically(): void
    {
        $farm = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['farm' => ['herd_id' => 1234, 'region' => 'munster']];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(context: [$farm]));

        self::assertSame(['herd_id' => 1234, 'region' => 'munster'], $output['farm']);
    }

    public function testBaseSkeletonIsProtectedFromFragments(): void
    {
        $malicious = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return [
                    '@timestamp' => 'HACK',
                    'message' => 'HACK',
                    'log.level' => 'HACK',
                    'ecs.version' => 'HACK',
                    'log' => ['logger' => 'HACK', 'origin' => ['file' => ['name' => 'x.php']]],
                    'event' => ['kind' => 'HACK', 'action' => 'custom'],
                ];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(message: 'real', context: [$malicious]));

        // Base scalars cannot be overwritten.
        self::assertNotSame('HACK', $output['@timestamp']);
        self::assertSame('real', $output['message']);
        self::assertSame('info', $output['log.level']);
        self::assertSame('8.11.0', $output['ecs.version']);

        // log/event: base values win on conflict, but new sub-keys are allowed.
        self::assertSame('app', $output['log']['logger']);
        self::assertSame('x.php', $output['log']['origin']['file']['name']);
        self::assertSame('event', $output['event']['kind']);
        self::assertSame('custom', $output['event']['action']);
    }

    // --- Regression: list merge, never-drop, base protection, newline ---

    public function testMultipleTagsBagsConcatenate(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            Tags::of('a', 'b'),
            Tags::of('c', 'd'),
        ]));

        self::assertSame(['a', 'b', 'c', 'd'], $output['tags']);
    }

    public function testTagsOverflowDoesNotCrashWhenContextHasScalarTags(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            'tags' => 'web',
            Tags::of('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'),
        ]));

        self::assertCount(8, $output['tags']);
        // Never-drop: the plain scalar 'tags' and the overflow tag both survive under context.tags.
        self::assertSame(['web', 'i'], $output['context']['tags']);
    }

    public function testNonArrayGovernedFragmentIsDemotedNotDropped(): void
    {
        $scalarMetric = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['metric' => 42];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(context: [$scalarMetric]));

        self::assertArrayNotHasKey('metric', $output);
        self::assertSame(42, $output['context']['metric']);
    }

    public function testNestedLogLevelFromFragmentIsStripped(): void
    {
        $logFragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['log' => ['level' => 'HACK', 'origin' => ['file' => ['name' => 'x.php']]]];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(context: [$logFragment]));

        self::assertSame('info', $output['log.level']);
        self::assertArrayNotHasKey('level', $output['log']);
        self::assertSame('app', $output['log']['logger']);
        self::assertSame('x.php', $output['log']['origin']['file']['name']);
    }

    public function testFragmentNamedContextIsNestedUnderContextNotClobberingBucket(): void
    {
        // `context` is a reserved output bucket, not an ECS field. A fragment using it is demoted
        // (nested under context.context), preserving its data without corrupting the bucket.
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['context' => ['injected' => 'frag']];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(context: [$fragment, 'plain' => 'value']));

        self::assertSame(['injected' => 'frag'], $output['context']['context']);
        self::assertSame('value', $output['context']['plain']);
    }

    public function testFragmentNamedExtraIsNestedUnderContextLeavingRealExtraIntact(): void
    {
        // `extra` is likewise reserved (Monolog's verbatim bucket); a fragment targeting it is
        // demoted under context.extra and the real extra bucket is untouched.
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['extra' => ['injected' => 'frag']];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(
            context: [$fragment],
            extra: ['real_extra' => 1],
        ));

        self::assertSame(['injected' => 'frag'], $output['context']['extra']);
        self::assertSame(['real_extra' => 1], $output['extra']);
    }

    public function testNestedEcsVersionFromFragmentIsStripped(): void
    {
        // Symmetric to the log.level case: the base owns the dotted ecs.version, so a nested
        // ecs.version contribution is stripped to avoid a duplicate field; siblings pass through.
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['ecs' => ['version' => '9.9.9', 'custom' => 'kept']];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(context: [$fragment]));

        self::assertSame('8.11.0', $output['ecs.version']);
        self::assertSame(['custom' => 'kept'], $output['ecs']);
    }

    public function testNestedEcsVersionOnlyFragmentEmitsNoEcsObject(): void
    {
        // A fragment whose only contribution was the stripped ecs.version adds nothing — no empty
        // `ecs` object, no demotion.
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['ecs' => ['version' => '9.9.9']];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(context: [$fragment]));

        self::assertSame('8.11.0', $output['ecs.version']);
        self::assertArrayNotHasKey('ecs', $output);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testProtectedScalarFragmentYieldsToCollidingPlainContextKey(): void
    {
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['message' => 'from-fragment'];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(
            context: [$fragment, 'message' => 'from-context'],
        ));

        // A plain context key of the same name wins; the base top-level message is still protected.
        self::assertSame('Test message', $output['message']);
        self::assertSame('from-context', $output['context']['message']);
    }

    public function testDemotionRespectsAnExistingNullPlainContextKey(): void
    {
        // A plain context key set to null still counts as "logged" — first-write precedence keeps
        // it rather than letting the demoted fragment value replace it (array_key_exists, not ??=).
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['message' => 'from-fragment'];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(
            context: [$fragment, 'message' => null],
        ));

        self::assertNull($output['context']['message']);
    }

    public function testProtectedScalarFragmentIsDemotedNotDropped(): void
    {
        $fragment = new class () implements EcsField {
            use SerializesToEcs;

            public function toEcs(): array
            {
                return ['message' => 'OVERRIDE', 'ecs.version' => '0'];
            }
        };

        $output = $this->formatAndDecode($this->createRecord(message: 'real', context: [$fragment]));

        // Base scalars are not overwritten...
        self::assertSame('real', $output['message']);
        self::assertSame('8.11.0', $output['ecs.version']);
        // ...but the contributed values are demoted to context rather than silently dropped.
        self::assertSame('OVERRIDE', $output['context']['message']);
        self::assertSame('0', $output['context']['ecs.version']);
    }

    public function testAppendNewlineFalseOmitsTrailingNewline(): void
    {
        $formatter = new EcsFieldsFormatter(appendNewline: false);

        self::assertStringEndsNotWith("\n", $formatter->format($this->createRecord()));
        self::assertStringEndsNotWith("\n", $formatter->formatBatch([$this->createRecord(), $this->createRecord()]));
    }

    // --- Standard ECS context types ---

    public function testStandardEcsContextTypesPromotedToTopLevel(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            new Http(statusCode: 503, method: 'get'),
            new Process(pid: 99, commandLine: 'bin/console app:run'),
            new Client(ip: '203.0.113.7'),
            Url::parse('https://app.herdwatch.com/herds/42?view=summary'),
        ]));

        self::assertSame(503, $output['http']['response']['status_code']);
        self::assertSame('GET', $output['http']['request']['method']);
        self::assertSame(99, $output['process']['pid']);
        self::assertSame('203.0.113.7', $output['client']['ip']);
        self::assertSame('app.herdwatch.com', $output['url']['domain']);
        self::assertSame('/herds/42', $output['url']['path']);
        self::assertSame('view=summary', $output['url']['query']);
        self::assertArrayNotHasKey('context', $output);
    }

    public function testEventFragmentMergesWithBaseEventAndFormatsStart(): void
    {
        $output = $this->formatAndDecode($this->createRecord(context: [
            new Event(action: 'farm.sync', start: new \DateTimeImmutable('2026-06-21T11:59:59.250000+00:00')),
        ]));

        self::assertSame('farm.sync', $output['event']['action']);
        self::assertSame('event', $output['event']['kind']);            // base preserved
        self::assertSame('symfony.logs', $output['event']['dataset']);  // base preserved
        self::assertSame('2026-06-21T11:59:59.250000+00:00', $output['event']['start']);
    }

    // --- Batch ---

    public function testFormatBatchProducesNdjson(): void
    {
        $raw = $this->formatter->formatBatch([
            $this->createRecord('First'),
            $this->createRecord("Second\nwith newline"),
        ]);

        $lines = array_filter(explode("\n", $raw));
        self::assertCount(2, $lines);

        self::assertSame('First', json_decode($lines[0], true)['message']);
        self::assertSame("Second\nwith newline", json_decode($lines[1], true)['message']);
    }
}
