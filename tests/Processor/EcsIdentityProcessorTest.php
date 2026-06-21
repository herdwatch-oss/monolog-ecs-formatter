<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Processor;

use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Herdwatch\MonologEcsFormatter\Processor\EcsIdentityProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class EcsIdentityProcessorTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private function createRecord(
        string $message = 'Test message',
        array $context = [],
        array $extra = [],
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function testServiceObjectInjectedIntoExtra(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))($this->createRecord());

        self::assertInstanceOf(Service::class, $result->extra['service']);
        self::assertSame(
            ['service' => ['name' => 'my-service', 'language' => 'php']],
            $result->extra['service']->toEcs(),
        );
    }

    public function testServiceNameIsInjectedValue(): void
    {
        $result = (new EcsIdentityProcessor('my-other-service'))($this->createRecord());

        self::assertSame('my-other-service', $result->extra['service']->toEcs()['service']['name']);
    }

    public function testProcessorDoesNotHandleExceptions(): void
    {
        // Exception → error.* is the formatter's job; the identity processor must not touch it.
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(context: ['exception' => new \RuntimeException('db down')]),
        );

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testProcessorDoesNotThrowOnOddContext(): void
    {
        $processor = new EcsIdentityProcessor('my-service');

        $this->expectNotToPerformAssertions();

        $processor($this->createRecord(context: ['exception' => new \stdClass()]));
        $processor($this->createRecord(context: ['exception' => 'a string']));
        $processor($this->createRecord(context: []));
    }

    public function testExistingServiceInExtraIsPreserved(): void
    {
        $existing = new Service('prior-svc', version: '2.0');
        $result = (new EcsIdentityProcessor('config-svc'))(
            $this->createRecord(extra: ['service' => $existing]),
        );

        self::assertSame($existing, $result->extra['service']);
        self::assertSame('prior-svc', $result->extra['service']->toEcs()['service']['name']);
    }

    public function testOriginalMessageUnmodified(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))($this->createRecord('Original message'));

        self::assertSame('Original message', $result->message);
    }

    public function testExistingExtraFieldsPreserved(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(extra: ['pid' => 1234, 'env' => 'staging']),
        );

        self::assertSame(1234, $result->extra['pid']);
        self::assertSame('staging', $result->extra['env']);
        self::assertInstanceOf(Service::class, $result->extra['service']);
    }
}
