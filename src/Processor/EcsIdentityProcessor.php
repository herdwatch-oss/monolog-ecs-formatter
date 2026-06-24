<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Processor;

use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Attaches the ECS service identity to every record so it rides the single EcsField path:
 *   - extra.service = new Service($serviceName, version: …, environment: …)
 *
 * The formatter then promotes it to top-level service.* fields. The optional version/environment
 * (typically bound to %env(APP_VERSION)% / %env(APP_ENV)% via bundle config) ride along so every
 * record carries service.version and service.environment without per-call-site effort. Exception
 * handling is NOT this processor's concern: the formatter promotes a \Throwable at
 * context['exception'] to error.* on its own, regardless of whether this processor is registered.
 */
final class EcsIdentityProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly string $serviceName,
        private readonly ?string $version = null,
        private readonly ?string $environment = null,
        private readonly string $language = 'php',
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Respect a Service already set by application code or an earlier processor.
        $extra['service'] ??= new Service(
            $this->serviceName,
            version: $this->version,
            environment: $this->environment,
            language: $this->language,
        );

        return $record->with(extra: $extra);
    }
}
