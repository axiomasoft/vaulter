<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

it('converges on a peer insert after acquiring a repeatable read snapshot', function (bool $nested, int $attempts): void {
    Storage::fake('local');
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();
    config()->set('database.connections.blob_peer', $connection->getConfig());
    $peer = DB::connection('blob_peer');
    // These sessions represent two application requests with independent callback queues.
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $peer->setTransactionManager(new DatabaseTransactionsManager);
    $store = app(BlobStore::class);
    $content = app(ContentStore::class);
    $scope = BlobScope::tenant('1');
    $bytes = 'shared contents';
    $winner = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor($bytes), $scope));
    $table = $winner->getTable();
    $connection->table($table)->where('id', $winner->id)->delete();
    $key = 'cas:'.$scope->key().':'.$winner->sha256;
    $staged = $content->stage(CasTestHelpers::streamFor($bytes));
    $lockAttempts = 0;
    $promoted = [];
    $committed = [];

    try {
        // The peer has promoted the bytes and inserted their row, but still owns the lock.
        $peer->beginTransaction();
        $peer->selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        $peer->table($table)->insert($winner->getRawOriginal());
        $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->beforeExecuting(static function (string $sql, array $bindings, Connection $db) use ($key, $peer, &$lockAttempts): void {
            if (!str_contains($sql, 'pg_advisory_xact_lock(') || $bindings !== [$key]) {
                return;
            }
            if (++$lockAttempts === 1) {
                // Establish the same visibility as a lock waiter before releasing the peer.
                $db->selectOne('select pg_current_snapshot()');
                $peer->commit();
            }
        });
        try {
            $result = $nested
                ? $database->transaction(function () use ($store, $database, $scope, $staged, &$promoted, &$committed): BlobSnapshot {
                    $other = $store->commit(CasTestHelpers::streamFor('owner bytes'), $scope);
                    $promoted[] = $other->id;
                    $database->afterCommit(static function () use ($other, &$committed): void {
                        $committed[] = $other->id;
                    });

                    return $store->commitStaged($staged, $scope);
                }, attempts: $attempts)
                : $store->commitStaged($staged, $scope);
        } catch (ConcurrentUpdateException $conflict) {
            expect($attempts)->toBe(1)
                ->and($conflict->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class)
                ->and($conflict->getPrevious()->getCode())->toBe('23505');
            expect($lockAttempts)->toBe(1)
                ->and($committed)->toBe([])
                ->and(Blob::query()->whereKey($promoted)->exists())->toBeFalse()
                ->and(Blob::query()->count())->toBe(1);

            return;
        }

        expect($attempts)->toBeGreaterThan(1)
            ->and($lockAttempts)->toBe(2)
            ->and($result->id)->toBe((string) $winner->id)
            ->and(Blob::query()->where('sha256', $winner->sha256)->count())->toBe(1)
            ->and(stream_get_contents($store->read($result)))->toBe($bytes);
        if ($nested) {
            expect($promoted)->toHaveCount(2)
                ->and(Blob::query()->whereKey($promoted[0])->exists())->toBeFalse()
                ->and($committed)->toBe([$promoted[1]])
                ->and(stream_get_contents($store->read($promoted[1])))->toBe('owner bytes');
        }
    } finally {
        $staged->discard();
        $connection->statement('RESET default_transaction_isolation');
        $peer->rollBack();
        DB::purge('blob_peer');
    }
})->with(['standalone' => [false, 3], 'inside the owner transaction' => [true, 3], 'owner allows one attempt' => [true, 1]]);

it('preserves a unique violation on another blob attribute', function (): void {
    Storage::fake('local');
    $database = app(VaulterDatabase::class);
    $store = app(BlobStore::class);
    $scope = BlobScope::tenant('1');
    $first = $store->commit(CasTestHelpers::streamFor('first object'), $scope);
    $table = (new Blob)->getTable();
    $database->connection()->statement("create unique index unique_blob_disk on {$table} (disk)");

    expect(fn () => $store->commit(CasTestHelpers::streamFor('other object'), $scope))->toThrow(UniqueConstraintViolationException::class)
        ->and(Blob::query()->count())->toBe(1)
        ->and(stream_get_contents($store->read($first)))->toBe('first object');
});
