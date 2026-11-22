<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;
use Throwable;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyExpiredException;
use Vaulter\Exceptions\Idempotency\OperationInProgressException;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Operations\ScopeRef;

/**
 * The rows of `v_idempotency_keys` (docs/adr/0029-idempotency.md). Every write runs inside the operation's root transaction:
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

    private const string EXPIRED = 'expired';

    private const int TOMBSTONE_DAYS = 7;

    /** The bounded wait on a concurrent claim. */
    public const int WAIT_SECONDS = 2;

    public function __construct(
        private readonly VaulterDatabase $database,
        private readonly DatabaseOptions $config,
        private readonly IdStrategyResolver $ids,
    ) {}

    /**
     * Claims the digest for this attempt, or returns the committed receipt that already holds it.
     * Recently expired keys are rejected even before the scheduled prune compacts their receipts.
     *
     * @param array<string, string|null> $tuple {@see IdempotencyDigest::tuple()}
     */
    public function claim(array $tuple, string $digest, string $requestHash, int $ttlSeconds): ?StoredReceipt
    {
        $now = $this->now();
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
            if (!in_array($existing->status, [self::COMPLETED, self::EXPIRED], true)) {
                throw new OperationInProgressException(1);
            }
            $now = $this->now();
            $expiry = Carbon::parse($existing->expiresAt);
            if ($expiry->lessThanOrEqualTo($now->copy()->subDays(self::TOMBSTONE_DAYS))) {
                $this->query()->where('scope_digest', $digest)->delete();
                $row['created_at'] = $now;
                $row['expires_at'] = $now->copy()->addSeconds($ttlSeconds);

                continue;
            }
            if ($existing->status === self::EXPIRED || $expiry->lessThanOrEqualTo($now)) {
                throw new IdempotencyKeyExpiredException('[Vaulter] The idempotency key is outside its replay window.');
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
     * The scope of the one live receipt of `$operation` that `$actor` committed under `$key` for exactly this request
     * (`$requestHash`), or null. For a transport whose retry addresses a resource that is gone by now (a purged node
     * no longer names its drive): the scope says where to run the retry, so the runner answers it from that receipt.
     * Nothing is trusted from the row — the claim compares the whole tuple again and the owner authorizes the replay.
     * Not indexed by these columns: called only for a keyed request whose resource is not found.
     */
    public function scopeOf(string $operation, string $key, ActorRef $actor, string $requestHash): ?ScopeRef
    {
        $fields = IdempotencyDigest::actor($actor);
        $rows = $this->query()
            ->where('operation', $operation)
            ->where('idempotency_key', base64_encode($key))
            ->where('actor_kind', $fields['actor_kind'])
            ->when($fields['actor_id'] === null, static fn (Builder $query): Builder => $query->whereNull('actor_id'), static fn (Builder $query): Builder => $query->where('actor_id', $fields['actor_id']))
            ->where('request_hash', $requestHash)
            ->whereIn('status', [self::COMPLETED, self::EXPIRED])
            ->where('expires_at', '>', $this->now()->subDays(self::TOMBSTONE_DAYS))
            ->limit(2)
            ->get(['scope_kind', 'scope_id']);
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $rows->first();

        return $row instanceof stdClass && is_string($row->scope_kind) && is_scalar($row->scope_id) ? new ScopeRef($row->scope_kind, (string) $row->scope_id) : null;
    }

    /**
     * Compacts expired receipts and removes elapsed tombstones, processing at most `$limit` rows.
     */
    public function prune(int $limit): int
    {
        return $this->database->write(function () use ($limit): int {
            $now = $this->now();
            $cutoff = $now->copy()->subDays(self::TOMBSTONE_DAYS);
            $elapsed = $this->query()
                ->whereIn('status', [self::COMPLETED, self::EXPIRED])
                ->where('expires_at', '<=', $cutoff);
            $delete = (clone $elapsed)->orderBy('expires_at')->limit($limit)->pluck('scope_digest');
            $deleted = $elapsed->whereIn('scope_digest', $delete)->delete();
            if ($deleted >= $limit) {
                return $deleted;
            }

            $expired = $this->query()->where('status', self::COMPLETED)
                ->where('expires_at', '>', $cutoff)->where('expires_at', '<=', $now);
            $compact = (clone $expired)->orderBy('expires_at')->limit($limit - $deleted)->pluck('scope_digest');

            return $deleted + $expired->whereIn('scope_digest', $compact)->update([
                'status' => self::EXPIRED,
                'receipt_kind' => null,
                'codec_version' => null,
                'receipt_body' => null,
                'http_code' => null,
                'receipt_headers' => null,
                'resource_kind' => null,
                'resource_id' => null,
                'auth_scope_kind' => null,
                'auth_scope_id' => null,
            ]);
        });
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

    private function now(): Carbon
    {
        $connection = $this->connection();

        return $connection->getDriverName() === 'pgsql'
            ? Carbon::parse(self::setting($connection->selectOne('select clock_timestamp()::timestamp as v', useReadPdo: false)))
            : Carbon::now();
    }

    /** The committed receipt under a write lock (read past any snapshot), or null. */
    private function lockedReceipt(string $digest): ?StoredReceipt
    {
        $query = $this->query()->where('scope_digest', $digest);
        if ($this->connection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
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
