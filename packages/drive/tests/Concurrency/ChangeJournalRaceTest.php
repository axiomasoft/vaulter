<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Changes\ReaderSyncIdentity;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Changes\NodeChange;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Exceptions\Changes\CursorExpiredException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * The change journal under real concurrency: seqs are allocated under
 * the drive's head lock until commit, so a reader paging by cursor while writers commit
 * never steps over an entry that commits later; a prune running beside a reader either
 * leaves its entries readable or expires its cursor, never a silent gap.
 *
 * The fork scenarios need PostgreSQL with pcntl + posix; the two-connection scenarios
 * run on PostgreSQL and MySQL (REPEATABLE READ by default: a read inside a host
 * transaction uses one snapshot for the journal and the nodes). SQLite has one writer and
 * one in-memory database per connection, so everything here is skipped there.
 */
function changeRaceSideConnection(): string
{
    $default = DB::getDefaultConnection();
    config()->set('database.connections.vaulter_change_side', config('database.connections.'.$default));
    DB::purge('vaulter_change_side');

    return 'vaulter_change_side';
}

function changeRaceSkipOnSqlite(object $test): void
{
    if (app(VaulterDatabase::class)->driver() === 'sqlite') {
        $test->markTestSkipped('Needs a second connection to a shared PostgreSQL or MySQL database.');
    }
}

/** The sync key a user reader gets for $nodeId in $drive: a remove names the node only by it. */
function changeRaceKey(DriveHandle $drive, TestUser $user, int|string $nodeId): string
{
    return (new ReaderSyncIdentity((string) config('app.key'), 1))
        ->syncKey($drive->model()->getRawOriginal('tenant_id'), $drive->model()->getKey(), new ActorRef($user->getMorphClass(), (string) $user->getKey()), $nodeId);
}

/** Raw journal rows of nodes that do not exist: a typed reader reads each back as a remove keyed by its node. */
function changeRaceSeedOldEntries(DriveHandle $drive, int $count): void
{
    $db = app(VaulterDatabase::class)->connection();
    $table = app(DatabaseConfig::class)->table('drive_changes');
    $rows = [];
    foreach (range(1, $count) as $seq) {
        $rows[] = ['drive_id' => $drive->model()->getKey(), 'seq' => $seq, 'change_id' => sprintf('race%022d', $seq), 'event_type' => 'node.moved',
            'node_id' => 900000 + $seq, 'operation' => 'upsert', 'subtree' => false, 'occurred_at' => now()->subDays(40)];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        $db->table($table)->insert($chunk);
    }
    $db->table(app(DatabaseConfig::class)->table('drive_change_heads'))->where('drive_id', $drive->model()->getKey())->update(['last_seq' => $count]);
}

