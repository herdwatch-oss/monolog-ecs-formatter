<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds ECS identity fields to every log record:
 *   - extra.service.name  = the configured service name
 *   - extra.service.language = 'php'
 *   - extra.error.message     = throwable message  (only when context['exception'] is a \Throwable)
 *   - extra.error.stack_trace = throwable trace    (only when context['exception'] is a \Throwable)
 *
 * The formatter will subsequently promote the service and error objects to top-level ECS fields.
 * This processor is non-throwing: a non-Throwable exception value is silently ignored.
 */
final class EcsIdentityProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly string $serviceName,
        private readonly string $language = 'php',
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $extra['service'] = [
            'name' => $this->serviceName,
            'language' => $this->language,
        ];

        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            $extra['error'] = [
                'message' => $exception->getMessage(),
                'stack_trace' => $exception->getTraceAsString(),
            ];
        }

        return $record->with(extra: $extra);
    }
}
