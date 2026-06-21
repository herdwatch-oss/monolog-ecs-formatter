<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Formatter;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Monolog formatter that extracts known keys (labels, metric, text, tags)
 * from context and promotes them to top-level JSON fields for proper Elasticsearch mapping.
 *
 * Metric values are type-coerced: *_count/*_total → int, is_* → bool, everything else → float.
 * Tags are promoted as a flat array of unique keyword strings (ECS base field).
 *
 * extra is treated as opaque processor data: its contents are NOT scanned for extractable namespaces
 * and are re-emitted verbatim (after service/error identity fields have been lifted out).
 *
 * Modes:
 *   Move (default): relocate fields to ECS top-level and drop originals — clean ECS-only end-state.
 *   Copy: promote ECS fields AND retain all original top-level keys (channel, level_name, level, datetime)
 *         and original context/extra contents — non-destructive transition mode.
 */
class EcsFieldsFormatter extends JsonFormatter
{
    private const array EXTRACTABLE_KEYS = ['labels', 'metric', 'text'];
    private const array LONG_SUFFIXES = ['_count', '_total'];
    private const array BOOL_PREFIXES = ['is_'];

    private const string KEY_PATTERN = '/^[a-z][a-z0-9]*(_[a-z][a-z0-9]*){0,2}$/';
    private const int MAX_TAGS = 8; // flat keyword array — generous for categorisation
    private const array MAX_KEYS = [
        'labels' => 8,   // filtering dimensions
        'metric' => 8,  // capped at 8 keys
        'text' => 2,     // long text fields — one or two per event is enough
    ];

