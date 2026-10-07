<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Models\Cas\Blob;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
});

it('copies node and current version attributes and charges the exact logical size', function (): void {
    $this->travelTo(now()->startOfSecond());
    $drive = DriveTestHelpers::drive();
    $dest = $drive->folders()->create('Destination');
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('first'), 'source.txt'), new UploadOptions(declaredMimeType: 'text/plain'));
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->files()->addRevision($source, UploadSource::fromStream(DriveTestHelpers::streamFor('second bytes'), $source->name));
    $source->refresh()->forceFill(['is_pinned' => true, 'inherit_grants' => false])->save();
    $head = $source->currentVersion()->firstOrFail();
    $used = $drive->model()->refresh()->used_bytes;

    AccessTestHelpers::grant($source, GrantLevel::Owner, subjectId: 17);
    $copy = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->copy($source, $dest)->refresh();
    $file = $copy->file()->firstOrFail();
    $version = $copy->currentVersion()->firstOrFail();

    expect($copy->parent_id)->toEqual($dest->getKey())
        ->and($copy->name)->toBe('source.txt')
        ->and($copy->mime_type)->toBe('text/plain')
        ->and($copy->extension)->toBe($source->extension)
        ->and($copy->size_bytes)->toBe(12)
        ->and($copy->is_pinned)->toBeTrue()
        ->and($copy->inherit_grants)->toBeFalse()
        ->and($copy->owner_id)->toEqual(17)
        ->and($copy->created_by)->toEqual(17)
        ->and($file->node_id)->toEqual($copy->getKey())
        ->and($file->size_bytes)->toBe(12)
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->revision_count)->toBe(1)
        ->and($file->current_revision_id)->toEqual($version->getKey())
        ->and($version->number)->toBe(1)
        ->and($version->blob_id)->toEqual($head->blob_id)
        ->and($version->sha256)->toBe($head->sha256)
        ->and($version->size_bytes)->toBe(12)
        ->and($version->mime_type)->toBe($head->mime_type)
        ->and($version->original_name)->toBe($head->original_name)
        ->and($version->created_by)->toEqual(17)
        ->and($version->committed_at->equalTo(now()))->toBeTrue()
        ->and($drive->model()->refresh()->used_bytes)->toBe($used + 12)
        ->and(Blob::query()->count())->toBe(2);

    $sourceEdge = $head->blobs()->sole()->pivot;
    $copyEdge = $version->blobs()->sole()->pivot;
    foreach (['role', 'ordinal', 'offset_bytes', 'length_bytes'] as $attribute) {
        expect($copyEdge->{$attribute})->toBe($sourceEdge->{$attribute});
    }
});

it('charges only live copied descendants and excludes trashed children', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Folder');
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('abc'), 'live.bin'), new UploadOptions(parent: $folder));
    $trashed = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('old bytes'), 'trashed.bin'), new UploadOptions(parent: $folder));
    $drive->nodes()->trash($trashed);
    $used = $drive->model()->refresh()->used_bytes;

    $copy = $drive->nodes()->copy($folder, destination: null, options: new CopyOptions(name: 'Copy'));

    expect($copy->children()->pluck('name')->all())->toBe(['live.bin'])
        ->and($drive->model()->refresh()->used_bytes)->toBe($used + 3);
});
