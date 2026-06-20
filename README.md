# herdwatch-oss/monolog-ecs-formatter

Monolog formatter and Symfony bundle that promotes structured log context (`labels`, `metric`, `text`, `tags`, `service`, `error`) to top-level ECS-aligned JSON fields for clean Elasticsearch mapping, with `copy`/`move` modes for a non-destructive migration.

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
    mode: copy                  # "move" (default) or "copy" — see Modes below
    service_name: my-service    # optional; enables the EcsIdentityProcessor when set
```

## Modes

### `move` (default)

Fields from `context`/`extra` matching the ECS namespaces (`labels`, `metric`, `text`, `tags`) are relocated to top-level JSON fields. The originals are removed. Legacy top-level keys (`channel`, `level_name`, `level`, `datetime`) are **not** emitted.

Use this for a clean ECS-only log shape.

### `copy`

All existing top-level keys (`channel`, `level_name`, `level`, `datetime`) and the original `context`/`extra` contents are **retained**. ECS fields (`event.*`, `log.*`, and any promoted namespaces) are **added on top**.

Use this for a non-destructive transition while existing dashboards/queries still reference legacy field names.

## ECS base fields emitted on every record

| Field | Value |
|-------|-------|
| `message` | log message |
| `event.kind` | `event` |
| `event.module` | `symfony` |
| `event.dataset` | `symfony.logs` |
| `event.created` | record datetime |
| `event.severity` | Monolog level integer |
| `log.level` | lowercased level name |
| `log.logger` | channel name |

## Promoted namespaces

| Key | Type | Notes |
|-----|------|-------|
| `labels` | `string` values | filtering dimensions; max 8 keys |
| `metric` | typed numbers | `*_count`/`*_total` → int, `is_*` → bool, else float; max 8 keys |
| `text` | `string` values | long text; max 2 keys |
| `tags` | `string[]` | flat unique keyword array; max 8 |

Keys must match `/^[a-z][a-z0-9]*(_[a-z][a-z0-9]*){0,2}$/`. Non-conforming keys fall back to `context`/`extra` (never dropped). Dot-notation (`labels.env`) is unflattened automatically.

## Identity processor (`service.*` / `error.*`)

When `service_name` is configured, `EcsIdentityProcessor` is registered as a global Monolog processor and adds the fields below. Omitting `service_name` disables the processor entirely — no `service.*` or `error.*` fields will appear in logs.

- `service.name` = configured value (e.g. `my-service`)
- `service.language` = `php`
- `error.message` + `error.stack_trace` — only when `context['exception']` is a `\Throwable`

The formatter promotes `service` and `error` to top-level ECS fields in both modes.

## Wiring formatters in `monolog.yaml`

For standard stream handlers:

```yaml
monolog:
    handlers:
        my_handler:
            type: stream
            path: "php://stderr"
            formatter: Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter
```

For service-type handlers (e.g. a custom stream handler wired in `services.yaml`):

```yaml
# config/services.yaml
MyApp\Monolog\Handler\MyStreamHandler:
    calls:
        - [setFormatter, ['@Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter']]
```

## Test command

In `dev`/`test` environments the bundle registers a console command to verify formatter output:

```bash
bin/console monolog-ecs:test
```

This emits sample log records covering the promoted namespaces, metric coercion, key-format validation, and edge cases. Inspect the log file (or stdout) to confirm the NDJSON shape matches expectations.

## License

Released under the [MIT License](LICENSE). Copyright © Herdwatch.
