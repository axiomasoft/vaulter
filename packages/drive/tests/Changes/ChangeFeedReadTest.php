<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Changes\ChangeCursor;
use Vaulter\Drive\Changes\ReaderSyncIdentity;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Changes\ChangePage;
use Vaulter\Drive\Data\Changes\NodeChange;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Changes\CursorExpiredException;
use Vaulter\Drive\Exceptions\Changes\InvalidCursorException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Reading the change journal (PLAN2.D50, D59; P5-I15, I17, I18): the reader sees an
 * upsert with a snapshot only for a node it may see now; a node that left its view by a
 * move or an access change is a remove with that reason; anything else it cannot see is
 * skipped. A pruned or reset journal expires the cursor (410), a forged or foreign
 * cursor is invalid (400). No wrapping test transaction: reads run as in production.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $this->drive = DriveTestHelpers::drive();
    $this->shared = $this->drive->folders()->create('shared');
    $this->hidden = $this->drive->folders()->create('hidden');
    // User 1 may see the shared folder and what is below it, nothing else.
    AccessTestHelpers::grant($this->shared, RoleRegistry::READER);
    $this->reader = $this->drive->actingAs(AccessTestHelpers::user(1));
    $this->cursor = $this->reader->changes()->startCursor();
});

/** @return list<array{?string, string}> node id (null for a remove, which names none) and operation of each change */
function changeFeedSummary(ChangePage $page): array
{
    return array_map(static fn (NodeChange $change): array => [$change->nodeId, $change->operation->value], $page->changes);
}

/** The sync key user $user is given for $nodeId of $drive: the U1 primitive over the drive's own tenant. */
function changeKeyOf(object $drive, int|string $nodeId, int $user = 1): string
{
    $actor = AccessTestHelpers::user($user);

    return (new ReaderSyncIdentity((string) config('app.key'), 1))
        ->syncKey($drive->model()->getRawOriginal('tenant_id'), $drive->model()->getKey(), new ActorRef($actor->getMorphClass(), (string) $actor->getKey()), $nodeId);
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

it('(b) turns a move out of the reader\'s view into a minimal keyed remove without a snapshot', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();
    $this->drive->nodes()->move($doc, $this->hidden);

    $page = $this->reader->changes()->list($start);

    expect(changeFeedSummary($page))->toBe([[null, 'remove']])
        ->and($page->changes[0]->node)->toBeNull()
        ->and($page->changes[0]->syncKey)->toBe(changeKeyOf($this->drive, $doc->id))
        ->and($page->changes[0]->syncKey)->not->toContain((string) $doc->id);
});

it('(c) turns a revoked grant into a keyed remove for the folder', function (): void {
    $team = $this->drive->folders()->create('team');
    $grant = $this->drive->access()->grant($team, new GrantInput(subject: Subject::ofModel(AccessTestHelpers::user(1)), role: RoleRegistry::READER));
    $granted = $this->reader->changes()->list($this->cursor);
    $this->drive->access()->revoke($team, $grant);

    $page = $this->reader->changes()->list($granted->cursor);

    expect(changeFeedSummary($granted))->toBe([[(string) $team->id, 'upsert']])
        ->and($granted->changes[0]->subtree)->toBeTrue()
        ->and(changeFeedSummary($page))->toBe([[null, 'remove']])
        ->and($page->changes[0]->syncKey)->toBe(changeKeyOf($this->drive, $team->id))
        ->and($granted->changes[0]->syncKey)->toBe($page->changes[0]->syncKey);
});

it('(d) reports a trashed node as a keyed remove', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();
    $this->drive->nodes()->trash($doc);

    $page = $this->reader->changes()->list($start);

    expect(changeFeedSummary($page))->toBe([[null, 'remove']])
        ->and($page->changes[0]->syncKey)->toBe(changeKeyOf($this->drive, $doc->id));
});

it('(d) keeps the remove of a trashed node when it is restored into a hidden folder and renamed', function (): void {
    // The compaction fix that was proposed for this was a false premise: only upserts are
    // compacted, so the remove survives the later upserts of a node the reader cannot see.
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();
    $this->drive->nodes()->trash($doc);
    $this->drive->trash()->restore($doc, RestoreOptions::make()->destination($this->hidden));
    $this->drive->nodes()->rename($doc, 'doc-hidden');

    $page = $this->reader->changes()->list($start);

    expect(changeFeedSummary($page))->toBe([[null, 'remove']])
        ->and($page->changes[0]->syncKey)->toBe(changeKeyOf($this->drive, $doc->id));
});

