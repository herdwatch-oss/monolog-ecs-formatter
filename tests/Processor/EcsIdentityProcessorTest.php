<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Processor;

use Herdwatch\MonologEcsFormatter\Processor\EcsIdentityProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class EcsIdentityProcessorTest extends TestCase
{
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

    // --- service.* always set ---

    public function testServiceNameAndLanguageAlwaysSet(): void
    {
        $processor = new EcsIdentityProcessor('my-service');
        $result = $processor($this->createRecord());

        self::assertArrayHasKey('service', $result->extra);
        self::assertSame('my-service', $result->extra['service']['name']);
        self::assertSame('php', $result->extra['service']['language']);
    }

    public function testServiceNameIsInjectedValue(): void
    {
        $processor = new EcsIdentityProcessor('my-other-service');
        $result = $processor($this->createRecord());

        self::assertSame('my-other-service', $result->extra['service']['name']);
    }

    public function testServiceLanguageDefaultsToPhp(): void
    {
        $processor = new EcsIdentityProcessor('svc');
        $result = $processor($this->createRecord());

        self::assertSame('php', $result->extra['service']['language']);
    }

    // --- error.* only on Throwable context exception ---

    public function testErrorFieldsSetWhenContextExceptionIsThrowable(): void
    {
        $exception = new \RuntimeException('DB connection failed');
        $processor = new EcsIdentityProcessor('my-service');

        $result = $processor($this->createRecord(context: ['exception' => $exception]));

        self::assertArrayHasKey('error', $result->extra);
        self::assertSame('DB connection failed', $result->extra['error']['message']);
        // getTraceAsString() starts with "#0 ..." — verify it is a non-empty trace string
        self::assertStringStartsWith('#0', $result->extra['error']['stack_trace']);
    }

    public function testErrorFieldsSetWhenContextExceptionIsError(): void
    {
        $error = new \TypeError('Type mismatch');
        $processor = new EcsIdentityProcessor('my-service');

        $result = $processor($this->createRecord(context: ['exception' => $error]));

        self::assertArrayHasKey('error', $result->extra);
        self::assertSame('Type mismatch', $result->extra['error']['message']);
    }

    public function testErrorStackTraceIsString(): void
    {
        $exception = new \InvalidArgumentException('Bad argument');
        $processor = new EcsIdentityProcessor('my-service');

        $result = $processor($this->createRecord(context: ['exception' => $exception]));

        self::assertIsString($result->extra['error']['stack_trace']);
        self::assertNotEmpty($result->extra['error']['stack_trace']);
    }

    // --- error.* NOT set when exception is absent or non-Throwable ---

    public function testNoErrorFieldWhenExceptionAbsent(): void
    {
        $processor = new EcsIdentityProcessor('my-service');
        $result = $processor($this->createRecord());

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testNoErrorFieldWhenExceptionIsString(): void
    {
        // Some MC call sites pass $e->getMessage() (a string) rather than the throwable itself.
        $processor = new EcsIdentityProcessor('my-service');
        $result = $processor($this->createRecord(context: ['exception' => 'error message string']));

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testNoErrorFieldWhenExceptionIsNull(): void
    {
        $processor = new EcsIdentityProcessor('my-service');
        $result = $processor($this->createRecord(context: ['exception' => null]));

        self::assertArrayNotHasKey('error', $result->extra);
    }

    public function testNoErrorFieldWhenExceptionIsInteger(): void
    {
        $processor = new EcsIdentityProcessor('my-service');
        $result = $processor($this->createRecord(context: ['exception' => 42]));

        self::assertArrayNotHasKey('error', $result->extra);
    }

    // --- processor is non-throwing ---

    public function testProcessorDoesNotThrow(): void
    {
        $processor = new EcsIdentityProcessor('my-service');

        $this->expectNotToPerformAssertions();

        // Pass various edge-case exception values — must not throw
        $processor($this->createRecord(context: ['exception' => new \stdClass()]));
        $processor($this->createRecord(context: ['exception' => []]));
        $processor($this->createRecord(context: []));
    }

    // --- original record fields unmodified ---

    public function testOriginalMessageUnmodified(): void
    {
        $processor = new EcsIdentityProcessor('my-service');
        $record = $this->createRecord('Original message');

        $result = $processor($record);

        self::assertSame('Original message', $result->message);
    }

    public function testExistingExtraFieldsPreserved(): void
    {
        $processor = new EcsIdentityProcessor('my-service');
        $record = $this->createRecord(extra: ['pid' => 1234, 'env' => 'staging']);

        $result = $processor($record);

        self::assertSame(1234, $result->extra['pid']);
        self::assertSame('staging', $result->extra['env']);
        self::assertArrayHasKey('service', $result->extra);
    }
}
