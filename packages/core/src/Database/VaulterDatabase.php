<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use stdClass;
use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;

#[Api]
final class VaulterDatabase
{
    /** The outermost transaction attempt now running; `aborted` once its commit failed and it was retried. */
    private ?stdClass $attempt = null;

    #[Internal]
    public function __construct(
        private readonly DatabaseConfig $databaseConfig,
        private readonly DatabaseManager $db,
        private readonly Container $container,
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
        $attempt = null;

        try {
            return $this->connection()->transaction(function (Connection $connection) use ($callback, &$attempt): mixed {
                // Laravel repeats the callback when the commit itself fails on a concurrency
                // error, rolling back without telling its after-commit queue: the callbacks
                // of the abandoned attempt would still run after the next commit.
                if ($attempt !== null) {
                    $attempt->aborted = true;
                }
                if ($connection->transactionLevel() === 1) {
                    $attempt = $this->attempt = (object) ['aborted' => false];
                }

                return $this->scope()->run(static fn (): mixed => $callback($connection));
            }, $attempts);
        } catch (Throwable $exception) {
            if ($attempt !== null) {
                $attempt->aborted = true;
            }

            throw $exception;
        } finally {
            if ($this->connection()->transactionLevel() === 0) {
                $this->attempt = null;
            }
        }
    }

    /**
     * Run $callback as a Vaulter write outside a transaction (a job, a command, a
     * listener staging rows after commit): model writes inside it pass the write guard.
     *
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn $callback
     * @return TReturn
     */
    #[Internal]
    public function write(Closure $callback): mixed
    {
        return $this->scope()->run($callback);
    }

    /** Resolved per call: the scope is a scoped service and this class is a singleton. */
    private function scope(): WriteScope
    {
        return $this->container->make(WriteScope::class);
    }

    /**
     * Run $callback as the OUTERMOST unit of work and repeat it after a deadlock or
     * serialization failure. Laravel cannot retry a nested transaction (it rethrows a
     * DeadlockException and lets only the outermost level roll back), so a retry is
     * only honest here, at transaction level 0, once the callback has fully rolled back.
     * Anything else, or a non-concurrency failure, is rethrown untouched; an exhausted
     * budget becomes a retryable {@see ConcurrentUpdateException}.
     *
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn $callback
     * @param  int<1, max>        $attempts
     * @return TReturn
     */
    #[Internal]
    public function retryingOutermost(Closure $callback, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $exception) {
                if ($this->connection()->transactionLevel() !== 0 || !$this->isConcurrencyFailure($exception)) {
                    throw $exception;
                }
                // A commit that failed on a concurrency error with no attempt left to Laravel leaves the
                // server transaction open under a level-0 connection: close it before the next attempt.
                $pdo = $this->connection()->getPdo();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($attempt >= $attempts) {
                    throw new ConcurrentUpdateException($exception);
                }
            }
        }
    }

    /**
     * Walks the previous chain: a nested failure arrives wrapped in a
     *           DeadlockException whose own code and message may no longer match.
     */
    #[Internal]
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

    /** Runs $callback once the outermost transaction has committed, never for a rolled-back attempt. */
    public function afterCommit(Closure $callback): void
    {
        $attempt = $this->connection()->transactionLevel() > 0 ? $this->attempt : null;

        $this->connection()->afterCommit(static function () use ($callback, $attempt): void {
            if ($attempt === null || !$attempt->aborted) {
                $callback();
            }
        });
    }

    /**
     * Insert $row unless a row with the same $uniqueColumn value exists; true when this
     * call inserted it. The conflict is resolved by the database, so two writers of
     * the same occurrence cannot both insert, and any other failure (truncation, a
     * foreign key, a lost connection) still throws. INSERT IGNORE is not used on
     * MySQL because it downgrades exactly those errors to warnings.
     *
     *
     * @param array<string, mixed> $row column => database-ready value
     */
    #[Internal]
    public function insertOnce(string $table, array $row, string $uniqueColumn): bool
    {
        if (!array_key_exists($uniqueColumn, $row)) {
            throw new LogicException('[Vaulter] insertOnce() needs a value for the unique column '.$uniqueColumn.'.');
        }

        $connection = $this->connection();
        $query = $connection->table($table);
        $grammar = $query->getGrammar();
        $column = $grammar->wrap($uniqueColumn);
        $sql = $grammar->compileInsert($query, [$row]).match ($connection->getDriverName()) {
            'pgsql', 'sqlite' => ' on conflict ('.$column.') do nothing',
            // With an unchanged row MySQL reports 0 affected rows, so the count still
            // tells an insert (1) from a duplicate (0).
            'mysql', 'mariadb' => ' on duplicate key update '.$column.' = '.$column,
            default => throw new LogicException('[Vaulter] insertOnce() does not support the '.$connection->getDriverName().' driver.'),
        };

        return $connection->affectingStatement($sql, $query->cleanBindings(array_values($row))) === 1;
    }

    private function requireTransaction(string $method): void
    {
        if ($this->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] '.$method.'() must run inside VaulterDatabase::transaction().');
        }
    }
}
