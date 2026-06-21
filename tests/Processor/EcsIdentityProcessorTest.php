<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Processor;

use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
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

    // --- service object always injected ---

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

    // --- error object only on a Throwable context exception ---

    public function testErrorObjectInjectedWhenContextExceptionIsThrowable(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(context: ['exception' => new \RuntimeException('DB connection failed')]),
        );

        self::assertInstanceOf(EcsError::class, $result->extra['error']);

        $error = $result->extra['error']->toEcs()['error'];
        self::assertSame('RuntimeException', $error['type']);
        self::assertSame('DB connection failed', $error['message']);
        self::assertIsString($error['stack_trace']);
    }

    public function testErrorObjectInjectedWhenContextExceptionIsError(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(context: ['exception' => new \TypeError('Type mismatch')]),
        );

        self::assertSame('Type mismatch', $result->extra['error']->toEcs()['error']['message']);
    }

    // --- error NOT injected when absent / non-Throwable ---

    public function testNoErrorWhenExceptionAbsent(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))($this->createRecord());

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testNoErrorWhenExceptionIsString(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(context: ['exception' => 'error message string']),
        );

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testNoErrorWhenExceptionIsNull(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(context: ['exception' => null]),
        );

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testNoErrorWhenExceptionIsInteger(): void
    {
        $result = (new EcsIdentityProcessor('my-service'))(
            $this->createRecord(context: ['exception' => 42]),
        );

        self::assertArrayNotHasKey('error', $result->extra);
    }

    // --- non-throwing ---

    public function testProcessorDoesNotThrow(): void
    {
        $processor = new EcsIdentityProcessor('my-service');

        $this->expectNotToPerformAssertions();

        $processor($this->createRecord(context: ['exception' => new \stdClass()]));
        $processor($this->createRecord(context: ['exception' => []]));
        $processor($this->createRecord(context: []));
    }

    // --- original record preserved ---

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
