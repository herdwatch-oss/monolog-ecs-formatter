<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Tests\Ecs;

use Herdwatch\MonologEcsFormatter\Ecs\Client;
use Herdwatch\MonologEcsFormatter\Ecs\Event;
use Herdwatch\MonologEcsFormatter\Ecs\EventCategory;
use Herdwatch\MonologEcsFormatter\Ecs\EventOutcome;
use Herdwatch\MonologEcsFormatter\Ecs\EventType;
use Herdwatch\MonologEcsFormatter\Ecs\Host;
use Herdwatch\MonologEcsFormatter\Ecs\Http;
use Herdwatch\MonologEcsFormatter\Ecs\Process;
use Herdwatch\MonologEcsFormatter\Ecs\Url;
use Herdwatch\MonologEcsFormatter\Ecs\UserAgent;
use PHPUnit\Framework\TestCase;

class StandardEcsTypesTest extends TestCase
{
    public function testHttpUppercasesMethodAndOmitsNulls(): void
    {
        self::assertSame(
            ['http' => ['request' => ['method' => 'POST'], 'response' => ['status_code' => 500]]],
            (new Http(statusCode: 500, method: 'post'))->toEcs(),
        );

        self::assertSame(['http' => ['response' => ['status_code' => 404]]], (new Http(statusCode: 404))->toEcs());
        self::assertSame([], (new Http())->toEcs());
    }

    public function testHttpBodyBytesAndMimeTypeNestUnderRequestAndResponse(): void
    {
        self::assertSame(
            ['http' => [
                'request' => ['method' => 'POST', 'body' => ['bytes' => 12], 'mime_type' => 'application/json'],
                'response' => ['status_code' => 200, 'body' => ['bytes' => 340], 'mime_type' => 'application/json'],
            ]],
            (new Http(
                statusCode: 200,
                method: 'POST',
                requestBodyBytes: 12,
                responseBodyBytes: 340,
                requestMimeType: 'application/json',
                responseMimeType: 'application/json',
            ))->toEcs(),
        );
    }

