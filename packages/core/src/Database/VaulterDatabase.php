<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Vaulter\Support\Config;

final class VaulterDatabase
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function connection(): Connection
    {
        return $this->db->connection(Config::connection());
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

        $this->connection()->table(Config::table('drives'))
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