    public function __construct(
        private readonly EcsFormatMode $mode = EcsFormatMode::Move,
        int $batchMode = self::BATCH_MODE_NEWLINES,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = false,
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    public function format(LogRecord $record): string
    {
        $normalizedRaw = parent::normalize($record->toArray());
        $normalized = is_array($normalizedRaw) ? $normalizedRaw : [];

        $output = $this->buildBaseFields($normalized);

        // extra is opaque processor data — never unflattened or scanned for extractable namespaces.
        $extra = is_array($normalized['extra'] ?? null) ? $normalized['extra'] : [];
        $context = $this->unflattenDotKeys(is_array($normalized['context'] ?? null) ? $normalized['context'] : []);

        // Extract namespaces from a working copy of context. In move mode the namespace keys are
        // consumed, so the remainder is re-emitted; in copy mode the original context is preserved
        // intact (promoted values appear both top-level and under their original context location).
        $working = $context;
        $output = $this->extractNamespaces($output, $working);
        $output = $this->extractTags($output, $working);

        $leftoverContext = $this->mode === EcsFormatMode::Copy ? $context : $working;

        // Promote bounded ECS objects (service, error) from context/extra in both modes, stripping
        // them from the leftovers since they now appear at top-level.
        $output = $this->promoteEcsObjects($output, $leftoverContext, $extra);

        // Re-emit whatever is left so nothing is lost.
        if ($leftoverContext) {
            $output['context'] = $leftoverContext;
        }

        if ($extra) {
            $output['extra'] = $extra;
        }

        return $this->toJson($output) . "\n";
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildBaseFields(array $normalized): array
    {
        $base = [];

        if ($this->mode === EcsFormatMode::Copy) {
            // Seed legacy top-level keys first so ECS fields overlay them.
            $base['channel'] = $normalized['channel'];
            $base['level_name'] = $normalized['level_name'];
            $base['level'] = $normalized['level'];
            $base['datetime'] = $normalized['datetime'];
        }

        $base['message'] = $normalized['message'];
        $base['event'] = [
            'kind' => 'event',
            'module' => 'symfony',
            'dataset' => 'symfony.logs',
            'created' => $normalized['datetime'],
            'severity' => $normalized['level'],
        ];
        $base['log'] = [
            'level' => strtolower($normalized['level_name']),
            'logger' => $normalized['channel'],
        ];

        return $base;
    }

    /**
     * Lift the bounded ECS identity objects (service, error) from extra/context to top-level.
     * Context wins over extra for the same key, mirroring namespace precedence.
     * The promoted key is removed from the $extra/$context arrays passed by reference.
     * This runs in both Move and Copy modes.
     *
     * @param array<string, mixed> $output
     * @param array<string, mixed> $context (modified by reference — promoted keys removed)
     * @param array<string, mixed> $extra   (modified by reference — promoted keys removed)
     * @return array<string, mixed>
     */
    private function promoteEcsObjects(array $output, array &$context, array &$extra): array
    {
        foreach (['service', 'error'] as $key) {
            $fromExtra = isset($extra[$key]) && is_array($extra[$key]) ? $extra[$key] : null;
            $fromContext = isset($context[$key]) && is_array($context[$key]) ? $context[$key] : null;

            if ($fromExtra !== null || $fromContext !== null) {
                // Context wins: merge extra first, then context overwrites.
                $merged = array_merge($fromExtra ?? [], $fromContext ?? []);
                $output[$key] = $merged;

                unset($extra[$key], $context[$key]);
            }
        }

        return $output;
    }

    /**
     * Extract known keys (labels, metric, text) from context, coerce values,
     * and promote them to top-level output fields.
     *
     * Remainders are written back to $context by reference.
     * extra is not scanned — it is re-emitted verbatim by the caller.
     *
     * @param array<string, mixed> $output
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function extractNamespaces(array $output, array &$context): array
    {
        foreach (self::EXTRACTABLE_KEYS as $key) {
            $contextValues = $context[$key] ?? [];

            unset($context[$key]);

            if (!is_array($contextValues)) {
                $contextValues = [$contextValues];
            }

            [$contextCoerced, $contextRemainder] = $this->coerceValues($key, $contextValues);

            if ($contextRemainder) {
                $context[$key] = $contextRemainder;
            }

            if ($contextCoerced) {
                $output[$key] = $contextCoerced;
            }
        }

        return $output;
    }

    /**
     * Extract tags from context, deduplicate, and promote to top-level.
     *
     * Remainders are written back to $context by reference.
     * extra is not scanned — it is re-emitted verbatim by the caller.
     *
     * @param array<string, mixed> $output
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function extractTags(array $output, array &$context): array
    {
        [$contextTags, $contextTagRemainder] = $this->partitionTags($context['tags'] ?? []);

        unset($context['tags']);

        if ($contextTagRemainder) {
            $context['tags'] = $contextTagRemainder;
        }

        $mergedTags = array_values(array_unique($contextTags));

        if ($mergedTags) {
            $output['tags'] = $mergedTags;
        }

        return $output;
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
     * @param array<string, mixed> $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [coerced, remainder]
     */
    private function coerceValues(string $namespace, array $values): array
    {
        return match ($namespace) {
            'labels', 'text' => $this->partitionScalars($namespace, $values),
            'metric' => $this->partitionMetrics($values),
            default => [[], $values],
        };
    }

    /**
     * @param array<string, mixed> $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [coerced, remainder]
     */
    private function partitionScalars(string $namespace, array $values): array
    {
        $coerced = [];
        $remainder = [];

        foreach ($values as $key => $value) {
            if (count($coerced) >= self::MAX_KEYS[$namespace]) {
                $remainder[$key] = $value;
            } elseif (!$this->isValidKey($key)) {
                $remainder[$key] = $value;
            } elseif (is_scalar($value)) {
                $coerced[$key] = (string)$value;
            } else {
                $remainder[$key] = $value;
            }
        }

        return [$coerced, $remainder];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [coerced, remainder]
     */
    private function partitionMetrics(array $values): array
    {
        $coerced = [];
        $remainder = [];

        foreach ($values as $key => $value) {
            if (count($coerced) >= self::MAX_KEYS['metric']) {
                $remainder[$key] = $value;
            } elseif (!$this->isValidKey($key)) {
                $remainder[$key] = $value;
            } elseif ($this->hasPrefix($key, self::BOOL_PREFIXES) && is_scalar($value)) {
                $coerced[$key] = (bool)$value;
            } elseif ($this->hasSuffix($key, self::LONG_SUFFIXES)) {
                if (is_numeric($value) && (int)$value == $value) {
                    $coerced[$key] = (int)$value;
                } else {
                    $remainder[$key] = $value;
                }
            } elseif (is_numeric($value)) {
                $coerced[$key] = (float)$value;
            } else {
                $remainder[$key] = $value;
            }
        }

        return [$coerced, $remainder];
    }

    /**
     * @param mixed $values
     * @return array{0: list<string>, 1: list<mixed>} [validTags, remainder]
     */
    private function partitionTags(mixed $values): array
    {
        if (!is_array($values)) {
            return $values !== null ? [[], [$values]] : [[], []];
        }

        $valid = [];
        $remainder = [];

        foreach ($values as $value) {
            if (count($valid) >= self::MAX_TAGS) {
                $remainder[] = $value;
            } elseif (is_string($value) && $value !== '') {
                $valid[] = $value;
            } else {
                $remainder[] = $value;
            }
        }

        return [$valid, $remainder];
    }

    /**
     * Converts dot-notation keys matching extractable namespaces into nested arrays.
     * E.g. ['labels.env' => 'prod', 'user_id' => 42] → ['labels' => ['env' => 'prod'], 'user_id' => 42]
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function unflattenDotKeys(array $data): array
    {
        foreach (array_keys($data) as $key) {
            $dotPos = strpos($key, '.');

            if ($dotPos === false) {
                continue;
            }

            $prefix = substr($key, 0, $dotPos);

            if (!in_array($prefix, self::EXTRACTABLE_KEYS, true)) {
                continue;
            }

            $suffix = substr($key, $dotPos + 1);

            if ($suffix === '' || str_contains($suffix, '.')) {
                continue;
            }

            $data[$prefix] ??= [];

            if (is_array($data[$prefix])) {
                $data[$prefix][$suffix] = $data[$key];
            }

            unset($data[$key]);
        }

        return $data;
    }

    private function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * @param string[] $suffixes
     */
    private function hasSuffix(string $key, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $prefixes
     */
    private function hasPrefix(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
