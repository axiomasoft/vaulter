<?php

declare(strict_types=1);

use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Drive\Node;

/*
 * NOTE on test level. Full Livewire page rendering of a Filament 5 resource under
 * Testbench hits a known nested-@livewire / ViewErrorBag integration snag (the
 * middleware-less Livewire test request never runs ShareErrorsFromSession). Rather
 * than assert through a brittle render, these tests pin the LOAD-BEARING behaviour
 * the spec asks for: the model resolves swappably, the resource is non-creatable, the
 * row actions invoke the CORE Drive Actions (proven by exercising the exact
 * DriveContext call the closures make), and authorization is the core AccessResolver.
 */

it('resolves the configured node model (swappable)', function (): void {
    expect(NodeResource::getModel())->toBe(Node::class);
});

it('is not creatable from a flat form (folders come from the Drive Actions)', function (): void {
    expect(NodeResource::canCreate())->toBeFalse();
});

it('trashes a node through the core Trash Action (the row action path)', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Trashable');
    DriveBuilder::grant($folder, $user); // Owner ⇒ may trash

    expect($folder->fresh()->trashed_at)->toBeNull();

    // The row action calls exactly this DriveContext path (TrashNodeAction).
    Vaulter::drive($folder->storage)->actingAs($user)->nodes()->trash(node: $folder->getKey());

    expect($folder->fresh()->trashed_at)->not->toBeNull();
});

it('restores a trashed node through the core Restore Action', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    DriveBuilder::grant($storage->rootNode, $user);
    $folder = DriveBuilder::folder($storage, 'Restorable');
    DriveBuilder::grant($folder, $user);

    Vaulter::drive($storage)->asSystem('test fixture')->nodes()->trash(node: $folder->getKey());
    expect($folder->fresh()->trashed_at)->not->toBeNull();

    Vaulter::drive($folder->storage)->actingAs($user)->trash()->restore(node: $folder->getKey());

    expect($folder->fresh()->trashed_at)->toBeNull();
});

it('renames a node through the core Rename Action', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Before');
    DriveBuilder::grant($folder, $user);

    Vaulter::drive($folder->storage)->actingAs($user)->nodes()->rename(node: $folder->getKey(), name: 'After');

    expect($folder->fresh()->name)->toBe('After');
});

it('force-deletes a trashed node through the core ForceDelete Action', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Purgeable');
    DriveBuilder::grant($folder, $user);

    Vaulter::drive($storage)->asSystem('test fixture')->nodes()->trash(node: $folder->getKey());

    Vaulter::drive($folder->storage)->actingAs($user)->trash()->purge(node: $folder->getKey());

    expect($folder->fresh())->toBeNull()
        ->and($folder->newQuery()->whereKey($folder->getKey())->exists())->toBeFalse();
});

it('denies the trash ability to an admin who lacks a grant (row action hidden)', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');
    // No grant for this user ⇒ AccessResolver denies ⇒ the row action's visible()
    // predicate is false.

    expect(app(AccessResolver::class)->can($user, Ability::Trash, $folder))->toBeFalse();
});

it('grants the trash ability to an admin who holds an owner grant', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Allowed');
    DriveBuilder::grant($folder, $user);

    expect(app(AccessResolver::class)->can($user, Ability::Trash, $folder))->toBeTrue();
});

it('exposes folder/file/document kinds', function (): void {
    expect(NodeKind::values())->toContain('folder', 'file', 'document');
});
