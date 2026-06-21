<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Command;

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

        // 1. Labels only
        $this->logger->notice('User login succeeded.', [
            'labels' => ['user_id' => (string) random_int(1, 999), 'env' => 'staging'],
        ]);

        // 2. Metrics with known suffixes
        $this->logger->notice('Request processed.', [
            'metric' => [
                'duration_ms' => round(random_int(50, 500) + lcg_value(), 1),
                'retry_count' => random_int(0, 5),
                'payload_bytes' => random_int(256, 8192),
                'success_rate' => round(lcg_value() * 0.5 + 0.5, 2),
            ],
        ]);

        // 3. Boolean metrics (is_* keys are coerced to bool and promoted under metric)
        $this->logger->notice('Cache lookup.', [
            'metric' => ['is_cached' => (bool) random_int(0, 1), 'is_retry' => (bool) random_int(0, 1)],
        ]);

        // 4. Text
        $this->logger->warning('Validation failed.', [
            'text' => ['reason' => 'Missing required field "email"'],
        ]);

        // 5. Tags (ECS base field: flat array of keyword strings)
        $this->logger->notice('Incoming API request.', [
            'tags' => ['web', 'api', 'production'],
            'labels' => ['endpoint' => '/api/v1/sync'],
        ]);

        // 6. Mixed: labels + metrics + tags + non-extractable context
        $this->logger->notice('Sync batch completed.', [
            'labels' => ['profile_id' => 'P-' . random_int(100, 999), 'sequence' => 'nightly'],
            'metric' => ['duration_ms' => random_int(1000, 10000), 'items_count' => random_int(10, 500)],
            'tags' => ['sync', 'batch'],
            'request_id' => 'req-' . bin2hex(random_bytes(4)),
        ]);

        // 7. Metric validation: type coercion and rejection
        $this->logger->notice('Metric validation.', [
            'metric' => [
                'duration_ms' => random_int(50, 300),   // numeric → double
                'latency_ms' => 'not-a-number',         // non-numeric → context
                'batch_count' => round(random_int(5, 15) + lcg_value(), 1), // fractional _count → context (not a whole number)
                'custom_ms' => round(random_int(10, 100) + lcg_value(), 1), // numeric → double
            ],
        ]);

        // 8. Key format validation: rejected key formats land in context
        $this->logger->notice('Key format validation.', [
            'labels' => [
                'env' => 'prod',                    // valid: single segment
                'user_id' => '42',                  // valid: two segments
                'User_Id' => 'rejected',            // invalid: uppercase
                'a_b_c_d' => 'rejected',            // invalid: 4 segments
            ],
        ]);

        // 9. Nested values (should remain in context)
        $readMs = random_int(30, 150);
        $writeMs = random_int(50, 200);
        $this->logger->notice('Nested value test.', [
            'labels' => [
                'env' => 'prod',
                'tags' => ['web', 'api'],
            ],
            'metric' => [
                'duration_ms' => $readMs + $writeMs,
                'breakdown' => ['read_ms' => $readMs, 'write_ms' => $writeMs],
            ],
        ]);

        // 10. Flat context with no extractable namespaces (kept as-is under context)
        $this->logger->notice('Plain message with no structured fields.', [
            'user_id' => 42,
            'action' => 'export',
        ]);

        // 11. Message with newlines
        $this->logger->error("Multi-line error message.\nStack trace line 1.\nStack trace line 2.", [
            'labels' => ['component' => 'parser'],
        ]);

        $output->writeln('Done. Check log output for formatted entries.');

        return Command::SUCCESS;
    }
}
