<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * Vaulter::for($entity) get-or-creates the storage idempotently and provisions a
 * root folder node (§2.1). The polymorphic owner triple is the idempotency key.
 */
it('создаёт storage и корневую папку при первом обращении', function (): void {
    $host = DriveTestHelpers::host();

    $drive = Vaulter::for($host);

    expect(Drive::query()->count())->toBe(1)
        ->and($drive->model()->owner_type)->toBe($host->getMorphClass())
        ->and($drive->model()->owner_id)->toEqual($host->getKey())
        ->and($drive->model()->root_node_id)->not->toBeNull();

    $root = $drive->root();

    expect($root)->toBeInstanceOf(Node::class)
        ->and($root->kind)->toBe(NodeKind::Folder)
        ->and($root->parent_id)->toBeNull()
        ->and($root->getKey())->toEqual($drive->model()->root_node_id);
});

it('идемпотентен: второе обращение возвращает тот же storage', function (): void {
    $host = DriveTestHelpers::host();

    $first = Vaulter::for($host)->model();
    $second = Vaulter::for($host)->model();

    expect($second->getKey())->toEqual($first->getKey())
        ->and(Drive::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', NodeKind::Folder->value)->count())->toBe(1);
});

// Разведение storage по панелям переехало в StoragePanelSeparationTest: панель обязана быть
// в конфиге ДО boot'а (ProfileRegistry резолвится в packageBooted с P4.4) — см. PanelTestCase.

it('применяет дефолтный диск из конфига к новому storage', function (): void {
    config()->set('vaulter.storage.default_disk', 'spaces');

    $storage = DriveTestHelpers::drive()->model();

    expect($storage->disk)->toBe('spaces');
});

it('диспатчит StorageCreated с корнем при провижене', function (): void {
    Event::fake([DriveCreated::class]);

    $drive = DriveTestHelpers::drive();

    Event::assertDispatched(DriveCreated::class, function (DriveCreated $event) use ($drive): bool {
        return $event->storage->getKey() === $drive->model()->getKey()
            && $event->root->kind === NodeKind::Folder;
    });
});

it('returns the registered profile object through the core handle contract', function (): void {
    $drive = DriveTestHelpers::drive();

    expect($drive->profile())->toBe(app(ProfileRegistry::class)->get($drive->model()->profile));
});

it('preserves owner provisioning before an unknown profile is requested', function (): void {
    $drive = Vaulter::for(DriveTestHelpers::host(), 'missing-profile');

    expect($drive->model()->profile)->toBe('missing-profile')
        ->and($drive->root()->getKey())->toEqual($drive->model()->root_node_id)
        ->and(Drive::query()->count())->toBe(1);

    expect(fn () => $drive->profile())->toThrow(UnknownProfileException::class);
});

it('opens an existing drive before an unknown profile is requested', function (): void {
    $storage = (new Drive)->forceFill(['profile' => 'missing-profile']);
    $drive = Vaulter::forStorage($storage);

    expect($drive->model())->toBe($storage);

    expect(fn () => $drive->profile())->toThrow(UnknownProfileException::class);
});
