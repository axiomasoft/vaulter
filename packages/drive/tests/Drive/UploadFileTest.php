<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Events\Files\FileUploaded;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Fixtures\RevisionBlobPins;
use Vaulter\Media\Models\Cas\Blob;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('uploads a file: a kind=file node, File, FileRevision and an edge', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('hello drive'), 'hello.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    expect($node->kind)->toBe(NodeKind::File)
        ->and($node->name)->toBe('hello.txt')
        ->and($node->extension)->toBe('txt')
        ->and($node->mimeType)->toBe('text/plain')
        ->and($node->sizeBytes)->toBe(11)
        ->and(DriveTestHelpers::model($node)->file()->value('id'))->not->toBeNull()
        ->and(DriveTestHelpers::model($node)->file()->value('current_revision_id'))->not->toBeNull()
        ->and(FileRevision::query()->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1);
});

it('dedup: two identical files yield one blob, two nodes and two edges', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('same bytes'), 'a.bin'));
    $b = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('same bytes'), 'b.bin'));

    expect(Blob::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(2);

    $blob = Blob::query()->first();

    expect(RevisionBlobPins::edges($blob))->toBe(2)->and(RevisionBlobPins::pins($blob))->toBe(2)
        ->and($a->id)->not->toEqual($b->id);
});

it('increments used_bytes inside the upload transaction', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('1234567890'), 'x.bin'));

    expect($drive->model()->refresh()->used_bytes)->toBe(10);
});

it('quota: an upload past the quota is rejected BEFORE the blob is committed', function (): void {
    $drive = DriveTestHelpers::drive();
    app(VaulterDatabase::class)->write(fn () => $drive->model()->forceFill(['quota_bytes' => 5])->save());

    expect(fn (): NodeSnapshot => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('too many bytes'), 'x.bin')))
        ->toThrow(QuotaExceededException::class);

    expect(Blob::query()->count())->toBe(0)
        ->and(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(0)
        ->and($drive->model()->refresh()->used_bytes)->toBe(0);
});

it('new version: bumps number, repoints head and supersedes the previous one', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'));
    $firstVersionId = DriveTestHelpers::model($node)->file()->value('current_revision_id');

    $updated = $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('version two longer'), 'doc.txt'));
    $updated = DriveTestHelpers::model($node);

    $file = $updated->file;

    expect($file->revision_count)->toBe(2)
        ->and($updated->file()->value('current_revision_id'))->not->toEqual($firstVersionId)
        ->and(FileRevision::query()->find($firstVersionId)->status)->toBe('superseded')
        ->and($file->currentRevision->number)->toBe(2);
});

it('a new version adds its full size to used_bytes', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aaaa'), 'doc.txt')); // 4 bytes
    expect($drive->model()->refresh()->used_bytes)->toBe(4);

    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('aaaaaaaaaa'), 'doc.txt')); // 10 bytes

    // Every retained revision counts: 4 + 10.
    expect($drive->model()->refresh()->used_bytes)->toBe(14);
});

it('dispatches FileUploaded for a new file and NodeVersionCommitted for a version', function (): void {
    Event::fake([FileUploaded::class, FileRevisionAdded::class]);

    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'));

    Event::assertDispatched(FileUploaded::class);

    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('v2 longer'), 'doc.txt'));

    Event::assertDispatched(FileRevisionAdded::class);
});
