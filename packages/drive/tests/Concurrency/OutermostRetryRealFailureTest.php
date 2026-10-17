<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Testing\Concurrency\ForkRace;

// F01 on a real server: VaulterDatabase::retryingOutermost() reacts to the SQLSTATE PostgreSQL
// actually raises (40P01 deadlock, 40001 serialization failure), not to a message we invented.
// Committed rows (DatabaseMigrations, see tests/Pest.php): the peer session must see the fixtures.

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('database.connections.retry_peer', config('database.connections.testing'));
});

afterEach(function (): void {
    DB::purge('retry_peer');
});

/**
 * One REPEATABLE READ unit that reads the drive, lets $interfere commit a conflicting write from
 * another session (first $conflicts attempts only), then updates it: PostgreSQL answers 40001.
 *
 * @return array{result: int|null, attempts: int, caught: Throwable|null}
 */
function serializationRetryUnit(Drive $drive, Connection $peer, int $conflicts): array
{
    $database = app(VaulterDatabase::class);
    $table = $drive->getTable();
    $attempts = 0;
    $caught = null;
    $result = null;

    try {
        $result = $database->retryingOutermost(function () use ($database, $table, $drive, $peer, $conflicts, &$attempts): int {
            $attempts++;

            return $database->transaction(function (Connection $connection) use ($table, $drive, $peer, $conflicts, &$attempts): int {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $connection->table($table)->where('id', $drive->getKey())->value('name');
                if ($attempts <= $conflicts) {
                    $peer->table($table)->where('id', $drive->getKey())->update(['name' => 'peer '.$attempts]);
                }
                $connection->table($table)->where('id', $drive->getKey())->update(['name' => 'unit '.$attempts]);

                return $attempts;
            }, attempts: 1);
        });
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    return ['result' => $result, 'attempts' => $attempts, 'caught' => $caught];
}

it('SEC-F01-40001 retries a real serialization failure once the unit has rolled back', function (): void {
    $drive = UploadTestHelpers::storage();

    $run = serializationRetryUnit($drive, DB::connection('retry_peer'), conflicts: 1);

    expect($run['caught'])->toBeNull()
        ->and($run['result'])->toBe(2)->and($run['attempts'])->toBe(2)
        ->and($drive->newQuery()->whereKey($drive->getKey())->value('name'))->toBe('unit 2');
});

it('SEC-F01-40001-exhausted gives up after three attempts with a retryable problem and no partial write', function (): void {
    $drive = UploadTestHelpers::storage();

    $run = serializationRetryUnit($drive, DB::connection('retry_peer'), conflicts: 99);

    expect($run['caught'])->toBeInstanceOf(ConcurrentUpdateException::class)->toBeInstanceOf(ProblemException::class)
        ->and($run['attempts'])->toBe(3)
        ->and($run['caught']->problemCode())->toBe('concurrent_update')->and($run['caught']->httpStatus())->toBe(409)
        ->and($run['caught']->getPrevious()?->getMessage())->toContain('SQLSTATE[40001]')
        ->and($drive->newQuery()->whereKey($drive->getKey())->value('name'))->toBe('peer 3');
});

it('SEC-F01-40P01 retries the real deadlock victim while the other transaction commits', function (): void {
    $first = UploadTestHelpers::storage();
    $second = UploadTestHelpers::storage();
    $ids = [$first->getKey(), $second->getKey()];
    $table = $first->getTable();

    // Opposite lock order; the first attempt waits long enough for both to hold their first row.
    $task = static fn (array $order): Closure => static function () use ($order, $table): int {
        $database = app(VaulterDatabase::class);
        $attempts = 0;
        $database->retryingOutermost(function () use ($database, $order, $table, &$attempts): void {
            $attempts++;
            $database->transaction(function (Connection $connection) use ($order, $table, $attempts): void {
                $connection->table($table)->where('id', $order[0])->lockForUpdate()->first();
                if ($attempts === 1) {
                    usleep(800_000);
                }
                $connection->table($table)->where('id', $order[1])->lockForUpdate()->first();
            }, attempts: 1);
        });

        return $attempts;
    };

    $outcomes = ForkRace::run([$task($ids), $task(array_reverse($ids))], timeoutMs: 20_000);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        // PostgreSQL aborts exactly one participant: it needs a second attempt, the survivor does not.
        ->and([$outcomes[0]->value, $outcomes[1]->value])->toContain(1)->toContain(2);
});
