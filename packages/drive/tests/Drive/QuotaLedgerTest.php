<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Events\Files\FileRevisionDeleted;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\CannotDeleteHeadRevisionException;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Drive\Exceptions\RevisionPinnedException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Quota\QuotaCalculator;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * P4-I18 (PLAN2.D48): used_bytes is the logical sum of every retained revision of the
 * drive, trash included, without a dedup discount — on every path that adds or
 * removes a revision, and always equal to QuotaCalculator's recount.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function quotaLedgerUpload(DriveHandle $drive, string $bytes, string $name = 'a.bin'): NodeSnapshot
{
    return $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), $name));
}

function quotaLedgerRevision(DriveHandle $drive, Node|NodeSnapshot $node, string $bytes): FileRevisionSnapshot
{
    return $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), $node->name));
}

/** used_bytes after the step, asserted equal to the recount. */
function quotaLedgerUsed(DriveHandle $drive): int
{
    $used = $drive->model()->refresh()->used_bytes;
    expect(app(QuotaCalculator::class)->usedBytes($drive->model()->id))->toBe($used);

    return $used;
}

it('counts every retained revision on every path', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = quotaLedgerUpload($drive, str_repeat('a', 100));
    expect(quotaLedgerUsed($drive))->toBe(100);
    $r1 = DriveTestHelpers::model($node)->file()->firstOrFail()->current_revision_id;

    $r2 = quotaLedgerRevision($drive, $node, str_repeat('b', 120));
    expect(quotaLedgerUsed($drive))->toBe(220);

    $drive->nodes()->trash($node);
    expect(quotaLedgerUsed($drive))->toBe(220)
        ->and(app(QuotaCalculator::class)->trashedBytes($drive->model()->id))->toBe(220);
    $drive->trash()->restore($node);
    expect(app(QuotaCalculator::class)->trashedBytes($drive->model()->id))->toBe(0);

    $restored = $drive->files()->restoreRevision($node, $r1);
    expect(quotaLedgerUsed($drive))->toBe(320)
        ->and($restored->sizeBytes)->toBe(100)
        ->and((string) $restored->restoredFromRevisionId)->toBe((string) $r1);

    $drive->files()->deleteRevision($node, $r2);
    expect(quotaLedgerUsed($drive))->toBe(200)
        ->and(FileRevision::query()->whereKey($r2->id)->exists())->toBeFalse();

    $drive->nodes()->trash($node);
    $drive->trash()->purge($node);
    expect(quotaLedgerUsed($drive))->toBe(0);
});

it('charges identical uploads twice: the quota is logical, never deduplicated', function (): void {
    $drive = DriveTestHelpers::drive();
    quotaLedgerUpload($drive, str_repeat('x', 64), 'one.bin');
    quotaLedgerUpload($drive, str_repeat('x', 64), 'two.bin');
    $node = quotaLedgerUpload($drive, 'other', 'three.bin');
    quotaLedgerRevision($drive, $node, str_repeat('x', 64));

    expect(Blob::query()->count())->toBe(2)
        ->and(quotaLedgerUsed($drive))->toBe(3 * 64 + 5);
});

it('charges a copy with the head it copies, not the source history', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = quotaLedgerUpload($drive, str_repeat('a', 10));
    quotaLedgerRevision($drive, $node, str_repeat('b', 30));
    expect(quotaLedgerUsed($drive))->toBe(40);

    $copy = $drive->nodes()->copy($node, destination: null, options: new CopyOptions(name: 'copy.bin'));

    expect(DriveTestHelpers::model($copy)->file()->firstOrFail()->versions()->count())->toBe(1)
        ->and(quotaLedgerUsed($drive))->toBe(70);
});

it('frees every revision of a purged subtree', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('docs');
    $a = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aaaa'), 'a.bin'), new UploadOptions(parent: $folder));
    quotaLedgerRevision($drive, $a, 'aaaaaaaa');
    $b = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bb'), 'b.bin'), new UploadOptions(parent: $folder));
    quotaLedgerRevision($drive, $b, 'bbb');
    $outside = quotaLedgerUpload($drive, 'keep');
    expect(quotaLedgerUsed($drive))->toBe(4 + 8 + 2 + 3 + 4);

    $drive->nodes()->trash($folder);
    $drive->trash()->purge($folder);

    expect(quotaLedgerUsed($drive))->toBe(4)
        ->and(DriveTestHelpers::model($outside)->exists)->toBeTrue();
});

