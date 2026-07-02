<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Events\StorageCreated;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\Panels\TeamStoragePanel;

/**
 * Vaulter::for($entity) get-or-creates the storage idempotently and provisions a
 * root folder node (§2.1). The polymorphic owner triple is the idempotency key.
 */
it('создаёт storage и корневую папку при первом обращении', function (): void {
    $host = DriveTestHelpers::host();

    $drive = Vaulter::for($host);

    expect(Storage::query()->count())->toBe(1)
        ->and($drive->storage()->owner_type)->toBe($host->getMorphClass())
        ->and($drive->storage()->owner_id)->toEqual($host->getKey())
        ->and($drive->storage()->root_node_id)->not->toBeNull();

    $root = $drive->root();

    expect($root)->toBeInstanceOf(Node::class)
        ->and($root->kind)->toBe(NodeKind::Folder)
        ->and($root->parent_id)->toBeNull()
        ->and($root->getKey())->toEqual($drive->storage()->root_node_id);
});

it('идемпотентен: второе обращение возвращает тот же storage', function (): void {
    $host = DriveTestHelpers::host();

    $first = Vaulter::for($host)->storage();
    $second = Vaulter::for($host)->storage();

    expect($second->getKey())->toEqual($first->getKey())
        ->and(Storage::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', NodeKind::Folder->value)->count())->toBe(1);
});

it('разводит storage по разным панелям одного владельца', function (): void {
    config()->set('vaulter.panels', [TeamStoragePanel::class]);

    $host = DriveTestHelpers::host();

    $default = Vaulter::for($host, 'default')->storage();
    $team = Vaulter::for($host, 'team')->storage();

    expect($team->getKey())->not->toEqual($default->getKey())
        ->and($team->panel)->toBe('team')
        ->and($team->storage_type)->toBe('public')
        ->and(Storage::query()->count())->toBe(2);
});

it('применяет дефолтный диск из конфига к новому storage', function (): void {
    config()->set('vaulter.storage.default_disk', 'spaces');

    $storage = DriveTestHelpers::drive()->storage();

    expect($storage->disk)->toBe('spaces');
});

it('диспатчит StorageCreated с корнем при провижене', function (): void {
    Event::fake([StorageCreated::class]);

    $drive = DriveTestHelpers::drive();

    Event::assertDispatched(StorageCreated::class, function (StorageCreated $event) use ($drive): bool {
        return $event->storage->getKey() === $drive->storage()->getKey()
            && $event->root->kind === NodeKind::Folder;
    });
});
