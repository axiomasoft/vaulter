<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\EventCatalog;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Activity\Activity;
use Vaulter\Repositories\Activity\ActivityReadRepository;

it('resolves the configured activity model (swappable)', function (): void {
    expect(ActivityResource::getModel())->toBe(Activity::class);
});

it('is strictly read-only (no create / edit / delete)', function (): void {
    $activity = new Activity;

    expect(ActivityResource::canCreate())->toBeFalse()
        ->and(ActivityResource::canEdit($activity))->toBeFalse()
        ->and(ActivityResource::canDelete($activity))->toBeFalse();
});

it('surfaces the append-only feed recorded by the core Drive Actions', function (): void {
    $user = DriveBuilder::user();
    $this->actingAs($user);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Logged');

    // Creating the folder recorded a folder.created activity row.
    $feed = app(ActivityReadRepository::class)->forStorage($storage)->get();

    expect($feed)->not->toBeEmpty()
        ->and($feed->contains(fn (Activity $a): bool => $a->verb === DriveEventType::FolderCreated))->toBeTrue();
});

it('lists every verb the audit filter offers', function (): void {
    $verbs = app(EventCatalog::class)->all();
    expect($verbs)->not->toBeEmpty()
        ->and($verbs)->toContain(DriveEventType::FolderCreated);

    foreach ($verbs as $verb) {
        expect($verb->label())->toBeString()->not->toBe('');
    }
});

it('reads the panel activity through current root and target visibility', function (): void {
    $actor = DriveBuilder::user();
    $this->actingAs($actor);
    $drive = DriveBuilder::storage();
    $root = $drive->rootNode()->firstOrFail();
    DriveBuilder::grant($root, $actor, RoleRegistry::READER, scope: GrantScope::Item);
    $node = DriveBuilder::folder($drive, 'hidden');
    $hidden = Activity::query()->where('target_id', $node->id)->firstOrFail();
    expect(ActivityResource::getEloquentQuery()->whereKey($hidden->id)->exists())->toBeFalse();
    $grant = DriveBuilder::grant($node, $actor, RoleRegistry::READER);
    expect(ActivityResource::getEloquentQuery()->whereKey($hidden->id)->exists())->toBeTrue();
    $grant->delete();
    expect(ActivityResource::getEloquentQuery()->whereKey($hidden->id)->exists())->toBeFalse();
});
