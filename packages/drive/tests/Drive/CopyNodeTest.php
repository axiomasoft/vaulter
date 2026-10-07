<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('копия файла переиспользует ОДИН блоб через новое ребро', function (): void {
    $drive = DriveTestHelpers::drive();

    $original = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('shared content'), 'a.txt'));

    $copy = $drive->nodes()->copy($original, destination: null, options: new CopyOptions(name: 'a-copy.txt'));

    expect($copy->kind)->toBe(NodeKind::File)
        ->and($copy->name)->toBe('a-copy.txt')
        ->and($copy->getKey())->not->toEqual($original->getKey())
        // One physical blob, two File handles, two versions referencing it.
        ->and(Blob::query()->count())->toBe(1)
        ->and(File::query()->count())->toBe(2);

    $blob = Blob::query()->first();
    expect($blob->versions()->count())->toBe(2);
});

it('глубокая копия папки клонирует всё поддерево', function (): void {
    $drive = DriveTestHelpers::drive();

    $root = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $root);
    $drive->folders()->create('Leaf', $sub);
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('file bytes'), 'data.bin'), new UploadOptions(parent: $sub));

    $copy = $drive->nodes()->copy($root, destination: null, options: new CopyOptions(name: 'Project Copy'));

    // 4 source nodes + 4 copied = 8 (Project, Sub, Leaf, data.bin) ×2.
    expect(Node::query()->where('kind', '!=', NodeKind::Folder->value)->count())->toBe(2) // two file nodes
        ->and(Node::query()->count())->toBeGreaterThanOrEqual(9); // + storage root

    $copiedSub = $copy->children()->where('name', 'Sub')->first();
    expect($copiedSub)->not->toBeNull()
        ->and($copiedSub->children()->count())->toBe(2); // Leaf + data.bin

    // Folder deep-copy of a file still shares the single blob.
    expect(Blob::query()->count())->toBe(1);
});

it('copy отклоняет конфликт имён в месте назначения', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $drive->folders()->create('B');

    expect(fn () => $drive->nodes()->copy($a, destination: null, options: new CopyOptions(name: 'B', onConflict: OnNameConflict::Fail)))
        ->toThrow(NodeNameConflictException::class);
});
