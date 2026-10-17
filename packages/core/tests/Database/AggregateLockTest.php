<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('takes the aggregate lock of any entity inside a transaction, on its own table outside PostgreSQL', function (string $entity): void {
    $database = app(VaulterDatabase::class);
    $level = $database->connection()->transactionLevel();

    $database->transaction(function () use ($database, $entity): void {
        $database->lockAggregate($entity, '1');
        $database->lockAggregateShared($entity, '1');
        $database->lockAggregateShared($entity, 1);
    });

    expect($database->connection()->transactionLevel())->toBe($level);
})->with(['outbox', 'outbox_consumers', 'idempotency_keys']);

it('keeps the aggregate lock free of the entities of any module: the entity key is its argument', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Database/VaulterDatabase.php');

    expect($source)->not->toContain("'drives'")
        ->and($source)->not->toContain('lockTree');
});
