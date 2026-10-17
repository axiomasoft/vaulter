<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use LogicException;
use stdClass;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Idempotency\OperationInProgressException;
use Vaulter\Schema\IdStrategyResolver;

/**
 * The rows of `v_idempotency_keys` (D25, ADR-0029). Every write runs inside the operation's root transaction:
 * the claim row is inserted uncommitted, so a concurrent request with the same scope digest waits on the unique
 * index (at most two seconds) instead of reading an "in progress" row; the row is completed by the same
 * transaction that commits the mutation, and a rollback removes it. Status `claiming` never commits.
 *
 * A wait that times out leaves no transaction to continue (Postgres aborts it): the operation is refused with
 * `operation_in_progress` and rolled back as a whole.
 */
#[Internal]
final class IdempotencyStore
{
    public const string ENTITY = 'idempotency_keys';

    public const string CLAIMING = 'claiming';

    public const string COMPLETED = 'completed';

    /** The bounded wait on a concurrent claim (D25). */
    public const int WAIT_SECONDS = 2;

    public function __construct(
        private readonly VaulterDatabase $database,
        private readonly DatabaseConfig $config,
        private readonly IdStrategyResolver $ids,
    ) {}

    /**
     * Claims the digest for this attempt, or returns the committed receipt that already holds it. An expired
     * receipt counts as absent: it is deleted and the digest claimed anew.
     *
     * @param array<string, string|null> $tuple {@see IdempotencyDigest::tuple()}
     */
    public function claim(array $tuple, string $digest, string $requestHash, int $ttlSeconds): ?StoredReceipt
    {
        $now = Carbon::now();
        $row = [
            'scope_digest' => $digest,
            ...$tuple,
            'idempotency_key' => base64_encode((string) $tuple['idempotency_key']),
            'request_hash' => $requestHash,
            'status' => self::CLAIMING,
            'created_at' => $now,
            'expires_at' => $now->copy()->addSeconds($ttlSeconds),
        ];
        $id = $this->ids->resolve()->newId();
        if ($id !== null) {
            $row = ['id' => $id, ...$row];
        }

        // The owner of a conflicting claim committed or rolled back while we waited: two rounds settle it
        // (rolled back → we insert; committed → we read it; expired → we delete and insert once more).
        for ($round = 0; $round < 3; $round++) {
            if ($this->withBoundedWait(fn (): bool => $this->database->insertOnce($this->table(), $row, 'scope_digest'))) {
                return null;
            }
            $existing = $this->lockedReceipt($digest);
            if ($existing === null) {
                continue;
            }
            if ($existing->status !== self::COMPLETED) {
                throw new OperationInProgressException(1);
            }
            if (Carbon::parse($existing->expiresAt)->lessThanOrEqualTo($now)) {
                // Still expired: a retry that committed a fresh receipt for this digest meanwhile has a later expiry.
                $this->query()->where('scope_digest', $digest)->where('status', self::COMPLETED)->where('expires_at', '<=', $now)->delete();

                continue;
            }

            return $existing;
        }

        throw new OperationInProgressException(1);
    }

    /**
     * Completes this attempt's claim with its receipt, in the same transaction as the mutation.
     *
     * @param array<string, mixed>       $body
     * @param array<string, string>|null $headers
     */
    public function complete(
        string $digest,
        string $kind,
        int $version,
        array $body,
        ?int $httpCode,
        ?array $headers,
        ?ResourceRef $resource,
        ?ResourceRef $scope,
    ): void {
        $updated = $this->query()->where('scope_digest', $digest)->where('status', self::CLAIMING)->update([
            'status' => self::COMPLETED,
            'receipt_kind' => $kind,
            'codec_version' => $version,
            'receipt_body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'http_code' => $httpCode,
            'receipt_headers' => $headers === null ? null : json_encode($headers, JSON_THROW_ON_ERROR),
            'resource_kind' => $resource?->kind,
            'resource_id' => $resource?->id,
            'auth_scope_kind' => $scope?->kind,
            'auth_scope_id' => $scope?->id,
            'completed_at' => Carbon::now(),
        ]);
        if ($updated !== 1) {
            throw new LogicException('[Vaulter] The idempotency claim of this attempt is gone; the receipt was not written.');
        }
    }

