<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Vaulter\Database\DatabaseErrorMapper;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

it('clears PDO and both callback queues after a non-concurrency COMMIT failure', function (): void {
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $actions = [];
    $fail = true;
    $connection->getEventDispatcher()->listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($connection, &$fail): void {
        if ($event->connection === $connection && $fail) {
            $fail = false;
            $error = new PDOException('COMMIT rejected');
            $error->errorInfo = ['23001'];
            throw $error;
        }
    });

    $record = static function (string $name) use (&$actions): Closure {
        return static function () use ($name, &$actions): void {
            $actions[] = $name;
        };
    };
    expect(fn () => $database->transaction(function (Connection $connection) use ($database, $record): void {
        $database->afterCommit($record('failed Vaulter callback'));
        $connection->afterCommit($record('failed framework callback'));
    }))->toThrow(PDOException::class, 'COMMIT rejected');

    expect($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->transactionLevel())->toBe(0)
        ->and($actions)->toBe([]);
    $database->transaction(fn () => $database->afterCommit($record('successful callback')));
    expect($actions)->toBe(['successful callback']);
});

it('classifies both foreign-key SQLSTATE codes through a Laravel query error', function (string $state, bool $matches): void {
    $driver = new PDOException('database error');
    $driver->errorInfo = [$state];
    $exception = new QueryException('pgsql', 'DELETE FROM parent', [], $driver);

    expect(DatabaseErrorMapper::isForeignKeyViolation($exception))->toBe($matches);
})->with([['23001', true], ['23503', true], ['23505', false]]);

it('checks explicitly deferred PostgreSQL constraints before entering COMMIT', function (): void {
    $database = app(VaulterDatabase::class);
    if ($database->driver() !== 'pgsql') {
        expect($database->transaction(static fn (): string => 'portable'))->toBe('portable');

        return;
    }

    try {
        $database->transaction(static function (Connection $connection): void {
            $connection->statement('CREATE TEMPORARY TABLE rail_parent (id bigint primary key)');
            $connection->statement('CREATE TEMPORARY TABLE rail_child (parent_id bigint references rail_parent(id) DEFERRABLE INITIALLY IMMEDIATE)');
            $connection->statement('SET CONSTRAINTS ALL DEFERRED');
            $connection->statement('INSERT INTO rail_child VALUES (1)');
        });
        $this->fail('Expected the missing parent to fail the pre-commit check.');
    } catch (QueryException $error) {
        expect($error->getSql())->toBe('SET CONSTRAINTS ALL IMMEDIATE')
            ->and(DatabaseErrorMapper::isForeignKeyViolation($error))->toBeTrue()
            ->and($database->connection()->getPdo()->inTransaction())->toBeFalse();
    }
});

it('does not replay a committed transaction when its callback reports a conflict', function (): void {
    $database = app(VaulterDatabase::class);
    $database->connection()->setTransactionManager(new DatabaseTransactionsManager);
    $attempts = 0;
    $run = static function () use ($database, &$attempts): void {
        $database->transaction(static function () use ($database, &$attempts): void {
            $attempts++;
            $database->afterCommit(static fn () => throw new ConcurrentUpdateException);
        });
    };

    expect($run)->toThrow(ConcurrentUpdateException::class)
        ->and($attempts)->toBe(1);
});

it('retries a nested concurrent update at the outer boundary within its budget', function (): void {
    $database = app(VaulterDatabase::class);
    $outerAttempts = 0;
    $nestedAttempts = 0;
    $run = static function () use ($database, &$outerAttempts, &$nestedAttempts): void {
        $database->transaction(static function () use ($database, &$outerAttempts, &$nestedAttempts): void {
            $outerAttempts++;
            $database->transaction(static function () use (&$nestedAttempts): void {
                $nestedAttempts++;
                throw new ConcurrentUpdateException;
            });
        }, attempts: 2);
    };

    expect($run)->toThrow(ConcurrentUpdateException::class)
        ->and($outerAttempts)->toBe(2)
        ->and($nestedAttempts)->toBe(2);
});
