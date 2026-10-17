<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Repositories\Activity\ActivityReadRepository;
use Vaulter\Events\EventCatalog;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\ActivityResource\Pages\ListActivities;
use Vaulter\Filament\Support\PanelActivity;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

uses(RefreshDatabase::class);

it('declares the panel record class, not the drive activity model', function (): void {
    expect(ActivityResource::getModel())->toBe(PanelRecord::class)
        ->and(ActivityResource::getModelLabel())->toBe('activity')
        ->and(ActivityResource::getPluralModelLabel())->toBe('activities');
});

it('is strictly read-only (no create / edit / delete)', function (): void {
    $activity = new PanelRecord;

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
    $targets = static fn (): array => array_column(PanelActivity::rows(), 'target_id');

    expect($targets())->not->toContain((string) $node->id);
    $grant = DriveBuilder::grant($node, $actor, RoleRegistry::READER);
    expect($targets())->toContain((string) $node->id);
    $grant->delete();
    expect($targets())->not->toContain((string) $node->id);
});

it('lists no activity when no admin is acting', function (): void {
    DriveBuilder::folder(DriveBuilder::storage(), 'Quiet');

    expect(PanelActivity::rows())->toBe([]);
});

it('carries the verb, its label and the projected fields on each row', function (): void {
    $actor = DriveBuilder::user();
    $this->actingAs($actor);
    $drive = DriveBuilder::storage();
    DriveBuilder::grant($drive->rootNode()->firstOrFail(), $actor);
    DriveBuilder::folder($drive, 'Loud');

    $row = collect(PanelActivity::rows())->first(static fn (array $row): bool => $row['verb'] === DriveEventType::FolderCreated->value);

    expect($row)->toBeArray()
        ->and($row['verb_label'])->toBe(DriveEventType::FolderCreated->label())
        ->and($row['drive_id'])->toBe((string) $drive->getKey())
        ->and($row['created_at'])->toBeInstanceOf(DateTimeInterface::class);
});

it('renders the activity table, filters by verb and opens the details modal', function (): void {
    app(DataStore::class)->register();
    $actor = DriveBuilder::user();
    $this->actingAs($actor);
    $drive = DriveBuilder::storage();
    DriveBuilder::grant($drive->rootNode()->firstOrFail(), $actor);
    DriveBuilder::folder($drive, 'Loud');
    $rows = PanelActivity::rows();
    $created = collect($rows)->first(static fn (array $row): bool => $row['verb'] === DriveEventType::FolderCreated->value);

    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$created['id']])
        ->filterTable('verb', DriveEventType::FolderCreated->value)
        ->assertCanSeeTableRecords([$created['id']])
        ->filterTable('verb', 'no.such.verb')
        ->assertCanNotSeeTableRecords([$created['id']]);

    Livewire::test(ListActivities::class)
        ->mountTableAction('view', $created['id'])
        ->assertTableActionDataSet(['verb' => DriveEventType::FolderCreated->label(), 'drive_id' => (string) $drive->getKey()]);
});
