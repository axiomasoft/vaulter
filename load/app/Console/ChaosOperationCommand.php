<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;
use Vaulter\Enums\Webhooks\WebhookEvent;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Webhooks\OutboxRecorder;

/**
 * Small real-product probes for operations that have no public HTTP surface.
 *
 * Upload, multipart, abort, outbox and sweeper cells use their public HTTP/Artisan
 * entry points directly. This command covers only CAS local staging, an explicit
 * bounded write and the PostgreSQL advisory-lock mutation used by lock-holder cells.
 */
final class ChaosOperationCommand extends Command
{
    protected $signature = 'load:chaos-operation
        {operation : cas-stage|write|lock-holder}
        {--run-prefix= : Run-scoped durable identity}
        {--bytes=4096 : Requested byte count}
        {--ordinal=1 : Product-attempt ordinal}
        {--hold-lock : Acknowledge the acquired lock and wait for scoped release on stdin}
        {--hold-timeout=240 : Maximum lock hold in seconds}';

    protected $description = 'Run one bounded P7 product operation and print a measured JSON result.';

    private ?array $lockBoundary = null;

    public function handle(Sha256ContentStore $content, OutboxRecorder $outbox): int
    {
        $this->lockBoundary = null;
        $operation = (string) $this->argument('operation');
        $runPrefix = ChaosFixtureSupport::sanitizeToken((string) $this->option('run-prefix'));
        $requested = max(1, min((int) $this->option('bytes'), 4 * 1024 * 1024));
        $ordinal = max(1, (int) $this->option('ordinal'));

        try {
            $result = match ($operation) {
                'cas-stage' => $this->stage($content, $runPrefix, $requested, $ordinal),
                'write' => $this->write($runPrefix, $requested, $ordinal),
                'lock-holder' => $this->lockHolder($outbox, $runPrefix, $requested, $ordinal),
                default => throw new \RuntimeException("Unknown chaos operation [{$operation}]."),
            };

            $this->line($this->encode([
                'operation' => $operation,
                'run_prefix' => $runPrefix,
                'ordinal' => $ordinal,
                ...$result,
                'error_class' => null,
                ...$this->lockEvidence(),
            ]));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line($this->encode([
                'operation' => $operation,
                'run_prefix' => $runPrefix,
                'ordinal' => $ordinal,
                'requested_bytes' => $requested,
                'written_bytes' => $this->writtenAfterFailure($operation, $runPrefix, $ordinal),
                'error_class' => $exception::class,
                ...$this->lockEvidence(),
            ]));

            return self::FAILURE;
        }
    }

    /**
     * @return array{requested_bytes: int, written_bytes: int, entity_id: string}
     */
    private function stage(
        Sha256ContentStore $content,
        string $runPrefix,
        int $requested,
        int $ordinal,
    ): array {
        $source = $this->source($runPrefix, $requested, $ordinal);
        $staged = $content->stage($source);

        try {
            clearstatcache(true, $staged->path());
            $written = filesize($staged->path());

            return [
                'requested_bytes' => $requested,
                'written_bytes' => $written === false ? 0 : $written,
                'entity_id' => $staged->sha256(),
            ];
        } finally {
            fclose($source);
            $staged->discard();
        }
    }

    /**
     * @return array{requested_bytes: int, written_bytes: int, entity_id: string}
     */
    private function write(string $runPrefix, int $requested, int $ordinal): array
    {
        $path = $this->writePath($runPrefix, $ordinal);
        $stream = @fopen($path, 'wb');

        if ($stream === false) {
            throw new \RuntimeException("Unable to open bounded chaos write [{$path}].");
        }

        $written = 0;
        $chunk = str_repeat('w', min($requested, 65_536));

        try {
            while ($written < $requested) {
                $result = @fwrite($stream, substr($chunk, 0, min(strlen($chunk), $requested - $written)));

                if ($result === false || $result === 0) {
                    break;
                }

                $written += $result;
            }

            if (!@fflush($stream)) {
                throw new \RuntimeException('Bounded chaos write could not be flushed.');
            }
        } finally {
            fclose($stream);
        }

        clearstatcache(true, $path);
        $size = filesize($path);

        if ($size === false || $size !== $requested) {
            throw new \RuntimeException("Short chaos write: requested {$requested}, persisted ".($size ?: 0).'.');
        }

        return [
            'requested_bytes' => $requested,
            'written_bytes' => $size,
            'entity_id' => $path,
        ];
    }