    public function testHttpBodyOnlyOmitsMethodAndStatus(): void
    {
        // Body bytes without method/status still nest correctly and omit the empty siblings.
        self::assertSame(
            ['http' => ['request' => ['body' => ['bytes' => 12]], 'response' => ['body' => ['bytes' => 340]]]],
            (new Http(requestBodyBytes: 12, responseBodyBytes: 340))->toEcs(),
        );
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

    public function testEventOutcomeIsAddedAdditively(): void
    {
        self::assertSame(
            ['event' => ['action' => 'farm.sync', 'outcome' => 'failure']],
            (new Event(action: 'farm.sync', outcome: EventOutcome::Failure))->toEcs(),
        );
        self::assertSame(['event' => ['outcome' => 'success']], (new Event(outcome: EventOutcome::Success))->toEcs());
    }

    public function testEventReasonIsAddedAdditively(): void
    {
        self::assertSame(
            ['event' => ['action' => 'farm.sync', 'outcome' => 'failure', 'reason' => 'threshold']],
            (new Event(action: 'farm.sync', outcome: EventOutcome::Failure, reason: 'threshold'))->toEcs(),
        );
        self::assertSame(['event' => ['reason' => 'threshold']], (new Event(reason: 'threshold'))->toEcs());
    }

    public function testEventOutcomeEnumCoversTheEcsAllowedValues(): void
    {
        // The ECS-mandated closed set, regardless of declaration order.
        self::assertEqualsCanonicalizing(['success', 'failure', 'unknown'], array_map(
            static fn (EventOutcome $o): string => $o->value,
            EventOutcome::cases(),
        ));
    }

    public function testEventEndSequenceAndUrl(): void
    {
        self::assertSame(
            ['event' => [
                'end' => '2026-06-21T12:00:00.500000+00:00',
                'sequence' => 42,
                'url' => 'https://sync.example.test/reports?run=abc',
            ]],
            (new Event(
                end: new \DateTimeImmutable('2026-06-21T12:00:00.500000+00:00'),
                sequence: 42,
                url: 'https://sync.example.test/reports?run=abc',
            ))->toEcs(),
        );
    }

    public function testEventTypeAndCategoryEmitAsValueArrays(): void
    {
        self::assertSame(
            ['event' => ['type' => ['creation'], 'category' => ['api']]],
            (new Event(type: [EventType::Creation], category: [EventCategory::Api]))->toEcs(),
        );
    }

    public function testEventTypeDeduplicatesPreservingOrder(): void
    {
        self::assertSame(
            ['event' => ['type' => ['start', 'end']]],
            (new Event(type: [EventType::Start, EventType::End, EventType::Start]))->toEcs(),
        );
    }

    public function testEventKitchenSinkFieldShape(): void
    {
        $start = new \DateTimeImmutable('2026-06-21T11:59:59.250000+00:00');
        $end = new \DateTimeImmutable('2026-06-21T12:00:00.500000+00:00');

        self::assertSame(
            ['event' => [
                'action' => 'farm-sync',
                'start' => '2026-06-21T11:59:59.250000+00:00',
                'end' => '2026-06-21T12:00:00.500000+00:00',
                'duration' => 1_250_000_000,
                'sequence' => 7,
                'outcome' => 'failure',
                'reason' => 'threshold',
                'url' => 'https://sync.example.test/reports?run=abc',
                'type' => ['change'],
                'category' => ['api'],
            ]],
            (new Event(
                action: 'farm-sync',
                start: $start,
                durationNanos: 1_250_000_000,
                outcome: EventOutcome::Failure,
                reason: 'threshold',
                end: $end,
                sequence: 7,
                type: [EventType::Change],
                category: [EventCategory::Api],
                url: 'https://sync.example.test/reports?run=abc',
            ))->toEcs(),
        );
    }

    public function testEventTypeEnumCoversTheEcsAllowedValues(): void
    {
        // The ECS-mandated closed set, regardless of declaration order.
        self::assertEqualsCanonicalizing(
            ['access', 'admin', 'allowed', 'change', 'connection', 'creation', 'deletion', 'denied', 'end', 'error', 'group', 'indicator', 'info', 'installation', 'protocol', 'start', 'user'],
            array_map(static fn (EventType $t): string => $t->value, EventType::cases()),
        );
    }

    public function testEventCategoryEnumCoversTheEcsAllowedValues(): void
    {
        // The ECS-mandated closed set, regardless of declaration order.
        self::assertEqualsCanonicalizing(
            ['api', 'authentication', 'configuration', 'database', 'driver', 'email', 'file', 'host', 'iam', 'intrusion_detection', 'library', 'malware', 'network', 'package', 'process', 'registry', 'session', 'threat', 'vulnerability', 'web'],
            array_map(static fn (EventCategory $c): string => $c->value, EventCategory::cases()),
        );
    }

    public function testEventFormatsStartAsIso8601String(): void
    {
        // Rendered as a string in toEcs() (not a raw DateTime), so it serialises identically through
        // EcsFieldsFormatter and any other handler.
        self::assertSame(
            ['event' => ['start' => '2026-06-21T11:59:59.250000+00:00']],
            (new Event(start: new \DateTimeImmutable('2026-06-21T11:59:59.250000+00:00')))->toEcs(),
        );
    }

    public function testUrlOmitsNulls(): void
    {
        self::assertSame(
            ['url' => ['domain' => 'app.herdwatch.com', 'path' => '/herds/42', 'query' => 'view=summary']],
            (new Url(path: '/herds/42', domain: 'app.herdwatch.com', query: 'view=summary'))->toEcs(),
        );

        self::assertSame([], (new Url())->toEcs());
    }

    public function testUrlParseSplitsComponents(): void
    {
        self::assertSame(
            ['url' => [
                'full' => 'https://app.herdwatch.com:8443/herds/42?view=summary#notes',
                'scheme' => 'https',
                'domain' => 'app.herdwatch.com',
                'port' => 8443,
                'path' => '/herds/42',
                'query' => 'view=summary',
                'fragment' => 'notes',
            ]],
            Url::parse('https://app.herdwatch.com:8443/herds/42?view=summary#notes')->toEcs(),
        );
    }

    public function testUrlParseKeepsUnparseableValueAsFull(): void
    {
        // parse_url() rejects a URL with a malformed port; the raw value is still kept, not lost.
        self::assertSame(['url' => ['full' => 'http://:-1']], Url::parse('http://:-1')->toEcs());
    }

    public function testUrlParseRecordsTheUrlVerbatimAsFull(): void
    {
        // Redaction is the caller's responsibility: parse() faithfully keeps the URL it is given
        // (credentials and all) so url.full matches the real request URL.
        $url = Url::parse('https://app.herdwatch.com:8443/herds/42?view=summary')->toEcs();

        self::assertSame('https://app.herdwatch.com:8443/herds/42?view=summary', $url['url']['full']);
        self::assertSame('app.herdwatch.com', $url['url']['domain']);
    }

    public function testUrlSchemeIsLowercased(): void
    {
        self::assertSame('https', Url::parse('HTTPS://X.TEST/p')->toEcs()['url']['scheme']);
        self::assertSame('http', (new Url(scheme: 'HTTP'))->toEcs()['url']['scheme']);
    }

    public function testUrlOmitsEmptyStringComponents(): void
    {
        // A trailing '?' yields an empty query from parse_url; it should be omitted, not emitted as "".
        self::assertArrayNotHasKey('query', Url::parse('https://x.test/p?')->toEcs()['url']);
        // An empty input has no meaningful parts at all.
        self::assertSame([], Url::parse('')->toEcs());
    }

    public function testUrlConstructorMapsPortAndFragment(): void
    {
        self::assertSame(
            ['url' => ['scheme' => 'https', 'port' => 8443, 'path' => '/p', 'fragment' => 'top']],
            (new Url(path: '/p', scheme: 'https', port: 8443, fragment: 'top'))->toEcs(),
        );
    }
}
