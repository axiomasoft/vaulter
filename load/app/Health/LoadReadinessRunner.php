<?php

declare(strict_types=1);

namespace Vaulter\Load\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

final class LoadReadinessRunner
{
    public function __construct(
        private readonly QueueReadiness $queueReadiness,
    ) {}

    /**
     * @return array{db: bool, s3: bool, queue: bool}
     */
    public function checks(): array
    {
        return [
            'db' => $this->checkDatabase(),
            's3' => $this->checkS3(),
            'queue' => $this->queueReadiness->probe(),
        ];
    }

    public function checkDatabase(): bool
    {
        try {
            $name = (string) config('database.default');
            $timeoutSeconds = max(1, (int) round((float) config('load.health.db_timeout_seconds', 2)));
            $config = (array) config("database.connections.{$name}", []);

            if (($config['driver'] ?? null) === 'pgsql') {
                return $this->probePostgres($config, $timeoutSeconds);
            }

            DB::connection($name)->selectOne('select 1 as ok');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function probePostgres(array $config, int $timeoutSeconds): bool
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 5432);
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $sslmode = (string) ($config['sslmode'] ?? 'prefer');

        $dsn = "pgsql:host={$host};port={$port};dbname='{$database}';connect_timeout={$timeoutSeconds};sslmode={$sslmode}";

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeoutSeconds,
        ]);

        $timeoutMs = $timeoutSeconds * 1000;
        $pdo->exec("SET statement_timeout = {$timeoutMs}");
        $pdo->query('select 1 as ok');

        return true;
    }

    public function checkS3(): bool
    {
        try {
            $disk = (string) config('vaulter.storage.default_disk', 's3');
            $sentinel = (string) config('load.health.s3_sentinel_key', '.vaulter-health-sentinel');

            return Storage::disk($disk)->exists($sentinel);
        } catch (Throwable) {
            return false;
        }
    }
}
