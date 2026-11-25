<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Drive\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * Vaulter::drive($entity) get-or-creates the storage idempotently and provisions a
 * root folder node. The polymorphic owner triple is the idempotency key.
 */
it('creates the storage and its root folder on first access', function (): void {
    $host = DriveTestHelpers::host();

    $drive = Vaulter::drive($host)->asSystem('test fixture');

    expect(Drive::query()->count())->toBe(1)
        ->and($drive->model()->owner_type)->toBe($host->getMorphClass())
        ->and($drive->model()->owner_id)->toEqual($host->getKey())
        ->and($drive->model()->root_node_id)->not->toBeNull();

    $root = $drive->root();

    expect($root)->toBeInstanceOf(NodeSnapshot::class)
        ->and($root->kind)->toBe(NodeKind::Folder)
        ->and($root->parentId)->toBeNull()
        ->and($root->id)->toEqual($drive->model()->root_node_id);
});

it('is idempotent: a second access returns the same storage', function (): void {
    $host = DriveTestHelpers::host();

    $first = Vaulter::drive($host)->model();
    $second = Vaulter::drive($host)->model();

    expect($second->getKey())->toEqual($first->getKey())
        ->and(Drive::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', NodeKind::Folder->value)->count())->toBe(1);
});

// Keeping storages apart per panel moved to StoragePanelSeparationTest: the panel must be
// in the config BEFORE boot (ProfileRegistry is resolved in packageBooted), see PanelTestCase.

it('applies the default disk from config to a new storage', function (): void {
    config()->set('vaulter-media.disk', 'spaces');

    $storage = DriveTestHelpers::drive()->model();

    expect($storage->disk)->toBe('spaces');
});

it('dispatches StorageCreated with the root on provisioning', function (): void {
    Event::fake([DriveCreated::class]);

    $drive = DriveTestHelpers::drive();

    Event::assertDispatched(DriveCreated::class, fn (DriveCreated $event): bool => $event->storage->id === (string) $drive->model()->getKey()
        && $event->root->kind === NodeKind::Folder);
});

it('returns the registered profile object through the core handle contract', function (): void {
    $drive = DriveTestHelpers::drive();

    expect($drive->profile())->toBe(app(ProfileRegistry::class)->get($drive->model()->profile));
});

it('rejects an unknown profile before owner provisioning', function (): void {
    expect(fn () => Vaulter::drive(DriveTestHelpers::host(), 'missing-profile'))->toThrow(UnknownProfileException::class)
        ->and(Drive::query()->count())->toBe(0);
});

it('rejects an unsaved drive before opening a handle', function (): void {
    $storage = (new Drive)->forceFill(['profile' => 'missing-profile']);

    expect(fn () => Vaulter::drive($storage))->toThrow(InvalidArgumentException::class, 'persisted drive');
});