it('allocates seqs without gaps and a concurrent reader sees every committed rename', function (): void {
    ForkRace::skipUnlessSupported($this);
    $drive = DriveTestHelpers::drive();
    $writers = 8;
    $renames = 25;
    $folders = [];
    for ($writer = 0; $writer < $writers; $writer++) {
        $parent = $drive->folders()->create('w'.$writer);
        for ($index = 0; $index < $renames; $index++) {
            $folders[$writer][] = $drive->folders()->create('f'.$index, $parent)->id;
        }
    }
    $start = $drive->changes()->startCursor();
    $base = (int) app(VaulterDatabase::class)->connection()->table('v_drive_change_heads')->where('drive_id', $drive->model()->getKey())->value('last_seq');

    $tasks = [];
    foreach ($folders as $writer => $ids) {
        $tasks[] = static function () use ($drive, $ids, $writer): int {
            foreach ($ids as $index => $id) {
                $drive->nodes()->rename($id, 'r-'.$writer.'-'.$index);
            }

            return count($ids);
        };
    }
    // The reader pages from the start cursor until it has seen every rename or time is up.
    $tasks[] = static function () use ($drive, $start, $writers, $renames): array {
        $seen = [];
        $cursor = $start;
        $deadline = microtime(true) + 50;
        while (count($seen) < $writers * $renames && microtime(true) < $deadline) {
            $page = $drive->changes()->list($cursor, limit: 7);
            foreach ($page->changes as $change) {
                if ($change->node !== null && str_starts_with($change->node->name, 'r-')) {
                    $seen[$change->nodeId] = true;
                }
            }
            $cursor = $page->cursor;
            if (!$page->hasMore) {
                usleep(2_000);
            }
        }

        return array_keys($seen);
    };

    $outcomes = ForkRace::run($tasks, timeoutMs: 60_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe(array_fill(0, $writers + 1, true));
    $renamed = app(VaulterDatabase::class)->connection()->table('v_drive_changes')
        ->where('drive_id', $drive->model()->getKey())->where('event_type', 'node.renamed')->orderBy('seq')->pluck('seq')
        ->map(static fn (mixed $seq): int => (int) $seq)->all();
    expect($renamed)->toBe(range($base + 1, $base + $writers * $renames))
        ->and($outcomes[$writers]->value)->toHaveCount($writers * $renames);
});

it('expires a concurrent reader\'s cursor or lets it read everything, never a gap, while a prune runs', function (): void {
    ForkRace::skipUnlessSupported($this);
    $drive = DriveTestHelpers::drive();
    $user = AccessTestHelpers::user(1);
    $reader = $drive->actingAs($user);
    $total = 3000;
    $start = $reader->changes()->startCursor();
    changeRaceSeedOldEntries($drive, $total);
    $seqOf = [];
    foreach (range(1, $total) as $seq) {
        $seqOf[changeRaceKey($drive, $user, 900000 + $seq)] = $seq;
    }

    $outcomes = ForkRace::run([
        static fn (): int => Artisan::call('vaulter:changes:prune', ['--days' => '1']),
        static function () use ($reader, $start, $seqOf): array {
            $seen = [];
            $cursor = $start;
            try {
                do {
                    $page = $reader->changes()->list($cursor, limit: 50);
                    foreach ($page->changes as $change) {
                        $seen[] = $seqOf[$change->syncKey] ?? 0;
                    }
                    $cursor = $page->cursor;
                } while ($page->hasMore);
            } catch (CursorExpiredException) {
                return ['seen' => $seen, 'expired' => true];
            }

            return ['seen' => $seen, 'expired' => false];
        },
    ], timeoutMs: 60_000);

    expect($outcomes[0]->ok)->toBeTrue()->and($outcomes[1]->ok)->toBeTrue();
    $result = $outcomes[1]->value;
    // Whatever the reader saw is the unbroken run 1..k; it either finished or was told to resync.
    expect($result['seen'])->toBe($result['seen'] === [] ? [] : range(1, count($result['seen'])))
        ->and($result['expired'] || count($result['seen']) === $total)->toBeTrue();
});

it('never skips an entry committed after a transaction snapshot, nor projects it with older nodes', function (): void {
    changeRaceSkipOnSqlite($this);
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $drive = DriveTestHelpers::drive();
    $shared = $drive->folders()->create('shared');
    $hidden = $drive->folders()->create('hidden');
    $doc = $drive->folders()->create('doc', $shared);
    AccessTestHelpers::grant($shared, RoleRegistry::READER);
    $reader = $drive->actingAs(AccessTestHelpers::user(1));
    $start = $reader->changes()->startCursor();
    $driveId = $drive->model()->getKey();
    $side = changeRaceSideConnection();
    $db = app(VaulterDatabase::class)->connection();

    // MySQL runs this in REPEATABLE READ, its default: the snapshot is taken by the first
    // read, before the other session moves the folder out of the reader's view and journals
    // it. PostgreSQL's READ COMMITTED reads the move at once; both must end the same way.
    $inside = app(VaulterDatabase::class)->transaction(static function () use ($db, $side, $reader, $start, $driveId, $doc, $hidden) {
        $db->table('v_nodes')->where('id', $doc->id)->value('parent_id');
        DB::connection($side)->transaction(static function () use ($side, $driveId, $doc, $hidden): void {
            DB::connection($side)->table('v_nodes')->where('id', $doc->id)->update(['parent_id' => $hidden->id]);
            $seq = (int) DB::connection($side)->table('v_drive_change_heads')->where('drive_id', $driveId)->lockForUpdate()->value('last_seq') + 1;
            DB::connection($side)->table('v_drive_change_heads')->where('drive_id', $driveId)->update(['last_seq' => $seq]);
            DB::connection($side)->table('v_drive_changes')->insert(['drive_id' => $driveId, 'seq' => $seq, 'change_id' => sprintf('race%022d', $seq),
                'event_type' => 'node.moved', 'node_id' => $doc->id, 'operation' => 'upsert', 'subtree' => true, 'occurred_at' => now()]);
        });

        return $reader->changes()->list($start);
    });
    $after = $reader->changes()->list($inside->cursor);

    $seen = array_map(static fn (NodeChange $change): array => [$change->operation, $change->syncKey, $change->nodeId],
        [...$inside->changes, ...$after->changes]);
    expect($seen)->toBe([[ChangeOperation::Remove, changeRaceKey($drive, AccessTestHelpers::user(1), $doc->id), null]]);
});

it('expires a cursor inside a transaction when a prune committed after its snapshot', function (): void {
    changeRaceSkipOnSqlite($this);
    $drive = DriveTestHelpers::drive();
    $start = $drive->changes()->startCursor();
    changeRaceSeedOldEntries($drive, 5);
    $side = changeRaceSideConnection();
    $db = app(VaulterDatabase::class)->connection();

    expect(fn () => app(VaulterDatabase::class)->transaction(static function () use ($db, $side, $drive, $start) {
        $db->table('v_drive_changes')->count();
        DB::connection($side)->transaction(static function () use ($side, $drive): void {
            DB::connection($side)->table('v_drive_changes')->where('drive_id', $drive->model()->getKey())->where('seq', '<=', 3)->delete();
            DB::connection($side)->table('v_drive_change_heads')->where('drive_id', $drive->model()->getKey())->update(['pruned_through' => 3]);
        });

        return $drive->changes()->list($start);
    }))->toThrow(CursorExpiredException::class);
});

it('expires a cursor outside a transaction when a prune lands between head and entries', function (): void {
    changeRaceSkipOnSqlite($this);
    $drive = DriveTestHelpers::drive();
    $start = $drive->changes()->startCursor();
    changeRaceSeedOldEntries($drive, 5);
    $side = changeRaceSideConnection();
    $pruned = false;
    app(VaulterDatabase::class)->connection()->listen(static function ($query) use (&$pruned, $side, $drive): void {
        if ($pruned || !str_contains($query->sql, 'v_drive_change_heads') || !str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }
        $pruned = true;
        DB::connection($side)->transaction(static function () use ($side, $drive): void {
            DB::connection($side)->table('v_drive_changes')->where('drive_id', $drive->model()->getKey())->delete();
            DB::connection($side)->table('v_drive_change_heads')->where('drive_id', $drive->model()->getKey())->update(['pruned_through' => 5]);
        });
    });

    expect(fn () => $drive->changes()->list($start))->toThrow(CursorExpiredException::class)
        ->and($pruned)->toBeTrue();
});
