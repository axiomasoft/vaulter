<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Fixtures\RevisionBlobPins;
use Vaulter\Media\Models\Cas\Blob;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('a file copy reuses ONE blob through a new edge', function (): void {
    $drive = DriveTestHelpers::drive();

    $original = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('shared content'), 'a.txt'));

    $copy = $drive->nodes()->copy($original, options: new CopyOptions(name: 'a-copy.txt'));

    expect($copy->kind)->toBe(NodeKind::File)
        ->and($copy->name)->toBe('a-copy.txt')
        ->and($copy->id)->not->toEqual($original->id)
        ->and($copy->file)->not->toBeNull()
        ->and($copy->file->sizeBytes)->toBe(strlen('shared content'))
        ->and($copy->file->sha256)->toBe(hash('sha256', 'shared content'))
        // One physical blob, two File handles, two versions referencing it.
        ->and(Blob::query()->count())->toBe(1)
        ->and(File::query()->count())->toBe(2);

    $blob = Blob::query()->first();
    expect(RevisionBlobPins::edges($blob))->toBe(2)->and(RevisionBlobPins::pins($blob))->toBe(2);
});

it('a deep folder copy clones the whole subtree', function (): void {
    $drive = DriveTestHelpers::drive();

    $root = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $root);
    $drive->folders()->create('Leaf', $sub);
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('file bytes'), 'data.bin'), new UploadOptions(parent: $sub));

    $copy = $drive->nodes()->copy($root, options: new CopyOptions(name: 'Project Copy'));

    // 4 source nodes + 4 copied = 8 (Project, Sub, Leaf, data.bin) ×2.
    expect(Node::query()->where('kind', '!=', NodeKind::Folder->value)->count())->toBe(2) // two file nodes
        ->and(Node::query()->count())->toBeGreaterThanOrEqual(9); // + storage root

    $copiedSub = DriveTestHelpers::model($copy)->children()->where('name', 'Sub')->first();
    expect($copiedSub)->not->toBeNull()
        ->and($copiedSub->children()->count())->toBe(2); // Leaf + data.bin

    // Folder deep-copy of a file still shares the single blob.
    expect(Blob::query()->count())->toBe(1);
});

it('copies every descendant when its id is a suffix of an ancestor id', function (): void {
    $drive = DriveTestHelpers::drive();
    $parentId = $drive->model()->root_node_id;
    // Leave room for the copy's generated IDs on PostgreSQL, where explicit inserts do not advance the sequence.
    $suffixId = (int) $parentId + 100;
    $sourceId = (int) ('2'.$suffixId);
    foreach ([[$sourceId, 'Source'], [$suffixId + 1, 'Middle'], [$suffixId, 'Suffix'], [$suffixId + 2, 'Leaf']] as [$id, $name]) {
        $node = new Node;
        $node->forceFill([
            'id' => $id,
            'drive_id' => $drive->model()->getKey(),
            'parent_id' => $parentId,
            'kind' => NodeKind::Folder,
            'name' => $name,
        ])->save();
        $parentId = $id;
    }

    $copy = $drive->nodes()->copy($sourceId, options: new CopyOptions(name: 'Copied'));

    $middle = DriveTestHelpers::model($copy)->children()->where('name', 'Middle')->sole();
    $suffix = $middle->children()->where('name', 'Suffix')->sole();
    expect($suffix->children()->pluck('name')->all())->toBe(['Leaf']);
});

it('copy rejects a name conflict at the destination', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $drive->folders()->create('B');

    expect(fn (): NodeSnapshot => $drive->nodes()->copy($a, options: new CopyOptions(name: 'B', onConflict: OnNameConflict::Fail)))
        ->toThrow(NodeNameConflictException::class);
});
