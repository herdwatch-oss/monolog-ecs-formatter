<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Formatter;

use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
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
 * leftover `context` object; `extra` is emitted verbatim. A `\Throwable` at `context['exception']`
 * (the Monolog convention) is promoted to `error.*` and removed from `context` — unless an explicit
 * `EcsError` already owns `error.*`, in which case the throwable is left in `context` (never dropped,
 * never double-promoted).
 *
 * Governed namespaces (labels, metric, text, tags) get key-name validation and a per-namespace cap
 * regardless of which EcsField produced them, and are always promoted to top-level. Anything that
 * can't be promoted is never dropped: it is demoted into the leftover `context` with a dotted key
 * (e.g. `metric.bad key`).
 *
 * The {@see EcsFormatMode} `mode` controls one thing only: whether the legacy Monolog top-level keys
 * (channel, level_name, level, datetime) are also emitted. Copy (the default) keeps them, so dashboards
 * querying the old keys keep working when the bundle is dropped into an existing app; Move drops them
 * for a clean ECS-only shape once migration is complete. Promotion of namespaces and identity fields
 * is identical in both modes.
 *
 * A contributed fragment can never overwrite the base ECS skeleton (@timestamp, log.level, message,
 * ecs.version), whether expressed as a top-level dotted key or nested — a nested `log.level` or
 * `ecs.version` is stripped from a `log`/`ecs` fragment to avoid a duplicate field. Contributions to
 * `log`/`event` are otherwise merged additively with the base winning conflicts. The reserved output
 * buckets `context`/`extra` are not ECS fields: a fragment that targets them is demoted under
 * `context` rather than driving the bucket.
 *
 * Base fields emitted on every record: @timestamp, log.level, message, ecs.version, log.logger,
 * event.{kind,module,dataset,created,severity}.
 */
class EcsFieldsFormatter extends JsonFormatter
{
    /** Default ECS schema version advertised in the `ecs.version` field; override per-instance or via bundle config. */
    public const string DEFAULT_ECS_VERSION = '8.11.0';

    /** Governed namespaces and their maximum promoted key counts. */
    private const array GOVERNED = [
        'labels' => 8,
        'metric' => 8,
        'text' => 2,
    ];

    private const int MAX_TAGS = 8;

    /** ISO-8601 with microseconds — the conventional precision for an ECS @timestamp. */
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** Base scalar fields a contributed fragment must never overwrite (matched as a literal field key). */
    private const array PROTECTED_SCALARS = ['@timestamp', 'log.level', 'message', 'ecs.version'];

    /**
     * Base scalars the skeleton emits in DOTTED top-level form, as parent => owned child. A fragment
     * expressing the same path NESTED (e.g. ['ecs' => ['version' => …]], ['log' => ['level' => …]])
     * would create a duplicate field in Elasticsearch, so the owned child is stripped from it.
     */
    private const array PROTECTED_NESTED = ['log' => 'level', 'ecs' => 'version'];

    /** Base object fields a fragment may extend additively, but never override (base wins conflicts). */
    private const array MERGE_UNDER_BASE = ['log', 'event'];

    /** The formatter's own output buckets — not ECS fields, so a fragment may not drive them. */
    private const array RESERVED_BUCKETS = ['context', 'extra'];

    public function __construct(
        private readonly EcsFormatMode $mode = EcsFormatMode::Copy,
        private readonly string $ecsVersion = self::DEFAULT_ECS_VERSION,
        bool $appendNewline = true,
        bool $includeStacktraces = false,
    ) {
        // NDJSON: one record per line, newline-delimited; empty context/extra objects are omitted.
        parent::__construct(self::BATCH_MODE_NEWLINES, $appendNewline, true, $includeStacktraces);
        $this->setDateFormat(self::DATE_FORMAT);
    }

    public function format(LogRecord $record): string
    {
        return $this->encodeRecord($record) . ($this->appendNewline ? "\n" : '');
    }

    public function formatBatch(array $records): string
    {
        $lines = array_map(fn (LogRecord $record): string => $this->encodeRecord($record), $records);

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . ($this->appendNewline ? "\n" : '');
    }

