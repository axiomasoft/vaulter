<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Access\AccessInvalidation;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Changes\ChangeCursor;
use Vaulter\Drive\Changes\ReaderSyncIdentity;
use Vaulter\Drive\Data\DriveRef;
use Vaulter\Drive\Exceptions\Changes\CursorExpiredException;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;

/**
 * The cursor lifecycle (PLAN4.P5.9/U3, D20) on a fake clock: the deadline is the
 * smallest of issued_at plus the CURRENT cap and the earliest live grant expiry of the
 * reader's own subject-set in this drive, latched so it never grows; it is checked
 * before and after the page is projected. A host access invalidation raises the epoch
 * in the transaction of its event. Deterministic SQLite timelines only: real engine
 * isolation, lock order and races are the owner's qualification (P8.2).
 */
uses(TestCase::class, DatabaseMigrations::class);

const SYNC_T0 = 1_000_000;

function syncAt(int $offset): void
{
    // In the application timezone, as Carbon::now() is outside a test.
    Carbon::setTestNow(Carbon::createFromTimestamp(SYNC_T0 + $offset, date_default_timezone_get()));
}

function syncDeadline(string $cursor): int
{
    return ChangeCursor::decode($cursor, (new ReaderSyncIdentity((string) config('app.key'), 1))->cursorSubkey())->notAfter;
}

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    syncAt(0);
    $this->drive = DriveTestHelpers::drive();
    $this->a = $this->drive->folders()->create('a');
    $this->b = $this->drive->folders()->create('b');
    $this->reader = $this->drive->actingAs(AccessTestHelpers::user(1));
    $this->heads = app(ChangeHeadRepository::class);
});

afterEach(fn () => Carbon::setTestNow());

it('takes the cap when the reader holds only permanent grants or none', function (): void {
    expect(syncDeadline($this->reader->changes()->startCursor()))->toBe(SYNC_T0 + 86400);

    AccessTestHelpers::grant($this->a, RoleRegistry::READER);
    AccessTestHelpers::grant($this->b, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 - 1));

    expect(syncDeadline($this->reader->changes()->startCursor()))->toBe(SYNC_T0 + 86400);
});

it('latches the earliest own grant expiry, ignoring other subjects and other drives', function (): void {
    $expiring = AccessTestHelpers::grant($this->a, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 100));
    AccessTestHelpers::grant($this->b, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 500));
    AccessTestHelpers::grant($this->a, RoleRegistry::READER, subjectId: 2, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 10));
    $other = DriveTestHelpers::drive();
    AccessTestHelpers::grant($other->folders()->create('x'), RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 5));

    $c0 = $this->reader->changes()->startCursor();
    expect(syncDeadline($c0))->toBe(SYNC_T0 + 100);

    // t50: the grant behind the deadline goes away; the deadline does not grow.
    syncAt(50);
    $expiring->delete();
    $next = $this->reader->changes()->list($c0)->cursor;
    expect(syncDeadline($next))->toBe(SYNC_T0 + 100);

    // A smaller cap counts from issued_at, not from now.
    config()->set('vaulter-drive.changes.cursor_max_age_seconds', 60);
    syncAt(60);
    $shortened = $this->reader->changes()->list($next)->cursor;
    expect(syncDeadline($shortened))->toBe(SYNC_T0 + 60);

    syncAt(61);
    expect(fn () => $this->reader->changes()->list($shortened))->toThrow(CursorExpiredException::class);
});

it('shortens the latched deadline when a nearer grant appears', function (): void {
    $c0 = $this->reader->changes()->startCursor();
    syncAt(10);
    AccessTestHelpers::grant($this->a, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 40));

    $next = $this->reader->changes()->list($c0)->cursor;
    expect(syncDeadline($next))->toBe(SYNC_T0 + 40);

    syncAt(41);
    expect(fn () => $this->reader->changes()->list($next))->toThrow(CursorExpiredException::class);
});

it('expires at a grant deadline without any mutation, before data', function (): void {
    AccessTestHelpers::grant($this->a, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 100));
    $c0 = $this->reader->changes()->startCursor();
    $this->drive->folders()->create('doc', $this->a);

    syncAt(101);
    expect(fn () => $this->reader->changes()->list($c0))->toThrow(CursorExpiredException::class);
});

