<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `service.*` identity. Immutable; construct with named arguments.
 *
 * Example:
 *   new Service('billing-svc', version: '1.4.0', environment: 'prod')
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-service.html
 */
final class Service implements EcsField
{
    use SerializesToEcs;

    public function __construct(
        private readonly string $name,
        private readonly ?string $version = null,
        private readonly ?string $environment = null,
        private readonly ?string $nodeName = null,
        private readonly string $language = 'php',
    ) {
    }

    public function toEcs(): array
    {
        $service = [
            'name' => $this->name,
            'language' => $this->language,
        ];

        if ($this->version !== null) {
            $service['version'] = $this->version;
        }

        if ($this->environment !== null) {
            $service['environment'] = $this->environment;
        }

        if ($this->nodeName !== null) {
            $service['node'] = ['name' => $this->nodeName];
        }

        return ['service' => $service];
    }
}
