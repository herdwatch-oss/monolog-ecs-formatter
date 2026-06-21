<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Formatter;

use Herdwatch\MonologEcsFormatter\Ecs\EcsField;
use Herdwatch\MonologEcsFormatter\Ecs\Key;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Monolog formatter that promotes typed {@see EcsField} value objects from a log's context/extra
 * to top-level ECS-aligned JSON fields, for clean Elasticsearch mapping.
 *
 * Promotion is driven entirely by the typed bags/identity objects (Metrics, Labels, Text, Tags,
 * Service, User, Tracing, EcsError and any project-specific EcsField). The objects are detected by
 * `instanceof` in the raw context/extra — the array key is irrelevant, so they may be passed
 * positionally or under any key. Ordinary (non-EcsField) context flows, un-promoted, into a
 * leftover `context` object; `extra` is emitted verbatim.
 *
 * Governed namespaces (labels, metric, text, tags) get key-name validation and a per-namespace cap
 * regardless of which EcsField produced them. Anything that can't be promoted is never dropped:
 *   - Move (default): demoted into the leftover `context` with a dotted key (e.g. `metric.bad key`).
 *   - Copy: the full original payload is mirrored under `context.<namespace>` for dashboard
 *     compatibility, and the legacy top-level keys (channel, level_name, level, datetime) are kept.
 *
 * A contributed fragment can never overwrite the base ECS skeleton (@timestamp, log.level, message,
 * ecs.version); contributions to `log`/`event` are merged additively with the base winning conflicts.
 *
 * Base fields emitted on every record: @timestamp, log.level, message, ecs.version, log.logger,
 * event.{kind,module,dataset,created,severity}.
 */
class EcsFieldsFormatter extends JsonFormatter
{
    /** ECS schema version advertised in the `ecs.version` field. */
    private const string ECS_VERSION = '8.11.0';

    /** Governed namespaces and their maximum promoted key counts. */
    private const array GOVERNED = [
        'labels' => 8,
        'metric' => 8,
        'text' => 2,
    ];

    private const int MAX_TAGS = 8;

    /** Base scalar fields a contributed fragment must never overwrite. */
    private const array PROTECTED_SCALARS = ['@timestamp', 'log.level', 'message', 'ecs.version'];

    /** Base object fields a fragment may extend additively, but never override (base wins conflicts). */
    private const array MERGE_UNDER_BASE = ['log', 'event'];

    public function __construct(
        private readonly EcsFormatMode $mode = EcsFormatMode::Move,
        int $batchMode = self::BATCH_MODE_NEWLINES,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = false,
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
        // ISO-8601 with microseconds — the conventional precision for an ECS @timestamp.
        $this->setDateFormat('Y-m-d\TH:i:s.uP');
    }

    public function format(LogRecord $record): string
    {
        // EcsField objects live in the raw context/extra. Pull them out BEFORE normalisation,
        // otherwise Monolog wraps each object as ['<FQCN>' => ...]. extra is scanned first and
        // context second, so an explicit context object wins over a processor-supplied one.
        $context = $record->context;
        $extra = $record->extra;

        $fragments = [];
        $this->pullFields($extra, $fragments);
        $this->pullFields($context, $fragments);

        $datetime = $this->formatDatetime($record);
        $output = $this->buildBaseFields($record, $datetime);

        // Leftover (non-EcsField) context is emitted as-is; bag overflow/demotions are added to it.
        $leftoverContext = $this->normalizeArray($context);
        $output = $this->mergeFragments($output, $fragments, $leftoverContext);

        if ($leftoverContext !== []) {
            $output['context'] = $leftoverContext;
        }

        $leftoverExtra = $this->normalizeArray($extra);
        if ($leftoverExtra !== []) {
            $output['extra'] = $leftoverExtra;
        }

        return $this->toJson($output) . "\n";
    }

    public function formatBatch(array $records): string
    {
        $output = '';

        foreach ($records as $record) {
            $output .= $this->format($record);
        }

        return $output;
    }

