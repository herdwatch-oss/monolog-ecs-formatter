<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Processor;

use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Attaches the ECS service identity to every record so it rides the single EcsField path:
 *   - extra.service = new Service($serviceName)
 *
 * The formatter then promotes it to top-level service.* fields. Exception handling is NOT this
 * processor's concern: the formatter promotes a \Throwable at context['exception'] to error.* on
 * its own, regardless of whether this processor is registered.
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

        // Respect a Service already set by application code or an earlier processor.
        $extra['service'] ??= new Service($this->serviceName, language: $this->language);

        return $record->with(extra: $extra);
    }
}
