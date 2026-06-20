<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter;

use Herdwatch\MonologEcsFormatter\Formatter\EcsFieldsFormatter;
use Herdwatch\MonologEcsFormatter\Formatter\EcsFormatMode;
use Herdwatch\MonologEcsFormatter\Processor\EcsIdentityProcessor;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MonologEcsFormatterBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->enumNode('mode')
                    ->values(['move', 'copy'])
                    ->defaultValue('move')
                    ->info('Formatter mode: "move" relocates fields to ECS top-level and drops originals (default); "copy" adds ECS fields and retains all original fields.')
                ->end()
                ->scalarNode('service_name')
                    ->defaultNull()
                    ->info('Service name for ECS service.name field (e.g. "my-service"). When set, the EcsIdentityProcessor is registered and adds service.* and error.* fields.')
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(EcsFieldsFormatter::class)
            ->arg('$mode', EcsFormatMode::from($config['mode']));

        if ($config['service_name'] !== null) {
            $container->services()
                ->set(EcsIdentityProcessor::class)
                ->arg('$serviceName', $config['service_name'])
                ->autoconfigure();
        }

        $env = $container->env();
        if ($env === 'dev' || $env === 'test') {
            $container->services()
                ->set(Command\TestLogFormatterCommand::class)
                ->autowire()
                ->autoconfigure();
        }
    }
}
