# herdwatch-oss/monolog-ecs-formatter

A Monolog formatter and Symfony bundle that promotes **typed ECS value objects** from a log's context to top-level ECS-aligned JSON fields, for clean Elasticsearch mapping.

You log structured data as small typed objects — `Metrics`, `Labels`, `Text`, `Tags`, `Service`, `User`, `Tracing`, `EcsError`, or any of your own — and the formatter lifts them to the correct ECS location with key validation, per-namespace caps, and a never-drop guarantee. The JSON type of each value is fixed by the object's method signatures, so there is no guesswork or formatter-side coercion.

```php
use Herdwatch\MonologEcsFormatter\Ecs\{Metrics, Labels, Tags, Service, EcsError};

$log->info('Order processed', [
    Metrics::create()->total('orders_total', 1200)->gauge('latency_ms', 12.5)->flag('is_retry', false),
    Labels::create()->add('tenant', 'acme')->add('env', 'prod'),
    Tags::of('billing', 'reconciliation'),
]);
```

```json
{
  "@timestamp": "2026-06-21T09:14:02.481139+00:00",
  "log.level": "info",
  "message": "Order processed",
  "ecs.version": "8.11.0",
  "log": {"logger": "app"},
  "event": {"kind": "event", "module": "symfony", "dataset": "symfony.logs", "created": "2026-06-21T09:14:02.481139+00:00", "severity": 200},
  "metric": {"orders_total": 1200, "latency_ms": 12.5, "is_retry": false},
  "labels": {"tenant": "acme", "env": "prod"},
  "tags": ["billing", "reconciliation"]
}
```

## Installation

```bash
composer require herdwatch-oss/monolog-ecs-formatter
```

Register the bundle in `config/bundles.php`:

```php
Herdwatch\MonologEcsFormatter\MonologEcsFormatterBundle::class => ['all' => true],
```

## Configuration

Create `config/packages/monolog_ecs_formatter.yaml`:

```yaml
monolog_ecs_formatter:
    mode: move                  # "move" (default) or "copy" — see Modes below
    service_name: my-service    # optional; enables the EcsIdentityProcessor when set
    ecs_version: '8.11.0'       # optional; value advertised in ecs.version (defaults to the ECS schema this formatter targets)
```

## Passing fields

Fields are detected by `instanceof`, **not** by array key — so the key is irrelevant. Pass them positionally (cleanest) or under any key; both work, and ordinary context entries flow through untouched:

```php
$log->info('User authenticated', [
    new Service('billing-svc', version: '1.4.0', environment: 'prod'),
    new User(id: 42, email: 'farmer@example.com'),
    new Tracing($traceId, $transactionId),
    'request_id' => $requestId,        // plain context — stays under "context", not promoted
]);
```

Multiple bags of the same kind merge. `EcsField` objects are detected in **both** `context` and `extra` (context wins on a conflict) — this is how the identity processor's injected `Service` object gets promoted. Anything that is **not** an `EcsField` is left alone: non-field context goes under a leftover `context` object, and `extra` is emitted verbatim.

### Recommended usage

Pass field objects **positionally, without a key**. The key is ignored for routing anyway, so a key is redundant and misleading; positional also reads cleanly and lets multiple fields merge:

```php
// ✅ Recommended — positional
$log->error('Sync failed', [
    new EcsError($e),
    new Service('billing-svc'),
    Labels::create()->add('tenant', 'acme'),
]);

// ⚠️ Works, but the key is redundant (it is NOT used to route the field)
$log->error('Sync failed', ['error' => new EcsError($e)]);
```

Reserve string keys for the two cases where the key genuinely matters:

- the `exception` convention — `['exception' => $e]` with a raw `\Throwable`, which the formatter auto-promotes to `error.*`;
- ordinary scalar context you want to keep or interpolate — `['request_id' => $id]`.

## Governed namespaces (bags)

