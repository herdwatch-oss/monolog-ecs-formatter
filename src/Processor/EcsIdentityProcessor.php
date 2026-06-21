<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Processor;

use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Attaches ECS identity objects to every record so they ride the single EcsField path:
 *   - extra.service = new Service($serviceName)            — always
 *   - extra.error   = new EcsError($throwable)             — only when context['exception'] is a \Throwable
 *
 * The formatter then promotes both to top-level ECS fields (service.*, error.*).
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

        $extra['service'] = new Service($this->serviceName, language: $this->language);

        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            $extra['error'] = new EcsError($exception);
        }

        return $record->with(extra: $extra);
    }
}