it('(e) skips a node created where the reader cannot see, while the system actor sees it', function (): void {
    // A cursor is bound to its principal (PLAN4.D20): the system actor takes its own C0.
    $systemCursor = $this->drive->changes()->startCursor();
    $secret = $this->drive->folders()->create('secret', $this->hidden);

    $page = $this->reader->changes()->list($this->cursor);
    $system = $this->drive->changes()->list($systemCursor);

    expect($page->changes)->toBe([])
        ->and($page->hasMore)->toBeFalse()
        ->and(changeFeedSummary($system))->toBe([[(string) $secret->id, 'upsert']])
        ->and($system->changes[0]->syncKey)->toHaveLength(43)->not->toBe(changeKeyOf($this->drive, $secret->id));
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
    $forged = (new ChangeCursor(
        tenantId: 'tenant-b', driveId: (string) $this->drive->model()->getKey(),
        actorType: AccessTestHelpers::user(1)->getMorphClass(), actorId: (string) AccessTestHelpers::user(1)->getKey(), actorReason: '',
        keyGeneration: 1, epoch: 1, after: 0, issuedAt: now()->getTimestamp(), notAfter: now()->getTimestamp() + 60,
    ))->encode((new ReaderSyncIdentity((string) config('app.key'), 1))->cursorSubkey());

    expect(fn () => $this->reader->changes()->list($forged))->toThrow(InvalidCursorException::class, 'another drive');
});

it('(h) expires every cursor after a reset of the journal (410)', function (): void {
    $this->artisan('vaulter:changes:reset', ['drive' => (string) $this->drive->model()->getKey()])->assertSuccessful();

    expect(fn () => $this->reader->changes()->list($this->cursor))->toThrow(CursorExpiredException::class, 'reset');

    $fresh = $this->reader->changes()->startCursor();
    $doc = $this->drive->folders()->create('doc', $this->shared);
    expect(changeFeedSummary($this->reader->changes()->list($fresh)))->toBe([[(string) $doc->id, 'upsert']]);
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
    config()->set('vaulter-drive.changes.max_page_size', 50);

    $this->reader->changes()->list($this->cursor, limit: $limit);
})->with([0, 51])->throws(InvalidArgumentException::class);

it('reads an archived drive and hides a drive being deleted', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);
    $this->drive->archive();

    expect(changeFeedSummary($this->reader->changes()->list($this->cursor)))->toBe([[(string) $doc->id, 'upsert']]);

    $this->drive->model()->forceFill(['status' => DriveStatus::Deleting])->saveQuietly();
    // The handle itself refuses a drive being deleted, before the journal is read.
    expect(fn () => $this->reader->changes()->list($this->cursor))->toThrow(StorageNotFoundException::class);
});

it('reads inside a host transaction with the head locked for share', function (): void {
    $doc = $this->drive->folders()->create('doc', $this->shared);

    $page = app(VaulterDatabase::class)->transaction(fn (): ChangePage => $this->reader->changes()->list($this->cursor));

    expect(changeFeedSummary($page))->toBe([[(string) $doc->id, 'upsert']]);
});

it('maps an expired cursor to 410 cursor_expired', function (): void {
    $exception = CursorExpiredException::pruned();

    expect([$exception->problemCode(), $exception->httpStatus()])->toBe(['cursor_expired', 410]);
});

it('starts a reader at the end of the journal', function (): void {
    $this->drive->folders()->create('doc', $this->shared);
    $start = $this->reader->changes()->startCursor();

    expect($this->reader->changes()->list($start)->changes)->toBe([])
        ->and(ChangeCursor::decode($start, (new ReaderSyncIdentity((string) config('app.key'), 1))->cursorSubkey())->after)->toBe(3);
});

it('(e) keys a node per typed system reason and gives an unbound, null or anonymous viewer no key', function (): void {
    $mapper = app(NodeSnapshotMapper::class);
    $model = DriveTestHelpers::model($this->shared);
    $keyFor = static fn (?ActorRef $viewer): ?string => $mapper->map($model, $viewer)->syncKey;

    $audit = $keyFor(new ActorRef(ActorRef::SYSTEM_TYPE, null, 'audit'));
    $billing = $keyFor(new ActorRef(ActorRef::SYSTEM_TYPE, null, 'billing'));
    $member = AccessTestHelpers::user(1);
    $user = $keyFor(new ActorRef($member->getMorphClass(), (string) $member->getKey()));

    expect($audit)->toHaveLength(43)->and($billing)->toHaveLength(43)
        ->and($audit)->not->toBe($billing)->and($audit)->not->toBe($user)
        ->and($keyFor(new ActorRef(ActorRef::SYSTEM_TYPE, null, 'audit')))->toBe($audit)
        // No viewer (a share link, an unbound read) and an actor without a type carry no key at all.
        ->and($keyFor(null))->toBeNull()
        ->and($keyFor(new ActorRef(null, null)))->toBeNull();
});
