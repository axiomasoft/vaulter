<?php

declare(strict_types=1);

/*
 * P1.10 repro — Filament panel surface (C1 authorization / C3 query scoping).
 *
 * Claim under test: only NodeResource scopes its listing to the acting admin.
 * StorageResource / NodeGrantResource / NodeShareResource / ActivityResource have
 * NO model policy, NO canViewAny override and NO getEloquentQuery override, so an
 * authenticated panel user with ZERO grants both (a) passes canViewAny and (b) sees
 * EVERY row of every owner/tenant.
 */

use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Resources\StorageResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Filament\Tests\TestCase;

uses(TestCase::class);

it('[C1] grants canViewAny to a stranger with zero grants on the four unscoped resources', function (): void {
    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    expect(StorageResource::canViewAny())->toBeTrue()
        ->and(NodeGrantResource::canViewAny())->toBeTrue()
        ->and(NodeShareResource::canViewAny())->toBeTrue()
        ->and(ActivityResource::canViewAny())->toBeTrue();
});

it('[C3] StorageResource lists a storage the acting admin has no grant on', function (): void {
    // A storage owned by host #1; the acting admin (#99) holds no grant anywhere.
    $storage = DriveBuilder::storage();

    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    $ids = StorageResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($storage->getKey());
});

it('[C3] NodeGrantResource lists another subject grant to a stranger', function (): void {
    // Owner #1 grants themselves on a folder; the grant row belongs to owner #1.
    $owner = DriveBuilder::user(1);
    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Owned');
    $grant = DriveBuilder::grant($folder, $owner, level: GrantLevel::Owner);

    // Stranger #99 has no grant, no Share ability on that node.
    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    $ids = NodeGrantResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($grant->getKey());
});

it('[C3] NodeShareResource lists a foreign share-link to a stranger', function (): void {
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'ShareHost');
    DriveBuilder::grant($folder, $owner, level: GrantLevel::Owner);

    // Mint a share as the owner via the resource's own path (authorized here).
    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'level' => GrantLevel::Reader->value,
        'permissions' => 1,
    ]);

    // Now a stranger with no grants sees the share row (node_id + token_prefix).
    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    $rows = NodeShareResource::getEloquentQuery()->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->first()->node_id)->toBe($folder->getKey());
});

it('[C3] ActivityResource lists foreign activity (ip/context) to a stranger', function (): void {
    // Building a storage + folder writes activity rows attributed to that operation.
    $storage = DriveBuilder::storage();
    DriveBuilder::folder($storage, 'Loud');

    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    $count = ActivityResource::getEloquentQuery()->count();

    expect($count)->toBeGreaterThan(0);
});

it('[CONTROL] NodeResource returns an empty listing for the same stranger (scoped)', function (): void {
    $storage = DriveBuilder::storage();
    DriveBuilder::folder($storage, 'Hidden');

    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    $ids = NodeResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([]);
});
