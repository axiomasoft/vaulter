<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Throwable;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;

final class VaulterDatabase
{
    public function __construct(
        private readonly DatabaseConfig $databaseConfig,
        private readonly DatabaseManager $db,
    ) {}

    public function connection(): Connection
    {
        return $this->db->connection($this->databaseConfig->connection());
    }

    public function driver(): string
    {
        return $this->connection()->getDriverName();
    }

    /**
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn $callback
     * @param  int<1, max>                  $attempts
     * @return TReturn
     */
    public function transaction(Closure $callback, int $attempts = 3): mixed
    {
        return $this->connection()->transaction($callback, $attempts);
    }

    /**
     * Run $callback as the OUTERMOST unit of work and repeat it after a deadlock or
     * serialization failure. Laravel cannot retry a nested transaction (it rethrows a
     * DeadlockException and lets only the outermost level roll back), so a retry is
     * only honest here, at transaction level 0, once the callback has fully rolled back.
     * Anything else, or a non-concurrency failure, is rethrown untouched; an exhausted
     * budget becomes a retryable {@see ConcurrentUpdateException}.
     *
     * @internal
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn $callback
     * @param  int<1, max>        $attempts
     * @return TReturn
     */
    public function retryingOutermost(Closure $callback, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $exception) {
                if ($this->connection()->transactionLevel() !== 0 || !$this->isConcurrencyFailure($exception)) {
                    throw $exception;
                }
                if ($attempt >= $attempts) {
                    throw new ConcurrentUpdateException($exception);
                }
            }
        }
    }

    /**
     * @internal Walks the previous chain: a nested failure arrives wrapped in a
     *           DeadlockException whose own code and message may no longer match.
     */
    public function isConcurrencyFailure(Throwable $exception): bool
    {
        $detect = Closure::bind(
            fn (Throwable $error): bool => $this->causedByConcurrencyError($error),
            $this->connection(),
            Connection::class,
        );
        for ($cursor = $exception; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($detect($cursor)
                || in_array((string) $cursor->getCode(), ['40001', '40P01'], true)
                || preg_match('/SQLSTATE\[(?:40001|40P01)\]/', $cursor->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The storage's tree lock, exclusive: for operations that change the shape of the
     * tree or touch many node rows (move, restore, trash, purge). Taken before any
     * node row lock.
     */
    public function lockTree(int|string $storageId): void
    {
        $this->requireTransaction('lockTree');

        if ($this->driver() === 'pgsql') {
            $this->advisoryLock('vaulter:tree:'.$storageId);

            return;
        }

        $this->connection()->table($this->databaseConfig->table('drives'))
            ->where('id', $storageId)->lockForUpdate()->first(['id']);
    }

    /**
     * The storage's tree lock, shared: for writes under a single locked parent
     * (create, upload, copy, rename). On PostgreSQL they run concurrently with each
     * other and wait only for an exclusive holder. Elsewhere the lock is the storage
     * row, which these writes update (quota) or reference (foreign-key check) anyway,
     * so it is taken exclusively.
     */
    public function lockTreeShared(int|string $storageId): void
    {
        $this->requireTransaction('lockTreeShared');

        if ($this->driver() === 'pgsql') {
            $this->connection()->statement('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['vaulter:tree:'.$storageId]);

            return;
        }

        $this->lockTree($storageId);
    }

    public function advisoryLock(string $key): void
    {
        $this->requireTransaction('advisoryLock');

        if ($this->driver() === 'pgsql') {
            $this->connection()->statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    public function afterCommit(Closure $callback): void
    {
        $this->connection()->afterCommit($callback);
    }

    private function requireTransaction(string $method): void
    {
        if ($this->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] '.$method.'() must run inside VaulterDatabase::transaction().');
        }
    }
}
