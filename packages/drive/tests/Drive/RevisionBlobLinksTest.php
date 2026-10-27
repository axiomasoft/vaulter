<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Fixtures\RevisionBlobPins;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * The drive's revision links (D23, P4/P5): every edge in v_file_revision_blobs carries a media pin
 * "drive.revision_blob" keyed "{revision}:{role}:{ordinal}", written and removed in the same transaction as the edge,
 * and every path that deletes revisions removes their pins first — a pin left behind would keep the blob forever.
 */

beforeEach(function (): void {
    DriveTestHelpers::insideOperation();
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

/** @return list<int|string> the revision ids of a file node, oldest first */
function linksRevisionIds(Node|string|int $node): array
{
    return DriveTestHelpers::model($node instanceof Node ? $node->id : $node)->file()->firstOrFail()->versions()->orderBy('number')->pluck('id')->all();
}

function linksBlob(string $bytes): Blob
{
    return Blob::query()->where('sha256', hash('sha256', $bytes))->firstOrFail();
}

it('writes the edge and its pin together on upload, keyed by the edge without the blob', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromString('one', 'a.txt'));
    [$revision] = linksRevisionIds($file->id);
    $blob = linksBlob('one');

    expect(RevisionBlobPins::edges($blob))->toBe(1)
        ->and(RevisionBlobPins::keys($blob))->toBe([RevisionBlobLinks::referenceKey($revision, 'data', 0)])
        ->and(RevisionBlobLinks::referenceKey($revision))->toBe($revision.':data:0')
        ->and(app(BlobPins::class)->countByOwner())->toBe([RevisionBlobLinks::OWNER_TYPE => 1]);
});

it('gives a new revision, a restored revision and a copy each a pin of its own', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromString('first bytes', 'a.txt'));
    $drive->files()->addRevision($file, UploadSource::fromString('second bytes', 'a.txt'));
    [$first, $second] = linksRevisionIds($file->id);
    $restored = $drive->files()->restoreRevision($file, $first);
    $copy = $drive->nodes()->copy($file, options: new CopyOptions(name: 'b.txt'));
    [$copied] = linksRevisionIds($copy->id);

    // The copy takes the head, which the restore made the first bytes again.
    expect(RevisionBlobPins::keys(linksBlob('first bytes')))->toBe(collect([$first, $restored->id, $copied])->map(fn ($id): string => RevisionBlobLinks::referenceKey($id))->sort()->values()->all())
        ->and(RevisionBlobPins::keys(linksBlob('second bytes')))->toBe([RevisionBlobLinks::referenceKey($second)])
        ->and(RevisionBlobPins::all())->toBe(FileRevisionBlob::query()->count());
});

it('removes the edges and pins of a deleted revision only', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromString('same', 'a.txt'));
    $drive->files()->addRevision($file, UploadSource::fromString('same', 'a.txt'));
    [$first, $second] = linksRevisionIds($file->id);
    $blob = linksBlob('same');
    expect(RevisionBlobPins::pins($blob))->toBe(2);

    $drive->files()->deleteRevision($file, $first);

    expect(RevisionBlobPins::keys($blob))->toBe([RevisionBlobLinks::referenceKey($second)])
        ->and(RevisionBlobPins::edges($blob))->toBe(1)
        ->and(FileRevision::query()->whereKey($first)->exists())->toBeFalse();
});

it('releases every revision pin of a purged file and lets the collector take the blobs', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromString('purged one', 'a.txt'));
    $drive->files()->addRevision($file, UploadSource::fromString('purged two', 'a.txt'));
    $kept = $drive->files()->upload(UploadSource::fromString('kept', 'k.txt'));
    $drive->nodes()->trash($file);

    $drive->trash()->purge($file);

    expect(RevisionBlobPins::pins(linksBlob('purged one')))->toBe(0)
        ->and(RevisionBlobPins::pins(linksBlob('purged two')))->toBe(0)
        ->and(RevisionBlobPins::pins(linksBlob('kept')))->toBe(1);

    app(BlobReconciler::class)->reconcile();
    expect(app(BlobGarbageCollector::class)->mark(graceHours: 24)['tombstoned'])->toBe(2)
        ->and(linksBlob('purged one')->status)->toBe(BlobStatus::Tombstoned)
        ->and(linksBlob('kept')->status)->toBe(BlobStatus::Committed)
        ->and(DriveTestHelpers::model($kept->id)->exists)->toBeTrue();
});