    /**
     * Lift every EcsField out of $bag (by reference) and deep-merge its fragment into $fragments.
     *
     * @param array<array-key, mixed> $bag       modified by reference — EcsField entries removed
     * @param array<string, mixed>    $fragments accumulator, keyed by ECS field path
     */
    private function pullFields(array &$bag, array &$fragments): void
    {
        foreach ($bag as $key => $value) {
            if (!$value instanceof EcsField) {
                continue;
            }

            foreach ($value->toEcs() as $field => $payload) {
                $normalized = $this->normalize($payload);
                $fragments[$field] = $this->deepMerge($fragments[$field] ?? [], $normalized);
            }

            unset($bag[$key]);
        }
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $fragments
     * @param array<string, mixed> $leftover  modified by reference — demotions/mirrors appended
     * @return array<string, mixed>
     */
    private function mergeFragments(array $output, array $fragments, array &$leftover): array
    {
        foreach ($fragments as $field => $value) {
            if ($field === 'tags') {
                [$promoted, $rejected] = $this->partitionTags($value);

                if ($promoted !== []) {
                    $output['tags'] = $promoted;
                }

                $this->placeLeftover('tags', $value, $rejected, $leftover);
            } elseif (isset(self::GOVERNED[$field])) {
                $payload = is_array($value) ? $value : [];
                [$promoted, $rejected] = $this->partitionNamespace($field, $payload);

                if ($promoted !== []) {
                    $output[$field] = $promoted;
                }

                $this->placeLeftover($field, $payload, $rejected, $leftover);
            } elseif (in_array($field, self::PROTECTED_SCALARS, true)) {
                continue;
            } elseif (in_array($field, self::MERGE_UNDER_BASE, true)) {
                // Additive: keep base values on conflict, but allow new sub-keys (e.g. log.origin).
                $output[$field] = $this->deepMerge($value, $output[$field] ?? []);
            } else {
                $output[$field] = $this->deepMerge($output[$field] ?? [], $value);
            }
        }

        return $output;
    }

    /**
     * Split a governed namespace into the promoted subset (valid key, within cap) and the rest.
     *
     * @param array<array-key, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [promoted, rejected]
     */
    private function partitionNamespace(string $namespace, array $payload): array
    {
        $promoted = [];
        $rejected = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            if (Key::isValid($key) && count($promoted) < self::GOVERNED[$namespace]) {
                $promoted[$key] = $value;
            } else {
                $rejected[$key] = $value;
            }
        }

        return [$promoted, $rejected];
    }

    /**
     * @return array{0: list<string>, 1: list<mixed>} [promoted, rejected]
     */
    private function partitionTags(mixed $value): array
    {
        $tags = is_array($value) ? array_values($value) : [$value];

        $promoted = [];
        $rejected = [];

        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                $rejected[] = $tag;
            } elseif (in_array($tag, $promoted, true)) {
                continue; // de-duplicate (not data loss)
            } elseif (count($promoted) >= self::MAX_TAGS) {
                $rejected[] = $tag;
            } else {
                $promoted[] = $tag;
            }
        }

        return [$promoted, $rejected];
    }

    /**
     * Never-drop policy for what couldn't be promoted.
     *   - Copy: mirror the full original payload under context.<namespace> for dashboard compatibility.
     *   - Move: keep only the rejected entries, as dotted keys, in the leftover context.
     *
     * @param mixed                $full     the complete fragment payload for this namespace
     * @param array<array-key, mixed> $rejected entries that were not promoted
     * @param array<string, mixed> $leftover modified by reference
     */
    private function placeLeftover(string $namespace, mixed $full, array $rejected, array &$leftover): void
    {
        if ($this->mode === EcsFormatMode::Copy) {
            if (is_array($full) && $full !== []) {
                $leftover[$namespace] = $full;
            }

            return;
        }

        if ($namespace === 'tags') {
            foreach ($rejected as $tag) {
                $leftover['tags'][] = $tag;
            }

            return;
        }

        foreach ($rejected as $key => $value) {
            $leftover["{$namespace}.{$key}"] = $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBaseFields(LogRecord $record, string $datetime): array
    {
        $levelName = $record->level->getName();

        $output = [
            '@timestamp' => $datetime,
            'log.level' => strtolower($levelName),
            'message' => $record->message,
            'ecs.version' => self::ECS_VERSION,
            'log' => ['logger' => $record->channel],
            'event' => [
                'kind' => 'event',
                'module' => 'symfony',
                'dataset' => 'symfony.logs',
                'created' => $datetime,
                'severity' => $record->level->value,
            ],
        ];

        if ($this->mode === EcsFormatMode::Copy) {
            $output['channel'] = $record->channel;
            $output['level_name'] = $levelName;
            $output['level'] = $record->level->value;
            $output['datetime'] = $datetime;
        }

        return $output;
    }

    private function formatDatetime(LogRecord $record): string
    {
        $normalized = $this->normalize($record->datetime);

        return is_string($normalized) ? $normalized : $record->datetime->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $normalized = $this->normalize($data);

        return is_array($normalized) ? $normalized : [];
    }

    private function deepMerge(mixed $a, mixed $b): mixed
    {
        if (is_array($a) && is_array($b)) {
            foreach ($b as $key => $value) {
                $a[$key] = array_key_exists($key, $a) ? $this->deepMerge($a[$key], $value) : $value;
            }

            return $a;
        }

        return $b;
    }
}
