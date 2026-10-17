<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\DriveResource\Pages\ListDrives;
use Vaulter\Filament\Support\PanelDrives;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

uses(RefreshDatabase::class);

it('declares the panel record class, not the drive model', function (): void {
    expect(DriveResource::getModel())->toBe(PanelRecord::class);
});

it('is read-mostly: drives are provisioned through the API, not the panel', function (): void {
    expect(DriveResource::canCreate())->toBeFalse();
});

it('lists the provisioned drives the admin can open, with their accounting', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $admin);
    DriveBuilder::storage(); // another drive the admin cannot open

    $rows = PanelDrives::rows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe((string) $storage->getKey())
        ->and($rows[0]['name'])->toBe($storage->name)
        ->and($rows[0]['profile'])->toBe($storage->profile)
        ->and($rows[0]['status'])->toBe(DriveStatus::Active)
        ->and($rows[0]['used_bytes'])->toBe(0)
        ->and($rows[0]['quota_bytes'])->toBeNull()
        ->and($rows[0]['owner_type'])->toBe($storage->owner_type);
});

it('lists no drive when no admin is acting', function (): void {
    DriveBuilder::storage();

    expect(PanelDrives::rows())->toBe([])
        ->and(PanelDrives::ids())->toBe([])
        ->and(PanelDrives::handles())->toBe([])
        ->and(PanelDrives::handle('1'))->toBeNull();
});

it('resolves a handle by key without judging visibility, so an operation answers for itself', function (): void {
    $this->actingAs(DriveBuilder::user());
    $storage = DriveBuilder::storage();

    $handle = PanelDrives::handle((string) $storage->getKey());

    expect($handle)->not->toBeNull()
        ->and($handle->snapshot()->id)->toBe((string) $storage->getKey())
        ->and(PanelDrives::handle('999999'))->toBeNull()
        ->and(PanelDrives::ids())->toBe([]);
});

it('renders the drive table from the listing and opens the details modal', function (): void {
    app(DataStore::class)->register();
    $admin = DriveBuilder::user();
    $this->actingAs($admin);
    $visible = DriveBuilder::storage();
    $hidden = DriveBuilder::storage();
    DriveBuilder::openDrive($visible, $admin);

    Livewire::test(ListDrives::class)
        ->assertCanSeeTableRecords([(string) $visible->getKey()])
        ->assertCanNotSeeTableRecords([(string) $hidden->getKey()])
        ->mountTableAction('view', (string) $visible->getKey())
        ->assertTableActionDataSet(['name' => $visible->name, 'profile' => $visible->profile, 'status' => 'active', 'quota' => '∞']);
});

it('searches the drive table by name', function (): void {
    app(DataStore::class)->register();
    $admin = DriveBuilder::user();
    $this->actingAs($admin);
    $a = DriveBuilder::storage();
    $b = DriveBuilder::storage();
    DriveBuilder::openDrive($a, $admin);
    DriveBuilder::openDrive($b, $admin);
    $a->forceFill(['name' => 'Alpha archive'])->save();
    $b->forceFill(['name' => 'Beta vault'])->save();

    Livewire::test(ListDrives::class)
        ->searchTable('alpha')
        ->assertCanSeeTableRecords([(string) $a->getKey()])
        ->assertCanNotSeeTableRecords([(string) $b->getKey()]);
});

it('uses drive labels and the drive route slug', function (): void {
    expect(DriveResource::getModelLabel())->toBe('drive')
        ->and(DriveResource::getPluralModelLabel())->toBe('drives')
        ->and(DriveResource::getSlug())->toBe('drives')
        ->and(DriveResource::canGloballySearch())->toBeFalse();
});