it('leaves no drive pin behind when a drive is purged, including revisions outside the root subtree', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->files()->upload(UploadSource::fromString('in the tree', 'a.txt'));
    $stray = $drive->files()->upload(UploadSource::fromString('stray', 's.txt'));
    $other = DriveTestHelpers::drive();
    $other->files()->upload(UploadSource::fromString('other drive', 'o.txt'));
    // A node the step purge never reaches through the root (a broken tree: its parent is in another drive); the
    // last transaction still detaches its revisions before the drive row cascades them away.
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($stray->id)->forceFill(['parent_id' => $other->root()->id])->save());
    $id = $drive->model()->getKey();

    $drive->delete();
    while (!app(PurgeDriveStepAction::class)->execute($id)) {
        // batches until done
    }

    expect(RevisionBlobPins::pins(linksBlob('in the tree')))->toBe(0)
        ->and(RevisionBlobPins::pins(linksBlob('stray')))->toBe(0)
        ->and(RevisionBlobPins::edges(linksBlob('stray')))->toBe(0)
        ->and(RevisionBlobPins::pins(linksBlob('other drive')))->toBe(1)
        ->and(RevisionBlobPins::all())->toBe(1);
});

it('refuses to link a blob whose bytes are gone and writes no edge', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromString('gone', 'a.txt'));
    [$revisionId] = linksRevisionIds($file->id);
    $revision = FileRevision::query()->findOrFail($revisionId);
    $blob = linksBlob('gone');
    app(VaulterDatabase::class)->write(fn () => $blob->forceFill(['status' => BlobStatus::Deleted])->save());

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => app(RevisionBlobLinks::class)->attach($revision, $blob, ['role' => 'chunk', 'ordinal' => 1])))
        ->toThrow(BlobUnavailableException::class)
        ->and(RevisionBlobPins::edges($blob))->toBe(1)
        ->and(RevisionBlobPins::keys($blob))->toBe([RevisionBlobLinks::referenceKey($revisionId)]);
});

it('detaches one edge with its pin, and all edges of the given revisions only', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->files()->upload(UploadSource::fromString('shared', 'a.txt'));
    $b = $drive->files()->upload(UploadSource::fromString('shared', 'b.txt'));
    [$revA] = linksRevisionIds($a->id);
    [$revB] = linksRevisionIds($b->id);
    $blob = linksBlob('shared');
    $links = app(RevisionBlobLinks::class);
    $revision = FileRevision::query()->findOrFail($revA);
    app(VaulterDatabase::class)->transaction(fn () => $links->attach($revision, $blob, ['role' => 'chunk', 'ordinal' => 2, 'offset_bytes' => 0, 'length_bytes' => 6]));
    expect(RevisionBlobPins::pins($blob))->toBe(3);

    app(VaulterDatabase::class)->transaction(fn () => $links->detach($revision, $blob->getKey(), 'chunk', 2));
    expect(RevisionBlobPins::keys($blob))->toBe(collect([$revA, $revB])->map(fn ($id): string => RevisionBlobLinks::referenceKey($id))->sort()->values()->all());

    $removed = app(VaulterDatabase::class)->transaction(fn (): int => $links->detachAll([$revA]));
    expect($removed)->toBe(1)
        ->and(RevisionBlobPins::keys($blob))->toBe([RevisionBlobLinks::referenceKey($revB)])
        ->and(RevisionBlobPins::edges($blob))->toBe(1)
        ->and(app(VaulterDatabase::class)->transaction(fn (): int => $links->detachAll([])))->toBe(0);
});

it('re-commits the staged bytes when the pin finds the blob swept between the commit and the pin', function (): void {
    $real = app(BlobStore::class);
    $calls = new stdClass;
    $calls->count = 0;
    // Stands in for a sweep that won between the two steps: the first commit returns a blob whose bytes are gone.
    app()->instance(BlobStore::class, new class($real, $calls) implements BlobStore
    {
        public function __construct(private BlobStore $inner, private stdClass $calls) {}

        public function commit(mixed $stream, BlobScope $scope, ?string $disk = null): BlobSnapshot
        {
            return $this->inner->commit($stream, $scope, $disk);
        }

        public function commitStaged(StagedObject $staged, BlobScope $scope, ?string $disk = null): BlobSnapshot
        {
            $blob = $this->inner->commitStaged($staged, $scope, $disk);
            if (++$this->calls->count === 1) {
                app(ContentStore::class)->delete($blob->storageKey, $blob->disk);
                Blob::query()->findOrFail($blob->id)->forceFill(['status' => BlobStatus::Deleted])->save();
            }

            return $blob;
        }

        public function read(BlobSnapshot|int|string $blob): mixed
        {
            return $this->inner->read($blob);
        }
    });

    $file = DriveTestHelpers::drive()->files()->upload(UploadSource::fromString('swept in between', 'a.txt'));
    $blob = linksBlob('swept in between');

    expect($calls->count)->toBe(2)
        ->and($blob->status)->toBe(BlobStatus::Committed)
        ->and(app(ContentStore::class)->exists($blob->storage_key, $blob->disk))->toBeTrue()
        ->and(RevisionBlobPins::keys($blob))->toBe([RevisionBlobLinks::referenceKey(linksRevisionIds($file->id)[0])]);
});
