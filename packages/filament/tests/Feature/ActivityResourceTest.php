<?php

declare(strict_types=1);

use Vaulter\Enums\Events\EventType;
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
        ->and($feed->contains(fn (Activity $a): bool => $a->verb === EventType::FolderCreated))->toBeTrue();
});

it('lists every verb the audit filter offers', function (): void {
    expect(EventType::cases())->not->toBeEmpty();

    foreach (EventType::cases() as $verb) {
        expect($verb->label())->toBeString()->not->toBe('');
    }
});
