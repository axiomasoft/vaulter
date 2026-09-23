<?php

declare(strict_types=1);

namespace Vaulter\Load\Listeners;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Throwable;

/**
 * Append-only queue lifecycle ledger for the load stand (P6.1).
 *
 * Records uuid, class, attempt and outcome. Never unserializes the job command
 * and never writes payloads, signatures or DSNs. A ledger failure is swallowed
 * so job execution is unchanged.
 */
final class RecordQueueLifecycle
{
    private const int LINE_CAP = 2048;

    public function handleQueued(JobQueued $event): void
    {
        $this->guard(function () use ($event): void {
            $payload = $this->decodePayload($event->payload());
            $this->append(
                source: 'queued',
                outcome: 'queued',
                queue: $this->queueName($event->queue, null),
                payload: $payload,
                job: $event->job,
                fallbackId: $event->id,
            );
        });
    }

    public function handleProcessing(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $this->appendFromJob($event->job, 'processing', 'started');
        });
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->guard(function () use ($event): void {
            $this->appendFromJob($event->job, 'processed', 'processed');
        });
    }

    public function handleException(JobExceptionOccurred $event): void
    {
        $this->guard(function () use ($event): void {
            $this->appendFromJob($event->job, 'exception', 'exception');
        });
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $this->appendFromJob($event->job, 'failed', 'failed');
        });
    }

    private function appendFromJob(object $job, string $source, string $outcome): void
    {
        $payload = [];

        if (method_exists($job, 'payload')) {
            $decoded = $job->payload();
            $payload = is_array($decoded) ? $decoded : [];
        }

        $queue = null;

        if (method_exists($job, 'getQueue')) {
            $queue = $job->getQueue();
        }

        $this->append(
            source: $source,
            outcome: $outcome,
            queue: $this->queueName($queue, null),
            payload: $payload,
            job: $job,
            fallbackId: null,
        );
    }

    /**
     * @param array<mixed> $payload
     */
    private function append(
        string $source,
        string $outcome,
        string $queue,
        array $payload,
        mixed $job,
        string|int|null $fallbackId,
    ): void {
        $context = $this->context();

        if ($context === null || $queue === '') {
            return;
        }

        $uuid = $payload['uuid'] ?? $fallbackId;

        if (!is_string($uuid) || $uuid === '' || str_contains($uuid, "\n")) {
            return;
        }

        $record = [
            'schema' => 'p6-lifecycle/v1',
            'run_id' => $context['run_id'],
            'cell_id' => $context['cell_id'],
            'attempt' => $context['attempt'],
            'monotonic_ns' => hrtime(true),
            'wall_time' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'source' => $source,
            'queue' => $queue,
            'uuid' => $uuid,
            'class' => $this->className($payload, $job),
            'job_attempt' => $this->jobAttempt($payload, $job),
            'outcome' => $outcome,
            'config_fingerprint' => $context['fingerprint'],
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (!is_string($line) || strlen($line) > self::LINE_CAP) {
            return;
        }

        $path = $context['path'];
        $directory = dirname($path);

        if (!is_dir($directory) || !is_writable($directory)) {
            return;
        }

        file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<mixed> $payload
     */
    private function className(array $payload, mixed $job): string
    {
        $display = $payload['displayName'] ?? null;

        if (is_string($display) && $display !== '') {
            return $this->cap($display);
        }

        $data = $payload['data'] ?? null;
        $commandName = is_array($data) ? ($data['commandName'] ?? null) : null;

        if (is_string($commandName) && $commandName !== '') {
            return $this->cap($commandName);
        }

        if (is_object($job)) {
            return $this->cap($job::class);
        }

        if (is_string($job) && $job !== '') {
            return $this->cap($job);
        }

        return 'unknown';
    }

    /**
     * @param array<mixed> $payload
     */
    private function jobAttempt(array $payload, mixed $job): int
    {
        $attempts = $payload['attempts'] ?? null;

        if (is_int($attempts)) {
            return $attempts;
        }

        if (is_string($attempts) && ctype_digit($attempts)) {
            return (int) $attempts;
        }

        if (is_object($job) && method_exists($job, 'attempts')) {
            return max(0, (int) $job->attempts());
        }

        return 0;
    }

    /**
     * @return array{path: string, run_id: string, cell_id: string, attempt: int, fingerprint: string}|null
     */
    private function context(): ?array
    {
        $path = $this->env('LOAD_QUEUE_LEDGER');
        $runId = $this->env('LOAD_RUN_ID');
        $cellId = $this->env('LOAD_CELL_ID');
        $attempt = $this->env('LOAD_ATTEMPT');
        $fingerprint = $this->env('LOAD_QUEUE_FINGERPRINT');

        if ($path === '' || $runId === '' || $cellId === '' || $fingerprint === '' || !ctype_digit($attempt)) {
            return null;
        }

        return [
            'path' => $path,
            'run_id' => $runId,
            'cell_id' => $cellId,
            'attempt' => (int) $attempt,
            'fingerprint' => $fingerprint,
        ];
    }

    private function queueName(mixed $primary, mixed $fallback): string
    {
        $queue = is_string($primary) && $primary !== '' ? $primary : $fallback;

        if (!is_string($queue) || $queue === '' || str_contains($queue, "\n") || strlen($queue) > 128) {
            return '';
        }

        return $queue;
    }

    private function cap(string $value): string
    {
        $value = str_replace(["\n", "\r"], '', $value);

        return strlen($value) > 200 ? substr($value, 0, 200) : $value;
    }

    /**
     * @return array<mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (!is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Ledger I/O must not change job success, failure or retry.
        }
    }
}
