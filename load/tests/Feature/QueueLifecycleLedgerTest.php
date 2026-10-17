<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Vaulter\Load\Listeners\RecordQueueLifecycle;

/**
 * @param array<string, string> $env
 */
function bindLedgerEnv(string $path, array $env = []): void
{
    $defaults = [
        'LOAD_QUEUE_LEDGER' => $path,
        'LOAD_RUN_ID' => 'run-ledger',
        'LOAD_CELL_ID' => 'product-realistic.relay.i20.f0.2xx',
        'LOAD_ATTEMPT' => '1',
        'LOAD_QUEUE_FINGERPRINT' => str_repeat('a', 64),
    ];

    foreach ([...$defaults, ...$env] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
    }
}

function clearLedgerEnv(): void
{
    foreach ([
        'LOAD_QUEUE_LEDGER',
        'LOAD_RUN_ID',
        'LOAD_CELL_ID',
        'LOAD_ATTEMPT',
        'LOAD_QUEUE_FINGERPRINT',
    ] as $name) {
        putenv($name);
        unset($_ENV[$name]);
    }
}

function ledgerJob(array $payload): object
{
    return new class($payload)
    {
        public function __construct(private array $payload) {}

        public function payload(): array
        {
            return $this->payload;
        }

        public function getQueue(): string
        {
            return 'default';
        }

        public function attempts(): int
        {
            return (int) ($this->payload['attempts'] ?? 0);
        }
    };
}

function readLedger(string $path): array
{
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));

    return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
}

afterEach(function (): void {
    clearLedgerEnv();
});

it('records uuid class and attempt without unserializing the command', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'p6-ledger-');
    bindLedgerEnv($path);

    $serialized = 'O:32:"Vaulter\\Webhooks\\Jobs\\DeliverWebhook":1:{s:6:"secret";s:11:"sign-me-not";}';
    $payload = [
        'uuid' => 'job-uuid-1',
        'displayName' => 'Vaulter\\Webhooks\\Jobs\\DeliverWebhook',
        'attempts' => 0,
        'data' => [
            'commandName' => 'Vaulter\\Webhooks\\Jobs\\DeliverWebhook',
            'command' => $serialized,
        ],
    ];
    $listener = new RecordQueueLifecycle;
    $listener->handleQueued(new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'job-uuid-1',
        job: 'Vaulter\\Webhooks\\Jobs\\DeliverWebhook',
        payload: json_encode($payload, JSON_THROW_ON_ERROR),
        delay: null,
    ));
    $processing = $payload;
    $processing['attempts'] = 1;
    $job = ledgerJob($processing);
    $listener->handleProcessing(new JobProcessing('redis', $job));
    $listener->handleProcessed(new JobProcessed('redis', $job));

    $rows = readLedger($path);
    $raw = (string) file_get_contents($path);

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['source'])->toBe('queued')
        ->and($rows[0]['outcome'])->toBe('queued')
        ->and($rows[0]['uuid'])->toBe('job-uuid-1')
        ->and($rows[0]['class'])->toBe('Vaulter\\Webhooks\\Jobs\\DeliverWebhook')
        ->and($rows[0]['job_attempt'])->toBe(0)
        ->and($rows[0]['queue'])->toBe('default')
        ->and($rows[0]['run_id'])->toBe('run-ledger')
        ->and($rows[1]['source'])->toBe('processing')
        ->and($rows[1]['job_attempt'])->toBe(1)
        ->and($rows[2]['source'])->toBe('processed')
        ->and($rows[2]['monotonic_ns'])->toBeGreaterThan($rows[0]['monotonic_ns'])
        ->and($raw)->not->toContain('sign-me-not')
        ->and($raw)->not->toContain('O:32:');

    @unlink($path);
});

it('records exception and failed outcomes without the exception message', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'p6-ledger-');
    bindLedgerEnv($path);
    $job = ledgerJob([
        'uuid' => 'job-uuid-2',
        'displayName' => 'Vaulter\\Webhooks\\Jobs\\DeliverWebhook',
        'attempts' => 1,
        'data' => ['command' => 'secret-body'],
    ]);
    $listener = new RecordQueueLifecycle;
    $listener->handleException(new JobExceptionOccurred('redis', $job, new RuntimeException('signature=abc body leaked')));
    $listener->handleFailed(new JobFailed('redis', $job, new RuntimeException('dsn=postgres://vaulter:secret@db/load')));

    $rows = readLedger($path);
    $raw = (string) file_get_contents($path);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['source'])->toBe('exception')
        ->and($rows[1]['source'])->toBe('failed')
        ->and($raw)->not->toContain('signature=')
        ->and($raw)->not->toContain('postgres://')
        ->and($raw)->not->toContain('secret-body');

    @unlink($path);
});

it('does not write when the ledger context is absent', function (): void {
    clearLedgerEnv();
    $listener = new RecordQueueLifecycle;
    $listener->handleQueued(new JobQueued('redis', 'default', 'id', 'Job', '{"uuid":"x"}', null));

    expect(getenv('LOAD_QUEUE_LEDGER'))->toBeFalse();
});

it('swallows listener failures so the job outcome is unchanged', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'p6-ledger-');
    bindLedgerEnv($path);
    $job = new class
    {
        public function payload(): array
        {
            throw new RuntimeException('payload exploded');
        }

        public function getQueue(): string
        {
            return 'default';
        }
    };
    $listener = new RecordQueueLifecycle;

    $listener->handleProcessing(new JobProcessing('redis', $job));

    expect(file_get_contents($path))->toBe('');

    @unlink($path);
});

it('records implicit default queue and joins dispatcher and worker through a context file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'p6-context-ledger-');
    $context = tempnam(sys_get_temp_dir(), 'p6-context-');
    config(['queue.connections.redis.queue' => 'default']);
    file_put_contents($context, json_encode([
        'path' => $path, 'run_id' => 'r09', 'cell_id' => 'cell',
        'attempt' => 2, 'fingerprint' => str_repeat('b', 64),
    ], JSON_THROW_ON_ERROR));
    putenv('LOAD_QUEUE_CONTEXT='.$context);
    $_ENV['LOAD_QUEUE_CONTEXT'] = $context;
    try {
        $payload = ['uuid' => 'shared-uuid', 'displayName' => 'Vaulter\\Webhooks\\Jobs\\DeliverWebhook'];
        $listener = new RecordQueueLifecycle;
        $listener->handleQueueing(new JobQueueing('redis', null, 'Job', json_encode($payload), null));
        $listener->handleProcessing(new JobProcessing('redis', ledgerJob($payload)));
        $listener->handleQueued(new JobQueued('redis', null, 'shared-uuid', 'Job', json_encode($payload), null));
        $rows = readLedger($path);
        expect($rows)->toHaveCount(3)
            ->and(array_column($rows, 'source'))->toBe(['queueing', 'processing', 'queued'])
            ->and(array_column($rows, 'uuid'))->toBe(['shared-uuid', 'shared-uuid', 'shared-uuid'])
            ->and(array_column($rows, 'run_id'))->toBe(['r09', 'r09', 'r09'])
            ->and(array_column($rows, 'attempt'))->toBe([2, 2, 2])
            ->and(array_column($rows, 'queue'))->toBe(['default', 'default', 'default']);
    } finally {
        putenv('LOAD_QUEUE_CONTEXT');
        unset($_ENV['LOAD_QUEUE_CONTEXT']);
        unlink($path);
        unlink($context);
    }
});
