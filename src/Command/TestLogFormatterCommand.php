<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Command;

use Herdwatch\MonologEcsFormatter\Ecs\Client;
use Herdwatch\MonologEcsFormatter\Ecs\EcsError;
use Herdwatch\MonologEcsFormatter\Ecs\EcsField;
use Herdwatch\MonologEcsFormatter\Ecs\Event;
use Herdwatch\MonologEcsFormatter\Ecs\EventOutcome;
use Herdwatch\MonologEcsFormatter\Ecs\Host;
use Herdwatch\MonologEcsFormatter\Ecs\Http;
use Herdwatch\MonologEcsFormatter\Ecs\Labels;
use Herdwatch\MonologEcsFormatter\Ecs\Metrics;
use Herdwatch\MonologEcsFormatter\Ecs\Process;
use Herdwatch\MonologEcsFormatter\Ecs\SerializesToEcs;
use Herdwatch\MonologEcsFormatter\Ecs\Service;
use Herdwatch\MonologEcsFormatter\Ecs\Tags;
use Herdwatch\MonologEcsFormatter\Ecs\Text;
use Herdwatch\MonologEcsFormatter\Ecs\Tracing;
use Herdwatch\MonologEcsFormatter\Ecs\Url;
use Herdwatch\MonologEcsFormatter\Ecs\User;
use Herdwatch\MonologEcsFormatter\Ecs\UserAgent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'monolog-ecs:test',
    description: 'Emit sample log entries to verify EcsFieldsFormatter output.',
)]
class TestLogFormatterCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Emitting sample log entries...');

        // 1. Labels only (passed positionally — the key is irrelevant, detection is by type).
        $this->logger->notice('User login succeeded.', [
            Labels::create()->add('user_id', (string) random_int(1, 999))->add('env', 'staging'),
        ]);

        // 2. Metrics — the method fixes the JSON type (count/total → int, gauge → float, flag → bool).
        $this->logger->notice('Request processed.', [
            Metrics::create()
                ->gauge('duration_ms', round(random_int(50, 500) + lcg_value(), 1))
                ->count('retry_count', random_int(0, 5))
                ->total('payload_bytes', random_int(256, 8192))
                ->gauge('success_rate', round(lcg_value() * 0.5 + 0.5, 2))
                ->flag('is_cached', (bool) random_int(0, 1)),
        ]);

        // 3. Free-form text.
        $this->logger->warning('Validation failed.', [
            Text::create()->add('reason', 'Missing required field "email"'),
        ]);

        // 4. Mixed bags in one call — they merge by namespace.
        $this->logger->notice('Sync batch completed.', [
            Labels::create()->add('profile_id', 'P-' . random_int(100, 999))->add('sequence', 'nightly'),
            Metrics::create()->gauge('duration_ms', random_int(1000, 10000))->count('items_count', random_int(10, 500)),
            Tags::of('sync', 'batch'),
            'request_id' => 'req-' . bin2hex(random_bytes(4)), // plain context — stays under "context"
        ]);

        // 5. Identity objects: service / user / tracing.
        $this->logger->info('Authenticated request.', [
            new Service('demo-service', version: '1.4.0', environment: 'staging'),
            new User(id: random_int(1, 9999), email: 'farmer@example.com'),
            new Tracing(bin2hex(random_bytes(8)), bin2hex(random_bytes(4))),
        ]);

        // 5b. Standard ECS runtime / request context.
        $this->logger->info('Inbound API request.', [
            new Http(
                statusCode: 200,
                method: 'POST',
                requestBodyBytes: random_int(64, 4096),
                responseBodyBytes: random_int(64, 8192),
                requestMimeType: 'application/json',
                responseMimeType: 'application/json',
            ),
            new Process(pid: getmypid() ?: null, commandLine: 'bin/console monolog-ecs:test'),
            new Client(ip: '203.0.113.' . random_int(1, 254)),
            new UserAgent(device: 'iPhone', version: '4.2.1'),
            new Host(name: gethostname() ?: 'localhost'),
            Url::parse('https://app.herdwatch.com/herds/' . random_int(100, 999) . '?view=summary'),
            new Event(action: 'api.request', start: new \DateTimeImmutable(), outcome: EventOutcome::Success),
        ]);

        // 6. Exceptions via EcsError (captures error.type, message, code, stack_trace).
        $this->logger->error('Sync failed.', [
            new EcsError(new \RuntimeException('Upstream timed out', 504)),
            new Event(action: 'farm.sync', outcome: EventOutcome::Failure),
        ]);

        // 7. Building a bag from an existing array (the escape hatch — still validated/coerced).
        $this->logger->notice('Imported counters.', [
            Metrics::fromArray(['rows_total' => 1200, 'skipped_count' => 14, 'avg_ms' => 8.3]),
        ]);

        // 8. Over-cap + invalid key are never dropped — they fall to "context" with dotted keys (move mode).
        $manyLabels = Labels::create();
        for ($i = 1; $i <= 10; $i++) {
            $manyLabels->add("key$i", "val$i"); // cap is 8 → key9/key10 demoted to context.labels.*
        }
        $manyLabels->add('Bad Key', 'demoted'); // invalid key → demoted to context.'labels.Bad Key'
        $this->logger->notice('Cap and key-format demo.', [$manyLabels]);

        // 9. A project-specific EcsField is detected automatically (no registration needed).
        $farmContext = new class (random_int(1000, 9999), 'munster') implements EcsField {
            use SerializesToEcs;

            public function __construct(private int $herdId, private string $region)
            {
            }

            public function toEcs(): array
            {
                return ['farm' => ['herd_id' => $this->herdId, 'region' => $this->region]];
            }
        };
        $this->logger->notice('Herd sync complete.', [$farmContext]);

        // 10. Plain message, no structured fields.
        $this->logger->notice('Plain message with no structured fields.', [
            'action' => 'export',
        ]);

        // 11. Message with newlines (NDJSON must keep it on one line).
        $this->logger->error("Multi-line error message.\nStack trace line 1.\nStack trace line 2.", [
            Labels::create()->add('component', 'parser'),
        ]);

        $output->writeln('Done. Check log output for formatted entries.');

        return Command::SUCCESS;
    }
}
