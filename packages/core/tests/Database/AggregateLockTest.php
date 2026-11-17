<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('takes the aggregate lock of any entity inside a transaction, on its own table outside PostgreSQL', function (string $entity): void {
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();
    $level = $connection->transactionLevel();
    $grammar = $connection->getQueryGrammar();
    $table = app(DatabaseOptions::class)->table($entity);
    $quoted = $grammar->wrapTable($table);
    // Aggregate roots use an id PK; the outbox and its consumer ledger are not aggregate roots.
    // TEMPORARY avoids committing the surrounding test transaction on MySQL.
    $connection->statement('CREATE TEMPORARY TABLE '.$quoted.' (id BIGINT PRIMARY KEY)');
    $queries = [];
    $recording = false;
    $connection->listen(static function (QueryExecuted $query) use ($connection, &$queries, &$recording): void {
        if ($recording && $query->connection === $connection && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $queries[] = $query;
        }
    });

    try {
        $connection->table($table)->insert(['id' => 1]);
        $recording = true;
        $database->transaction(function () use ($database, $entity): void {
            $database->lockAggregate($entity, '1');
            $database->lockAggregateShared($entity, '1');
            $database->lockAggregateShared($entity, 1);
        });
        $recording = false;

        expect($connection->transactionLevel())->toBe($level)
            ->and($queries)->toHaveCount(3);
        if ($connection->getDriverName() === 'pgsql') {
            expect($queries[0]->sql)->toContain('pg_advisory_xact_lock(')
                ->and($queries[1]->sql)->toContain('pg_advisory_xact_lock_shared(')
                ->and($queries[2]->sql)->toContain('pg_advisory_xact_lock_shared(');
            foreach ($queries as $query) {
                expect($query->bindings)->toBe(['vaulter:'.$entity.':1']);
            }
        } else {
            foreach ($queries as $index => $query) {
                expect($query->sql)->toContain('from '.$quoted)
                    ->and($query->sql)->toContain('where '.$grammar->wrap('id').' = ?')
                    ->and($query->bindings)->toBe($index === 2 ? [1] : ['1']);
                if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                    expect($query->sql)->toEndWith('for update');
                }
            }
        }
    } finally {
        $recording = false;
        $connection->statement((in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'DROP TEMPORARY TABLE ' : 'DROP TABLE ').$quoted);
    }
})->with(['aggregate_lock_probe', 'another_aggregate_probe', 'longer_aggregate_root_probe']);

it('keeps the aggregate lock free of the entities of any module: the entity key is its argument', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Database/VaulterDatabase.php');

    expect($source)->not->toContain("'drives'")
        ->and($source)->not->toContain('lockTree');
});
