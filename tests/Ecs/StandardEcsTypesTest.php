<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\Client;
use Herdwatch\MonologEcsFormatter\Ecs\Event;
use Herdwatch\MonologEcsFormatter\Ecs\Host;
use Herdwatch\MonologEcsFormatter\Ecs\Http;
use Herdwatch\MonologEcsFormatter\Ecs\Process;
use Herdwatch\MonologEcsFormatter\Ecs\UserAgent;
use PHPUnit\Framework\TestCase;

class StandardEcsTypesTest extends TestCase
{
    public function testHttpUppercasesMethodAndOmitsNulls(): void
    {
        self::assertSame(
            ['http' => ['response' => ['status_code' => 500], 'request' => ['method' => 'POST']]],
            (new Http(statusCode: 500, method: 'post'))->toEcs(),
        );

        self::assertSame(['http' => ['response' => ['status_code' => 404]]], (new Http(statusCode: 404))->toEcs());
        self::assertSame([], (new Http())->toEcs());
    }

    public function testProcess(): void
    {
        self::assertSame(
            ['process' => ['pid' => 42, 'command_line' => 'bin/console x']],
            (new Process(pid: 42, commandLine: 'bin/console x'))->toEcs(),
        );

        self::assertSame([], (new Process())->toEcs());
    }

    public function testClient(): void
    {
        self::assertSame(['client' => ['ip' => '10.0.0.1', 'port' => 443]], (new Client(ip: '10.0.0.1', port: 443))->toEcs());
        self::assertSame([], (new Client())->toEcs());
    }

    public function testUserAgent(): void
    {
        self::assertSame(
            ['user_agent' => ['version' => '4.2.1', 'device' => ['name' => 'iPhone']]],
            (new UserAgent(version: '4.2.1', device: 'iPhone'))->toEcs(),
        );
    }

    public function testHost(): void
    {
        self::assertSame(['host' => ['name' => 'web-1']], (new Host(name: 'web-1'))->toEcs());
    }

    public function testEventOmitsNulls(): void
    {
        self::assertSame(['event' => ['action' => 'farm.sync']], (new Event(action: 'farm.sync'))->toEcs());
        self::assertSame([], (new Event())->toEcs());
    }
}