| Bag | ECS field | Value typing | Cap |
|-----|-----------|--------------|-----|
| `Metrics` | `metric.*` | `count()`/`total()` → int, `gauge()` → float, `flag()` → bool | 8 keys |
| `Labels` | `labels.*` | scalars coerced to string | 8 keys |
| `Text` | `text.*` | strings | 2 keys |
| `Tags` | `tags` | de-duplicated keyword strings | 8 |

Keys must match `/^[a-z][a-z0-9]*(_[a-z][a-z0-9]*){0,2}$/` (lower snake_case, ≤ 3 segments).

**Nothing is ever dropped.** A key that fails validation or exceeds the cap is preserved:
- **Move mode:** demoted into the leftover `context` with a dotted key — e.g. `Labels::create()->add('Bad Key', 'x')` ends up as `"context": {"labels.Bad Key": "x"}`.
- **Copy mode:** the full original payload is mirrored under `context.<namespace>` (see Modes).

### Building a bag from an array

When you already hold an array (config-driven logging, generic middleware), use the `fromArray()` factories — values still go through the bag's typing/validation:

```php
Metrics::fromArray(['rows_total' => 1200, 'avg_ms' => 8.3]); // non-numeric values are ignored
Labels::fromArray($keyValuePairs);
Tags::fromArray($list);
```

## Identity fields

| Object | ECS fields |
|--------|-----------|
| `Service` | `service.name`, `service.language` (default `php`), and optional `version`, `environment`, `node.name` |
| `User` | `user.id`, `name`, `email`, `domain`, `full_name`, `hash` (null fields omitted) |
| `Tracing` | `trace.id`, `transaction.id` — for logs ↔ APM correlation |
| `EcsError` | `error.type` (class), `message`, `stack_trace` (includes throw-site file:line + previous-exception chain); `error.code` only when non-zero |

```php
$log->error('Sync failed', [new EcsError($exception)]);
```

## Standard ECS context fields

Bundled value objects for common runtime / request / host fields, so each service doesn't hand-roll them. All take named constructor arguments and omit null fields.

| Object | ECS fields |
|--------|-----------|
| `Http` | `http.request.{method,body.bytes,mime_type}`, `http.response.{status_code,body.bytes,mime_type}` — request/response nest, so separate `Http` objects deep-merge |
| `Process` | `process.pid`, `process.command_line`, `process.name` |
| `Client` | `client.ip`, `client.port` |
| `UserAgent` | `user_agent.original`, `user_agent.version`, `user_agent.device.name` |
| `Host` | `host.name`, `host.ip` |
| `Event` | `event.action`, `event.start`, `event.duration` (nanoseconds), `event.outcome` (the `EventOutcome` enum: success/failure/unknown) — merged additively onto the base `event` object; it cannot override `event.kind`/`dataset`/etc. |
| `Url` | `url.full`, `url.scheme`, `url.domain`, `url.port`, `url.path`, `url.query`, `url.fragment` — pass parts by name, or use `Url::parse($url)` to split a URL string |

```php
$log->info('Inbound request', [
    new Http(statusCode: 200, method: 'POST'),
    new Client(ip: $request->getClientIp()),
    Url::parse((string) $request->getUri()),
    new Event(action: 'api.request', start: $startedAt),
]);
```

> `Url` records the URL faithfully — `url.full`/`url.query` keep whatever you pass, including any embedded credentials or query tokens/PII. Redaction is the application's job: sanitise the URL before logging it, or strip sensitive fields in a Monolog processor.

## Project-specific fields

Any class implementing `EcsField` is detected automatically — no registration, no formatter change. `EcsField` extends `JsonSerializable`; `use SerializesToEcs` to satisfy it (it maps `jsonSerialize()` to `toEcs()`):