    private function encodeRecord(LogRecord $record): string
    {
        // EcsField objects live in the raw context/extra. Pull them out BEFORE normalisation,
        // otherwise Monolog wraps each object as ['<FQCN>' => ...]. extra is scanned first and
        // context second, so an explicit context object wins over a processor-supplied one.
        $context = $record->context;
        $extra = $record->extra;

        $fragments = [];
        $this->pullFields($extra, $fragments);
        $this->pullFields($context, $fragments);
        $this->promoteContextException($context, $fragments);

        $datetime = $this->formatDatetime($record);
        $output = $this->buildBaseFields($record, $datetime);

        // Leftover (non-EcsField) context is emitted as-is; bag overflow/demotions are added to it.
        $leftoverContext = $this->normalizeArray($context);
        $output = $this->mergeFragments($output, $fragments, $leftoverContext);

        // `context`/`extra` are reserved buckets: mergeFragments demotes any fragment that targets
        // them, so nothing else writes these slots and a direct assignment is safe.
        if ($leftoverContext !== []) {
            $output['context'] = $leftoverContext;
        }

        $leftoverExtra = $this->normalizeArray($extra);
        if ($leftoverExtra !== []) {
            $output['extra'] = $leftoverExtra;
        }

        return $this->toJson($output);
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
     * Promote a \Throwable at context['exception'] (the Monolog convention) to error.* via EcsError.
     * When an explicit EcsError (from a bag) already owns error.*, the throwable is left untouched in
     * context — never dropped, never double-promoted. Otherwise it is promoted to error.* and the
     * consumed exception is removed from the leftover context (it now lives, typed, under error.*).
     *
     * @param array<array-key, mixed> $context   modified by reference
     * @param array<string, mixed>    $fragments modified by reference
     */
    private function promoteContextException(array &$context, array &$fragments): void
    {
        $exception = $context['exception'] ?? null;

        if (!$exception instanceof \Throwable) {
            return;
        }

        // An explicit EcsError (from a bag) already owns error.*. Leave this exception in context
        // rather than consuming it — unsetting it here without promoting it would silently drop it,
        // breaking the never-drop policy. It flows, Monolog-normalised, into the leftover context.
        if (isset($fragments['error'])) {
            return;
        }

        foreach ((new EcsError($exception))->toEcs() as $field => $payload) {
            $fragments[$field] = $payload;
        }

        // The exception now lives, typed, under error.* — drop the raw copy regardless of mode.
        unset($context['exception']);
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
            // Strip any nested contribution to a dotted base path (e.g. log.level, ecs.version)
            // before placing the fragment, so it can never shadow the base skeleton.
            if (isset(self::PROTECTED_NESTED[$field]) && is_array($value)) {
                unset($value[self::PROTECTED_NESTED[$field]]);
            }

            if ($field === 'tags') {
                [$promoted, $rejected] = $this->partitionTags($value);

                if ($promoted !== []) {
                    $output['tags'] = $promoted;
                }

                $this->placeLeftover('tags', $rejected, $leftover);
            } elseif (isset(self::GOVERNED[$field])) {
                if (!is_array($value)) {
                    $this->demoteValue($field, $value, $leftover); // malformed payload — never drop
                    continue;
                }

                [$promoted, $rejected] = $this->partitionNamespace($field, $value);

                if ($promoted !== []) {
                    $output[$field] = $promoted;
                }

                $this->placeLeftover($field, $rejected, $leftover);
            } elseif (in_array($field, self::RESERVED_BUCKETS, true) || in_array($field, self::PROTECTED_SCALARS, true)) {
                // Reserved buckets (context, extra) are not ECS fields, and the base owns the
                // protected scalars — neither may become a top-level field. Demote so the value is
                // preserved under context; an existing logged key of that name wins (first-write).
                $this->demoteValue($field, $value, $leftover);
            } elseif (in_array($field, self::MERGE_UNDER_BASE, true)) {
                if (!is_array($value)) {
                    $this->demoteValue($field, $value, $leftover);
                    continue;
                }

                // Additive: keep base values on conflict, but allow new sub-keys (e.g. log.origin).
                $output[$field] = $this->deepMerge($value, $output[$field] ?? []);
            } elseif (!is_array($value) || $value !== []) {
                // Passthrough ECS namespace (service, user, trace, http, project-specific, …).
                // An empty array — e.g. a fragment that was only a stripped base path — adds nothing.
                $output[$field] = $this->deepMerge($output[$field] ?? [], $value);
            }
        }

        return $output;
    }

    /**
     * Never-drop a value that can't be promoted, by parking it under $field in the leftover context.
     * An existing key wins (first-write precedence) — including one whose value is null, so an
     * explicit plain-context entry is never silently replaced.
     *
     * @param array<string, mixed> $leftover modified by reference
     */
    private function demoteValue(string $field, mixed $value, array &$leftover): void
    {
        if (!array_key_exists($field, $leftover)) {
            $leftover[$field] = $value;
        }
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
     * Never-drop policy for what couldn't be promoted: keep only the rejected entries in the leftover
     * context — governed namespaces as dotted keys (e.g. `metric.bad key`), tags merged into a list.
     * Promoted entries are not duplicated under context; promotion is identical in both modes.
     *
     * @param array<array-key, mixed> $rejected entries that were not promoted
     * @param array<string, mixed> $leftover modified by reference
     */
    private function placeLeftover(string $namespace, array $rejected, array &$leftover): void
    {
        if ($rejected === []) {
            return;
        }

        if ($namespace === 'tags') {
            // tags is a flat list; merge rejected entries into any existing value without assuming
            // the leftover slot is already an array (a plain scalar context 'tags' may occupy it).
            $existing = $leftover['tags'] ?? [];
            $existing = is_array($existing) ? array_values($existing) : [$existing];
            $leftover['tags'] = array_merge($existing, $rejected);

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
            'ecs.version' => $this->ecsVersion,
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
        return $record->datetime->format(self::DATE_FORMAT);
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
            // Two lists concatenate (e.g. tags from multiple bags); maps merge key-by-key, $b winning.
            if (array_is_list($a) && array_is_list($b)) {
                return array_merge($a, $b);
            }

            foreach ($b as $key => $value) {
                $a[$key] = array_key_exists($key, $a) ? $this->deepMerge($a[$key], $value) : $value;
            }

            return $a;
        }

        return $b;
    }
}