it('refuses a revision past the quota and still lets a drive over its quota delete', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = quotaLedgerUpload($drive, str_repeat('a', 6));
    $old = DriveTestHelpers::model($node)->file()->firstOrFail()->current_revision_id;
    quotaLedgerRevision($drive, $node, str_repeat('b', 4));
    // 10 retained bytes; a quota of 12 admits 2 more, not 3.
    app(VaulterDatabase::class)->write(fn () => $drive->model()->forceFill(['quota_bytes' => 12])->save());
    expect(fn () => quotaLedgerRevision($drive, $node, 'ccc'))->toThrow(QuotaExceededException::class)
        ->and(fn () => $drive->files()->restoreRevision($node, $old))->toThrow(QuotaExceededException::class)
        ->and(fn () => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('ccc'), 'b.bin'), new UploadOptions(onConflict: OnNameConflict::Rename)))->toThrow(QuotaExceededException::class)
        ->and(quotaLedgerUsed($drive))->toBe(10);

    // A lowered quota leaves the drive over it: deleting still works and frees bytes.
    app(VaulterDatabase::class)->write(fn () => $drive->model()->forceFill(['quota_bytes' => 5])->save());
    $drive->files()->deleteRevision($node, $old);
    expect(quotaLedgerUsed($drive))->toBe(4);
});

it('refuses to delete the head or a pinned revision and keeps their bytes', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = quotaLedgerUpload($drive, 'first');
    $first = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $head = quotaLedgerRevision($drive, $node, 'second');

    expect(fn () => $drive->files()->deleteRevision($node, $head))->toThrow(CannotDeleteHeadRevisionException::class);

    $pinned = $drive->files()->setRevisionRetention($node, $first->id, keepForever: true);
    expect($pinned->keepForever)->toBeTrue()
        ->and(fn () => $drive->files()->deleteRevision($node, $first->id))->toThrow(RevisionPinnedException::class)
        ->and(quotaLedgerUsed($drive))->toBe(11);

    expect($drive->files()->setRevisionRetention($node, $first->id, keepForever: false)->keepForever)->toBeFalse();
    Event::fake([FileRevisionDeleted::class]);
    $drive->files()->deleteRevision($node, $first->id);
    Event::assertDispatched(FileRevisionDeleted::class, fn (FileRevisionDeleted $event): bool => (string) $event->revisionId === (string) $first->id
        && $event->revisionNo === 1 && $event->sizeBytes === 5);
    expect(quotaLedgerUsed($drive))->toBe(6)
        ->and(FileRevisionBlob::query()->where('file_revision_id', $first->id)->exists())->toBeFalse();
});

it('forgets the source of restores whose source revision is deleted', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = quotaLedgerUpload($drive, 'first');
    $first = DriveTestHelpers::model($node)->file()->firstOrFail()->current_revision_id;
    quotaLedgerRevision($drive, $node, 'second');
    $restored = $drive->files()->restoreRevision($node, $first);

    $drive->files()->deleteRevision($node, $first);

    expect(FileRevision::query()->findOrFail($restored->id)->restored_from_revision_id)->toBeNull()
        ->and($drive->files()->read($node)->stream)->not->toBeNull();
});

it('lets a writer pin but only a manager delete revisions', function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $system = DriveTestHelpers::drive();
    $node = quotaLedgerUpload($system, 'first');
    $first = DriveTestHelpers::model($node)->file()->firstOrFail()->current_revision_id;
    quotaLedgerRevision($system, $node, 'second');
    $user = new TestUser;
    $user->forceFill(['id' => 31]);
    $user->exists = true;
    AccessTestHelpers::grant(node: $system->root(), role: RoleRegistry::WRITER, subjectId: 31, subjectType: $user->getMorphClass());
    $writer = $system->actingAs($user);

    expect($writer->files()->setRevisionRetention($node, $first, keepForever: true)->keepForever)->toBeTrue()
        ->and(fn () => $writer->files()->deleteRevision($node, $first))->toThrow(PermissionDeniedException::class);

    $owner = $system->actingAs(DriveTestHelpers::actor($system, 32));
    $owner->files()->setRevisionRetention($node, $first, keepForever: false);
    $owner->files()->deleteRevision($node, $first);
    expect(FileRevision::query()->whereKey($first)->exists())->toBeFalse();
});
