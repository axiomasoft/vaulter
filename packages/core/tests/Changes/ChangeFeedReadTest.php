<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Changes\ChangeCursor;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Changes\ChangePage;
use Vaulter\Data\Changes\NodeChange;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Changes\ChangeOperation;
use Vaulter\Enums\Changes\ChangeReason;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Changes\CursorExpiredException;
use Vaulter\Exceptions\Changes\InvalidCursorException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Tests\TestCase;

/**
 * Reading the change journal (PLAN2.D50, D59; P5-I15, I17, I18): the reader sees an
 * upsert with a snapshot only for a node it may see now; a node that left its view by a
 * move or an access change is a remove with that reason; anything else it cannot see is
 * skipped. A pruned or reset journal expires the cursor (410), a forged or foreign
 * cursor is invalid (400). No wrapping test transaction: reads run as in production.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $this->drive = DriveTestHelpers::drive();
    $this->shared = $this->drive->folders()->create('shared');
    $this->hidden = $this->drive->folders()->create('hidden');
    // User 1 may see the shared folder and what is below it, nothing else.
    AccessTestHelpers::grant($this->shared, RoleRegistry::READER);
    $this->reader = $this->drive->actingAs(AccessTestHelpers::user(1));
    $this->cursor = $this->reader->changes()->startCursor();
});

/** @return list<array{string, string, ?string}> node id, operation and reason of each change */
function changeFeedSummary(ChangePage $page): array
{
    return array_map(static fn (NodeChange $change): array => [$change->nodeId, $change->operation->value, $change->reason?->value], $page->changes);
}

it('(a) returns an upsert with the current snapshot of a node the reader may see', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $this->drive->nodes()->rename($doc, 'doc-2');

    $page = $this->reader->changes()->list($this->cursor);
    $change = $page->changes[0];

    expect($page->changes)->toHaveCount(1)
        ->and($change->operation)->toBe(ChangeOperation::Upsert)
        ->and($change->node)->toBeInstanceOf(NodeSnapshot::class)
        ->and($change->node->name)->toBe('doc-2')
        ->and($change->nodeSequence)->toBe(DriveTestHelpers::model($doc->id)->sequence)
        ->and($change->subtree)->toBeFalse()
        ->and($page->hasMore)->toBeFalse();
});

it('(b) turns a move out of the reader\'s view into remove(moved_out) without a snapshot', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();
    $this->drive->nodes()->move($doc, $this->hidden);

    $page = $this->reader->changes()->list($start);

    expect(changeFeedSummary($page))->toBe([[(string) $doc->id, 'remove', 'moved_out']])
        ->and($page->changes[0]->node)->toBeNull();
});

it('(c) turns a revoked grant into remove(permission_lost) for the folder', function (): void {
    $team = $this->drive->folders()->create('team');
    $grant = $this->drive->access()->grant($team, new GrantInput(subject: Subject::ofModel(AccessTestHelpers::user(1)), role: RoleRegistry::READER));
    $granted = $this->reader->changes()->list($this->cursor);
    $this->drive->access()->revoke($team, $grant);

    $page = $this->reader->changes()->list($granted->cursor);

    expect(changeFeedSummary($granted))->toBe([[(string) $team->id, 'upsert', null]])
        ->and($granted->changes[0]->subtree)->toBeTrue()
        ->and(changeFeedSummary($page))->toBe([[(string) $team->id, 'remove', 'permission_lost']]);
});

it('(d) reports a trashed node as remove(deleted)', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();
    $this->drive->nodes()->trash($doc);

    expect(changeFeedSummary($this->reader->changes()->list($start)))->toBe([[(string) $doc->id, 'remove', 'deleted']]);
});

it('(e) skips a node created where the reader cannot see, while the system actor sees it', function (): void {
    $secret = $this->drive->folders()->create('secret', $this->hidden);

    $page = $this->reader->changes()->list($this->cursor);
    $system = $this->drive->changes()->list($this->cursor);

    expect($page->changes)->toBe([])
        ->and($page->hasMore)->toBeFalse()
        ->and(changeFeedSummary($system))->toBe([[(string) $secret->id, 'upsert', null]]);
});

it('(f) expires a cursor whose entries were pruned (410)', function (): void {
    $this->drive->folders()->create('doc', $this->shared);
    $this->travel(31)->days();
    $this->artisan('vaulter:changes:prune')->assertSuccessful();

    expect(fn () => $this->reader->changes()->list($this->cursor))->toThrow(CursorExpiredException::class);

    // A cursor taken after the prune is still good.
    expect($this->reader->changes()->list($this->reader->changes()->startCursor())->changes)->toBe([]);
});