    /**
     * Deletes up to `$limit` expired receipts; returns how many. A claim is never pruned (it is never committed).
     */
    public function prune(int $limit): int
    {
        $ids = $this->query()
            ->where('status', self::COMPLETED)
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('scope_digest')
            ->all();
        if ($ids === []) {
            return 0;
        }

        // Expired again at delete time: a receipt a retry committed after the read above is not this one.
        return $this->database->write(fn (): int => $this->query()->whereIn('scope_digest', $ids)->where('status', self::COMPLETED)->where('expires_at', '<=', Carbon::now())->delete());
    }

    public function table(): string
    {
        return $this->config->table(self::ENTITY);
    }

    private function query(): Builder
    {
        return $this->connection()->table($this->table());
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /** The committed receipt under a shared lock (read past any snapshot), or null. */
    private function lockedReceipt(string $digest): ?StoredReceipt
    {
        $query = $this->query()->where('scope_digest', $digest);
        if ($this->connection()->getDriverName() !== 'sqlite') {
            $query->sharedLock();
        }
        $row = $query->first();

        return $row instanceof stdClass ? StoredReceipt::fromRow($row) : null;
    }

    /**
     * Runs `$insert` with the lock wait bounded to {@see WAIT_SECONDS}; a timeout becomes `operation_in_progress`.
     * Postgres scopes the setting to the transaction (`set_config(…, true)`), MySQL restores the session value.
     * SQLite has a single writer: its busy timeout bounds the wait.
     *
     * @param Closure(): bool $insert
     */
    private function withBoundedWait(Closure $insert): bool
    {
        $connection = $this->connection();
        $driver = $connection->getDriverName();
        $restore = null;
        if ($driver === 'pgsql') {
            $previous = self::setting($connection->selectOne("select current_setting('lock_timeout') as v"));
            $connection->select("select set_config('lock_timeout', ?, true)", [self::WAIT_SECONDS.'s']);
            $restore = static fn () => $connection->select("select set_config('lock_timeout', ?, true)", [$previous]);
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $previous = (int) self::setting($connection->selectOne('select @@session.innodb_lock_wait_timeout as v'));
            $connection->statement('set session innodb_lock_wait_timeout = '.self::WAIT_SECONDS);
            $restore = static fn () => $connection->statement('set session innodb_lock_wait_timeout = '.$previous);
        } elseif ($driver === 'sqlite') {
            $previous = (int) self::setting($connection->selectOne('select timeout as v from pragma_busy_timeout'));
            $connection->statement('pragma busy_timeout = '.(self::WAIT_SECONDS * 1000));
            $restore = static fn () => $connection->statement('pragma busy_timeout = '.$previous);
        }

        try {
            return $insert();
        } catch (QueryException $exception) {
            if (self::isLockTimeout($exception)) {
                // Postgres aborted the transaction: nothing more runs in it, the exception rolls it back (and the
                // transaction-local setting with it); MySQL and SQLite keep the connection, so their setting is restored.
                // No previous: a lock-wait timeout in the chain would read as a concurrency failure and repeat the attempt.
                if ($driver === 'pgsql') {
                    $restore = null;
                }
                throw new OperationInProgressException(1);
            }

            throw $exception;
        } finally {
            if ($restore !== null) {
                try {
                    $restore();
                } catch (Throwable) {
                    // The transaction is failing anyway; its rollback restores a transaction-local setting.
                }
            }
        }
    }

    private static function isLockTimeout(QueryException $exception): bool
    {
        $info = $exception->errorInfo ?? [];
        $first = $info[0] ?? $exception->getCode();
        $second = $info[1] ?? 0;
        $state = is_scalar($first) ? (string) $first : '';
        $driverCode = is_numeric($second) ? (int) $second : 0;

        // 55P03 lock_not_available (Postgres lock_timeout), 1205 ER_LOCK_WAIT_TIMEOUT (MySQL/MariaDB), 5 SQLITE_BUSY.
        return $state === '55P03' || $driverCode === 1205 || ($driverCode === 5 && str_contains($exception->getMessage(), 'database is locked'));
    }

    private static function setting(mixed $row): string
    {
        $value = $row instanceof stdClass ? ($row->v ?? null) : null;

        return is_scalar($value) ? (string) $value : '';
    }
}
