<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Exceptions\NodeNameConflictException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('копия файла переиспользует ОДИН блоб через новое ребро', function (): void {
    $drive = DriveTestHelpers::drive();

    $original = $drive->upload(DriveTestHelpers::streamFor('shared content'), originalName: 'a.txt');

    $copy = $drive->copy($original, name: 'a-copy.txt');

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

    $root = $drive->createFolder('Project');
    $sub = $drive->createFolder('Sub', $root);
    $drive->createFolder('Leaf', $sub);
    $drive->upload(DriveTestHelpers::streamFor('file bytes'), into: $sub, originalName: 'data.bin');

    $copy = $drive->copy($root, name: 'Project Copy');

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

    $a = $drive->createFolder('A');
    $drive->createFolder('B');

    expect(fn () => $drive->copy($a, name: 'B'))
        ->toThrow(NodeNameConflictException::class);
});