it('(f) notices a prune that runs between reading the head and reading the entries', function (): void {
    $this->drive->folders()->create('doc', $this->shared);
    $driveId = $this->drive->model()->getKey();
    $pruned = false;
    app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query) use (&$pruned, $driveId): void {
        if ($pruned || !str_contains($query->sql, 'v_drive_change_heads') || !str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }
        $pruned = true;
        app(VaulterDatabase::class)->transaction(static function () use ($driveId): void {
            app(ChangeHeadRepository::class)->find($driveId, 'update');
            $batch = app(ChangeEntryRepository::class)->deleteOldest($driveId, PHP_INT_MAX, 1000);
            app(ChangeHeadRepository::class)->markPruned($driveId, $batch['seq'] ?? 0);
        });
    });

    expect(fn () => $this->reader->changes()->list($this->cursor))->toThrow(CursorExpiredException::class);
    expect($pruned)->toBeTrue();
});

it('(g) rejects a forged cursor and a cursor of another drive (400)', function (): void {
    [$payload, $signature] = explode('.', $this->cursor);
    $other = DriveTestHelpers::drive()->changes()->startCursor();

    expect(fn () => $this->reader->changes()->list($payload.'.'.strrev($signature)))->toThrow(InvalidCursorException::class)
        ->and(fn () => $this->reader->changes()->list($other))->toThrow(InvalidCursorException::class, 'another drive');
});

it('(g) rejects a validly signed cursor naming another tenant', function (): void {
    $forged = (new ChangeCursor(tenantId: 'tenant-b', driveId: (string) $this->drive->model()->getKey(), epoch: 1, after: 0))->encode((string) config('app.key'));

    expect(fn () => $this->reader->changes()->list($forged))->toThrow(InvalidCursorException::class, 'another drive');
});

it('(h) expires every cursor after a reset of the journal (410)', function (): void {
    $this->artisan('vaulter:changes:reset', ['drive' => (string) $this->drive->model()->getKey()])->assertSuccessful();

    expect(fn () => $this->reader->changes()->list($this->cursor))->toThrow(CursorExpiredException::class, 'reset');

    $fresh = $this->reader->changes()->startCursor();
    $doc = $this->drive->folders()->create('doc', $this->shared);
    expect(changeFeedSummary($this->reader->changes()->list($fresh)))->toBe([[(string) $doc->id, 'upsert', null]]);
});

it('(i) pages by limit and advances the cursor over entries the reader cannot see', function (): void {
    $visible = [];
    foreach (range(1, 3) as $i) {
        $this->drive->folders()->create('hidden-'.$i, $this->hidden);
        $visible[] = (string) $this->drive->folders()->create('shown-'.$i, $this->shared)->id;
    }
    $this->drive->folders()->create('hidden-last', $this->hidden);

    $seen = [];
    $pages = 0;
    $cursor = $this->cursor;
    do {
        $page = $this->reader->changes()->list($cursor, limit: 2);
        $seen = [...$seen, ...array_column(changeFeedSummary($page), 0)];
        expect(count($page->changes))->toBeLessThanOrEqual(2);
        $cursor = $page->cursor;
        $pages++;
    } while ($page->hasMore);

    expect($seen)->toBe($visible)
        ->and($pages)->toBe(4)
        ->and($this->reader->changes()->list($cursor)->changes)->toBe([]);
});

it('refuses a limit outside 1..max_page_size', function (int $limit): void {
    config()->set('vaulter.changes.max_page_size', 50);

    $this->reader->changes()->list($this->cursor, limit: $limit);
})->with([0, 51])->throws(InvalidArgumentException::class);

it('reads an archived drive and hides a drive being deleted', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $this->drive->archive();

    expect(changeFeedSummary($this->reader->changes()->list($this->cursor)))->toBe([[(string) $doc->id, 'upsert', null]]);

    $this->drive->model()->forceFill(['status' => DriveStatus::Deleting])->saveQuietly();
    // The handle itself refuses a drive being deleted, before the journal is read.
    expect(fn () => $this->reader->changes()->list($this->cursor))->toThrow(StorageNotFoundException::class);
});

it('reads inside a host transaction with the head locked for share', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);

    $page = app(VaulterDatabase::class)->transaction(fn (): ChangePage => $this->reader->changes()->list($this->cursor));

    expect(changeFeedSummary($page))->toBe([[(string) $doc->id, 'upsert', null]]);
});

it('maps an expired cursor to 410 cursor_expired', function (): void {
    $exception = CursorExpiredException::pruned();

    expect([$exception->problemCode(), $exception->httpStatus()])->toBe(['cursor_expired', 410])
        ->and(ChangeReason::cases())->toHaveCount(3);
});

it('starts a reader at the end of the journal', function (): void {
    $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();

    expect($this->reader->changes()->list($start)->changes)->toBe([])
        ->and(ChangeCursor::decode($start, (string) config('app.key'))->after)->toBe(3);
});
