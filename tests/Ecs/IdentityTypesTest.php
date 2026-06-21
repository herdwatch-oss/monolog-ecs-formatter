<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Herdwatch\MonologEcsFormatter\Ecs\Tracing;
use Herdwatch\MonologEcsFormatter\Ecs\User;
use PHPUnit\Framework\TestCase;

class IdentityTypesTest extends TestCase
{
    public function testServiceMinimal(): void
    {
        self::assertSame(
            ['service' => ['name' => 'svc', 'language' => 'php']],
            (new Service('svc'))->toEcs(),
        );
    }

    public function testServiceFull(): void
    {
        self::assertSame(
            ['service' => [
                'name' => 'svc',
                'language' => 'php',
                'version' => '1.0',
                'environment' => 'prod',
                'node' => ['name' => 'node-1'],
            ]],
            (new Service('svc', version: '1.0', environment: 'prod', nodeName: 'node-1'))->toEcs(),
        );
    }

    public function testUserOmitsNullFields(): void
    {
        self::assertSame(
            ['user' => ['id' => 42, 'email' => 'farmer@example.com']],
            (new User(id: 42, email: 'farmer@example.com'))->toEcs(),
        );
    }

    public function testUserWithNoFieldsProducesNoFragment(): void
    {
        self::assertSame([], (new User())->toEcs());
    }

    public function testTracingWithTransaction(): void
    {
        self::assertSame(
            ['trace' => ['id' => 'trace-1'], 'transaction' => ['id' => 'txn-1']],
            (new Tracing('trace-1', 'txn-1'))->toEcs(),
        );
    }

    public function testTracingWithoutTransaction(): void
    {
        self::assertSame(['trace' => ['id' => 'trace-1']], (new Tracing('trace-1'))->toEcs());
    }

    public function testEcsErrorCapturesTypeMessageCodeAndTrace(): void
    {
        $error = (new EcsError(new \RuntimeException('boom', 7)))->toEcs();

        self::assertSame('RuntimeException', $error['error']['type']);
        self::assertSame('boom', $error['error']['message']);
        self::assertSame('7', $error['error']['code']);
        self::assertIsString($error['error']['stack_trace']);
    }
}
