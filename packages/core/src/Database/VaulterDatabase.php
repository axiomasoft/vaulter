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

    public function lockTree(int|string $storageId): void
    {
        $this->requireTransaction('lockTree');

        if ($this->driver() === 'pgsql') {
            $this->advisoryLock('vaulter:tree:'.$storageId);

            return;
        }

        $this->connection()->table(Config::table('storages'))
            ->where('id', $storageId)->lockForUpdate()->first(['id']);
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
