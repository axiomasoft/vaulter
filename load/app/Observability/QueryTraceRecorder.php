<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;

/**
 * Load-host-only query trace (P3.1). Opt-in via LOAD_DB_TRACE; never stores bindings or rendered SQL.
 */
final class QueryTraceRecorder
{
    private readonly RoutesConfig $routesConfig;

    private bool $registered = false;

    /** @var list<array<string, mixed>> */
    private array $records = [];

    private int $ordinal = 0;

    private int $transactionDepth = 0;

    private ?string $commandId = null;

    private ?string $invocationId = null;

    public function __construct(?RoutesConfig $routesConfig = null)
    {
        $this->routesConfig = $routesConfig ?? app(RoutesConfig::class);
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        DB::listen(function ($query): void {
            $this->recordQuery(
                connection: (string) $query->connectionName,
                sql: (string) $query->sql,
                durationMs: (float) $query->time,
            );
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->beginInvocation();
            $this->commandId = 'artisan:'.$event->command;
        });
        Event::listen(CommandFinished::class, fn () => $this->flush());
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->beginInvocation();
            $this->commandId = 'job:'.get_class($event->job);
        });
        Event::listen(JobProcessed::class, fn () => $this->flush());
        Event::listen(JobFailed::class, fn () => $this->flush());
        Event::listen(JobExceptionOccurred::class, fn () => $this->flush());

        Event::listen(TransactionBeginning::class, fn () => $this->transactionDepth++);
        Event::listen(TransactionCommitted::class, fn () => $this->transactionDepth = max(0, $this->transactionDepth - 1));
        Event::listen(TransactionRolledBack::class, fn () => $this->transactionDepth = max(0, $this->transactionDepth - 1));
    }

    public function bindHttpRequest(Request $request): void
    {
        $this->beginInvocation();
        $operation = $this->operationFor($request);
        $this->commandId = $operation === null ? 'http:unmapped' : 'http:'.$operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function flush(): void
    {
        $path = (string) env('LOAD_DB_TRACE_PATH', '');

        if ($path === '' || $this->records === []) {
            return;
        }

        $lines = array_map(
            static fn (array $row): string => (string) json_encode($row, JSON_THROW_ON_ERROR),
            $this->records,
        );

        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (file_put_contents($path, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Could not append P3 query trace');
        }

        $this->clear();
    }

    public function clear(): void
    {
        $this->records = [];
        $this->ordinal = 0;
        $this->commandId = null;
        $this->invocationId = null;
    }

    private function beginInvocation(): void
    {
        $this->clear();
        $this->invocationId = bin2hex(random_bytes(8));
    }

    private function recordQuery(string $connection, string $sql, float $durationMs): void
    {
        $runId = (string) env('LOAD_RUN_ID', '');

        if ($runId === '') {
            return;
        }

        $this->ordinal++;

        $this->records[] = [
            'run_id' => $runId,
            'command_id' => $this->commandId ?? 'unknown',
            'invocation_id' => $this->invocationId ??= bin2hex(random_bytes(8)),
            'connection' => $connection,
            'sql_template_hash' => hash('sha256', self::normalizeSqlTemplate($sql)),
            'duration_ms' => round($durationMs, 3),
            'ordinal' => $this->ordinal,
            'in_transaction' => $this->transactionDepth > 0,
        ];
    }

    private function operationFor(Request $request): ?string
    {
        $prefix = trim($this->routesConfig->prefix(), '/');
        $path = trim($request->path(), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        return VaulterHttpOperationMap::resolve(method: $request->method(), pathAfterPrefix: $path);
    }

    private static function normalizeSqlTemplate(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $normalized = preg_replace("/'(?:''|[^'])*'/", '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized) ?? $normalized;

        return strtolower($normalized);
    }
}