```php
use Herdwatch\MonologEcsFormatter\Ecs\EcsField;
use Herdwatch\MonologEcsFormatter\Ecs\SerializesToEcs;

final class FarmContext implements EcsField
{
    use SerializesToEcs;

    public function __construct(private string $herdId, private string $region) {}

    public function toEcs(): array
    {
        return ['farm' => ['herd_id' => $this->herdId, 'region' => $this->region]];
    }
}

$log->info('Herd sync complete', [new FarmContext($herd->id(), $herd->region())]);
// → { …, "farm": {"herd_id": "…", "region": "…"} }
```

Rules that keep this safe:
- A fragment targeting a governed namespace (`metric`/`labels`/`text`/`tags`) is validated and capped like any bag, whatever produced it.
- Unknown namespaces and new top-level fields pass through.
- A fragment can never overwrite the base skeleton (`@timestamp`, `log.level`, `message`, `ecs.version`); contributions to `log`/`event` (e.g. `log.origin`) are merged additively, with the base winning conflicts.

> **Serialisation under other handlers.** Because `EcsField` is `JsonSerializable`, the same object also serialises to its ECS data under a non-ECS formatter/handler (a JSON handler emits the bare fragment; a line-based one wraps it under the class name) instead of an empty `{}`. That path is *raw* `toEcs()` — the validation, caps and never-drop demotion above are applied only by `EcsFieldsFormatter`, which pulls the objects out before normalising. If a non-ECS handler needs the governed, promoted form, point it at `EcsFieldsFormatter` too.

To apply a custom field to **every** record, inject it from a Monolog processor (the pattern the bundled identity processor uses).

## ECS base fields emitted on every record

| Field | Value |
|-------|-------|
| `@timestamp` | record datetime, ISO-8601 with microseconds |
| `log.level` | lowercased level name (dotted top-level key, per the ecs-logging spec) |
| `message` | log message |
| `ecs.version` | configurable; defaults to `8.11.0` (the ECS schema this formatter's fields conform to — bump it if you emit fields from a newer ECS version) |
| `log.logger` | channel name |
| `event.kind` / `module` / `dataset` | `event` / `symfony` / `symfony.logs` |
| `event.created` / `severity` | record datetime / Monolog level integer |

## Exceptions

A `\Throwable` at `context['exception']` (the Monolog convention) is promoted to `error.*` by the **formatter** automatically — no processor or configuration required. So existing `$log->error($msg, ['exception' => $e])` call sites get `error.{type,message,code,stack_trace}` for free. In move mode the consumed exception is then removed from the leftover context; in copy mode it is kept (Monolog-normalised) for dashboard compatibility. An explicit `new EcsError($e)` always takes precedence.

## Service identity processor (`service.*`)

When `service_name` is configured, `EcsIdentityProcessor` is registered as a global Monolog processor and injects a `Service` object into every record, which the formatter promotes to `service.*`. Omitting `service_name` disables it. The processor does **not** handle exceptions — that is the formatter's job (see above).

## Modes

### `move` (default)

ECS fields are promoted to top-level; everything else stays under `context`/`extra`. No legacy top-level keys. Use this for a clean ECS-only shape.

### `copy`

A non-destructive transition mode: the legacy top-level keys (`channel`, `level_name`, `level`, `datetime`) are kept **and** each governed namespace is mirrored under `context.<namespace>` (the full, uncapped payload), so dashboards querying the old `context.*` paths keep working while you migrate them to the promoted top-level fields.

## Wiring the formatter in `monolog.yaml`

```yaml
monolog:
    handlers:
        my_handler:
            type: stream
            path: "php://stderr"
            formatter: Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter
```

For service-type handlers wired in `services.yaml`:

```yaml
MyApp\Monolog\Handler\MyStreamHandler:
    calls:
        - [setFormatter, ['@Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter']]
```

## Test command

In `dev`/`test` environments the bundle registers a console command that emits sample records covering every field type, the governance rules, and a custom `EcsField`:

```bash
bin/console monolog-ecs:test
```

## License

Released under the [MIT License](LICENSE). Copyright © Herdwatch.
