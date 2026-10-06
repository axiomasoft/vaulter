<?php

declare(strict_types=1);

use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

/*
 * F-P1.10-01 (Major) — the four previously-unscoped Filament resources
 * (Storage/NodeGrant/NodeShare/Activity) now narrow getEloquentQuery() to the
 * acting admin's grant-reachable candidate set (PanelAccessScope), mirroring
 * NodeResource's existing pattern. Each pair below is red-before-fix (D7): on
 * the pre-fix code every "stranger sees nothing" assertion FAILS (the repro
 * `plans/AUDIT-VLT/artifacts/repro/P1.10/FilamentSurfaceReproTest.php` demonstrates
 * exactly this — a zero-grant actor saw every row of every owner).
 */

it('DriveResource lists a storage the acting admin can reach and hides one it cannot (F-P1.10-01)', function (): void {
    $owner = DriveBuilder::user(1);
    $reachable = DriveBuilder::storage();
    $unreachable = DriveBuilder::storage();

    $this->actingAs($owner);
    DriveBuilder::grant($reachable->rootNode()->first(), $owner, level: GrantLevel::Owner);

    $ids = DriveResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($reachable->getKey())
        ->and($ids)->not->toContain($unreachable->getKey());

    // A stranger with ZERO grants anywhere sees NEITHER storage.
    $stranger = DriveBuilder::user(99);
    $this->actingAs($stranger);

    $strangerIds = DriveResource::getEloquentQuery()->pluck('id')->all();

    expect($strangerIds)->toBe([]);
});

it('NodeGrantResource lists a grant on a reachable node and hides one on an unreachable node (F-P1.10-01)', function (): void {
    $owner = DriveBuilder::user(1);
    $storage = DriveBuilder::storage();
    $reachable = DriveBuilder::folder($storage, 'Owned');
    $unreachable = DriveBuilder::folder($storage, 'NotOwned');

    $this->actingAs($owner);
    $ownGrant = DriveBuilder::grant($reachable, $owner, level: GrantLevel::Owner);

    // A grant that belongs to a DIFFERENT subject on a node the acting owner
    // cannot reach at all.
    $stranger = DriveBuilder::user(2);
    $foreignGrant = DriveBuilder::grant($unreachable, $stranger, level: GrantLevel::Owner);

    $ids = NodeGrantResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($ownGrant->getKey())
        ->and($ids)->not->toContain($foreignGrant->getKey());

    // A zero-grant stranger sees NEITHER grant row.
    $this->actingAs(DriveBuilder::user(99));

    expect(NodeGrantResource::getEloquentQuery()->pluck('id')->all())->toBe([]);
});

it('NodeShareResource lists a share on a reachable node and hides one on an unreachable node (F-P1.10-01)', function (): void {
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $reachable = DriveBuilder::folder($storage, 'ShareHost');
    DriveBuilder::grant($reachable, $owner, level: GrantLevel::Owner);

    NodeShareResource::createFromData([
        'node_id' => $reachable->getKey(),
        'level' => GrantLevel::Reader->value,
        'permissions' => 1,
    ]);

    // A stranger with zero grants anywhere sees NO share row.
    $this->actingAs(DriveBuilder::user(99));

    expect(NodeShareResource::getEloquentQuery()->get())->toBeEmpty();

    // The owning admin still sees their own share.
    $this->actingAs($owner);

    $rows = NodeShareResource::getEloquentQuery()->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->first()->node_id)->toBe($reachable->getKey());
});

it('ActivityResource lists activity targeting a reachable node and hides a foreign owner\'s activity (F-P1.10-01)', function (): void {
    $owner = DriveBuilder::user(1);
    $storage = DriveBuilder::storage();
    $reachable = DriveBuilder::folder($storage, 'Loud');

    $foreignStorage = DriveBuilder::storage();
    DriveBuilder::folder($foreignStorage, 'AlsoLoud'); // writes a foreign activity row

    $this->actingAs($owner);
    DriveBuilder::grant($reachable, $owner, level: GrantLevel::Owner);

    $targetIds = ActivityResource::getEloquentQuery()->pluck('target_id')->all();

    expect($targetIds)->toContain($reachable->getKey());

    // A zero-grant stranger sees NO activity at all.
    $this->actingAs(DriveBuilder::user(99));

    expect(ActivityResource::getEloquentQuery()->count())->toBe(0);
});