it('reads the grant deadline in a non-UTC application timezone at the instant the resolver does', function (string $timezone): void {
    $originalTimezone = date_default_timezone_get();
    $originalConfig = config('app.timezone');
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);
    try {
        syncAt(0);
        // Stored as the naive wall clock of the application timezone, as a host writes it.
        $grant = AccessTestHelpers::grant($this->a, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 100, $timezone));
        expect(Carbon::now()->getOffset())->not->toBe(0)
            ->and($grant->refresh()->expires_at?->getTimestamp())->toBe(SYNC_T0 + 100);

        $c0 = $this->reader->changes()->startCursor();
        expect(syncDeadline($c0))->toBe(SYNC_T0 + 100);
        $this->drive->folders()->create('doc', $this->a);

        // Valid before the expiry, and the deadline does not grow page to page.
        syncAt(50);
        $page = $this->reader->changes()->list($c0);
        expect($page->changes)->toHaveCount(1)->and(syncDeadline($page->cursor))->toBe(SYNC_T0 + 100);
        syncAt(100);
        expect(syncDeadline($this->reader->changes()->list($page->cursor)->cursor))->toBe(SYNC_T0 + 100);

        // No mutation since: the cursor dies at the real expiry instant, before data.
        syncAt(101);
        expect(fn () => $this->reader->changes()->list($page->cursor))->toThrow(CursorExpiredException::class)
            ->and(fn () => $this->reader->changes()->list($c0))->toThrow(CursorExpiredException::class);
    } finally {
        date_default_timezone_set($originalTimezone);
        config()->set('app.timezone', $originalConfig);
    }
})->with(['UTC+3' => 'Europe/Moscow', 'UTC-5' => 'America/New_York']);

it('hands out no page when the deadline passes while the page is read and projected', function (): void {
    AccessTestHelpers::grant($this->a, RoleRegistry::READER, expiresAt: Carbon::createFromTimestamp(SYNC_T0 + 100));
    $c0 = $this->reader->changes()->startCursor();
    $this->drive->folders()->create('doc', $this->a);
    syncAt(90);
    DB::listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'drive_changes')) {
            syncAt(101);
        }
    });

    expect(fn () => $this->reader->changes()->list($c0))->toThrow(CursorExpiredException::class);
});

it('expires every cursor of a drive or of all drives on a host invalidation, seq unchanged', function (): void {
    $other = DriveTestHelpers::drive();
    $mine = $this->reader->changes()->startCursor();
    $theirs = $other->changes()->startCursor();
    $seq = $this->heads->find($this->drive->model()->getKey())['last_seq'];

    app(AccessInvalidation::class)->changed(DriveRef::of($this->drive), 'host-policy');
    expect(fn () => $this->reader->changes()->list($mine))->toThrow(CursorExpiredException::class)
        ->and($other->changes()->list($theirs)->changes)->toBe([]);

    $mine = $this->reader->changes()->startCursor();
    app(AccessInvalidation::class)->changed(null, 'host-role');

    expect(fn () => $this->reader->changes()->list($mine))->toThrow(CursorExpiredException::class)
        ->and(fn () => $other->changes()->list($theirs))->toThrow(CursorExpiredException::class)
        ->and($this->heads->find($this->drive->model()->getKey()))->toMatchArray(['last_seq' => $seq, 'epoch' => 3])
        ->and($this->reader->changes()->list($this->reader->changes()->startCursor())->changes)->toBe([]);
});

it('does not reset the cursor on ordinary node and archive events', function (): void {
    AccessTestHelpers::grant($this->a, RoleRegistry::READER);
    $c0 = $this->reader->changes()->startCursor();
    $doc = $this->drive->folders()->create('doc', $this->a);
    $this->drive->nodes()->rename($doc, 'doc-2');
    $this->drive->archive();

    expect($this->reader->changes()->list($c0)->changes)->toHaveCount(1)
        ->and($this->heads->find($this->drive->model()->getKey())['epoch'])->toBe(1);
});

it('refuses to start inside an open transaction and leaves no head', function (): void {
    $this->heads->delete($this->drive->model()->getKey());

    expect(fn () => DB::transaction(fn () => $this->reader->changes()->startCursor()))->toThrow(LogicException::class)
        ->and($this->heads->find($this->drive->model()->getKey()))->toBeNull();
});