    /**
     * @return array{requested_bytes: int, written_bytes: int, entity_id: string}
     */
    private function lockHolder(
        OutboxRecorder $outbox,
        string $runPrefix,
        int $requested,
        int $ordinal,
    ): array {
        $eventId = "{$runPrefix}:lock-holder:{$ordinal}";

        DB::transaction(function () use ($outbox, $eventId, $runPrefix, $ordinal): void {
            if ($this->option('hold-lock') && DB::getDriverName() !== 'pgsql') {
                throw new \RuntimeException('A held lock boundary requires PostgreSQL.');
            }
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$eventId]);
            }

            if ($this->option('hold-lock')) {
                $backend = DB::selectOne('select pg_backend_pid() as backend_pid, current_database() as database');
                $this->lockBoundary = [
                    'schema' => 'p7-lock-holder-ready/v1',
                    'run_prefix' => $runPrefix,
                    'ordinal' => $ordinal,
                    'event_id' => $eventId,
                    'backend_pid' => (int) $backend->backend_pid,
                    'database' => $backend->database,
                    'lock_acquired' => true,
                ];
                $this->line($this->encode($this->lockBoundary));
                fflush(STDOUT);
                $this->waitForLockRelease($runPrefix, $ordinal, $eventId);
                $current = DB::selectOne('select pg_backend_pid() as backend_pid');
                if ((int) $current->backend_pid !== $this->lockBoundary['backend_pid']) {
                    throw new \RuntimeException('The held transaction changed DB backend.');
                }
            }

            $outbox->stage(
                event: WebhookEvent::NodeCreated,
                key: $eventId,
                panel: 'load',
                topic: $runPrefix,
                payload: ['probe' => 'lock-holder'],
                actorId: null,
            );
        });

        return [
            'requested_bytes' => $requested,
            'written_bytes' => $requested,
            'entity_id' => $eventId,
        ];
    }

    private function waitForLockRelease(string $runPrefix, int $ordinal, string $eventId): void
    {
        $timeout = max(1, min(240, (int) $this->option('hold-timeout')));
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $line = '';
        stream_set_blocking(STDIN, false);
        while (!str_contains($line, "\n")) {
            $remaining = $deadline - hrtime(true);
            $read = [STDIN];
            $write = $except = [];
            if ($remaining <= 0 || stream_select(
                $read,
                $write,
                $except,
                intdiv($remaining, 1_000_000_000),
                intdiv($remaining % 1_000_000_000, 1000),
            ) !== 1) {
                throw new \RuntimeException('The held lock timed out awaiting release.');
            }
            $chunk = fread(STDIN, 4096);
            if ($chunk === false || $chunk === '' || strlen($line) + strlen($chunk) > 4096) {
                throw new \RuntimeException('The held lock received an invalid control frame.');
            }
            $line .= $chunk;
        }
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($message)
            || ($message['schema'] ?? null) !== 'p7-lock-holder-control/v1'
            || ($message['run_prefix'] ?? null) !== $runPrefix
            || ($message['ordinal'] ?? null) !== $ordinal
            || ($message['event_id'] ?? null) !== $eventId
            || ($message['action'] ?? null) !== 'release') {
            throw new \RuntimeException('The held lock was cancelled or received a foreign release.');
        }
    }

    private function lockEvidence(): array
    {
        if (!$this->option('hold-lock')) {
            return [];
        }

        return ['schema' => 'p7-lock-holder-result/v1', 'lock_boundary' => $this->lockBoundary];
    }

    /**
     * @return resource
     */
    private function source(string $runPrefix, int $requested, int $ordinal): mixed
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open the chaos probe source stream.');
        }

        $seed = "{$runPrefix}|{$ordinal}|";
        $chunk = str_repeat($seed, (int) ceil(65_536 / max(1, strlen($seed))));
        $remaining = $requested;

        while ($remaining > 0) {
            $piece = substr($chunk, 0, min($remaining, strlen($chunk)));
            if (fwrite($stream, $piece) !== strlen($piece)) {
                fclose($stream);
                throw new \RuntimeException('Unable to build the bounded chaos probe source.');
            }
            $remaining -= strlen($piece);
        }

        rewind($stream);

        return $stream;
    }

    private function writePath(string $runPrefix, int $ordinal): string
    {
        return "/var/tmp/vaulter-chaos/{$runPrefix}-write-{$ordinal}.bin";
    }

    private function writtenAfterFailure(string $operation, string $runPrefix, int $ordinal): int
    {
        if ($operation !== 'write') {
            return 0;
        }

        $path = $this->writePath($runPrefix, $ordinal);
        clearstatcache(true, $path);
        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    private function encode(array $result): string
    {
        try {
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode the chaos operation result.', 0, $exception);
        }
    }
}
