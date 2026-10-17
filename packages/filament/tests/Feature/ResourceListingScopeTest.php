<?php

declare(strict_types=1);

use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Support\PanelNodes;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Filament\Tests\Fixtures\MutableTenantResolver;

/*
 * F-P1.10-01 (Major) — the four previously-unscoped Filament resources
 * (Storage/NodeGrant/NodeShare/Activity) now narrow getEloquentQuery() to the
 * acting admin's grant-reachable candidate set (the drive handle's own
 * visibility), mirroring NodeResource. Each pair below is red-before-fix (D7): on
 * the pre-fix code every "stranger sees nothing" assertion FAILS (the repro
 * `plans/AUDIT-VLT/artifacts/repro/P1.10/FilamentSurfaceReproTest.php` demonstrates
 * exactly this — a zero-grant actor saw every row of every owner).
 */

it('DriveResource lists a storage the acting admin can reach and hides one it cannot (F-P1.10-01)', function (): void {
    $owner = DriveBuilder::user(1);
    $reachable = DriveBuilder::storage();
    $unreachable = DriveBuilder::storage();

    $this->actingAs($owner);
    DriveBuilder::grant($reachable->rootNode()->first(), $owner, role: RoleRegistry::OWNER);

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
    $ownGrant = DriveBuilder::grant($reachable, $owner, role: RoleRegistry::OWNER);

    // A grant that belongs to a DIFFERENT subject on a node the acting owner
    // cannot reach at all.
    $stranger = DriveBuilder::user(2);
    $foreignGrant = DriveBuilder::grant($unreachable, $stranger, role: RoleRegistry::OWNER);

    $ids = NodeGrantResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($ownGrant->getKey())
        ->and($ids)->not->toContain($foreignGrant->getKey());

    // A zero-grant stranger sees NEITHER grant row.
    $this->actingAs(DriveBuilder::user(99));

    expect(NodeGrantResource::getEloquentQuery()->pluck('id')->all())->toBe([]);
});

it('NodeShareResource lists a share on a reachable node and hides one on an unreachable node (F-P1.10-01)', function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $reachable = DriveBuilder::folder($storage, 'ShareHost');
    DriveBuilder::grant($reachable, $owner, role: RoleRegistry::OWNER);

    NodeShareResource::createFromData([
        'node_id' => $reachable->getKey(),
        'level' => RoleRegistry::READER,
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
    DriveBuilder::grant($reachable, $owner, role: RoleRegistry::OWNER);

    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $owner, role: RoleRegistry::READER, scope: GrantScope::Item);

    $targetIds = ActivityResource::getEloquentQuery()->pluck('target_id')->all();

    expect($targetIds)->toContain($reachable->getKey());

    // A zero-grant stranger sees NO activity at all.
    $this->actingAs(DriveBuilder::user(99));

    expect(ActivityResource::getEloquentQuery()->count())->toBe(0);
});

it('isolates database resources from a sibling profile with an open candidate lane', function (string $mode): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $owner = DriveBuilder::user(1);
    $stranger = DriveBuilder::user(2);
    $visible = DriveBuilder::storage();
    $hidden = DriveBuilder::storage();
    $open = DriveBuilder::storage();
    $hiddenNode = DriveBuilder::folder($hidden, 'hidden-mixed');
    $openNode = DriveBuilder::folder($open, 'open-mixed');
    DriveBuilder::grant($visible->rootNode()->first(), $owner);
    $hiddenGrant = DriveBuilder::grant($hiddenNode, $stranger);
    $this->actingAs($stranger);
    NodeShareResource::createFromData(['node_id' => $hiddenNode->id, 'level' => 'reader', 'permissions' => 1]);
    $this->actingAs($owner);
    $open->profile = 'open';
    $open->save();
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default'));
    $profile = (new Profile)->key('open');
    $mode === 'gate' ? $profile->permissionDriver('gate') : $profile->grants(false);
    $registry->register($profile);
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    expect(DriveResource::getEloquentQuery()->pluck('id')->all())->toContain($visible->id)->not->toContain($hidden->id, $open->id)
        ->and(NodeGrantResource::getEloquentQuery()->pluck('id')->all())->not->toContain($hiddenGrant->id)
        ->and(NodeShareResource::getEloquentQuery()->pluck('node_id')->all())->not->toContain($hiddenNode->id)
        ->and(ActivityResource::getEloquentQuery()->pluck('target_id')->all())->not->toContain($hiddenNode->id);
    $rowIds = array_map(static fn (array $row): string => $row['id'], PanelNodes::rows());
    // The sibling profile's open candidate lane no longer lists its drive: the handle
    // (not the candidate superset) decides, and it denies the root View there.
    expect($rowIds)->toContain((string) $visible->root_node_id)->not->toContain((string) $hiddenNode->id, (string) $openNode->id);
})->with(['gate', 'disabled']);

it('V31 Filament node grant share and activity listings stay in the current tenant', function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    $actor = DriveBuilder::user(1);
    $this->actingAs($actor);
    $a = Vaulter::drive(DriveBuilder::storage())->asSystem('fixture');
    $nodeA = DriveBuilder::folder($a->model(), 'Tenant A');
    $grantA = DriveBuilder::grant($nodeA, $actor, role: RoleRegistry::OWNER);
    $shareA = $a->shares()->create($nodeA->getKey(), new ShareLinkInput);
    $driveAId = $a->model()->id;
    $resolver->tenant = 'B';
    $b = Vaulter::drive(DriveBuilder::storage())->asSystem('fixture');
    $nodeB = DriveBuilder::folder($b->model(), 'Tenant B');
    DriveBuilder::grant($nodeB, $actor, role: RoleRegistry::OWNER);
    DriveBuilder::grant(Node::query()->findOrFail($b->root()->id), $actor, role: RoleRegistry::READER, scope: GrantScope::Item);
    $shareB = $b->shares()->create($nodeB->getKey(), new ShareLinkInput);
    $rowIds = array_map(static fn (array $row): string => $row['id'], PanelNodes::rows());
    expect($rowIds)->toContain((string) $nodeB->id)->not->toContain((string) $nodeA->id)
        ->and(NodeGrantResource::getEloquentQuery()->pluck('id')->all())->not->toContain($grantA->id)
        ->and(array_map('strval', NodeShareResource::getEloquentQuery()->pluck('id')->all()))->toContain($shareB->link->id)->not->toContain($shareA->link->id)
        ->and(ActivityResource::getEloquentQuery()->pluck('drive_id')->all())->toContain($b->model()->id)->not->toContain($driveAId);
});
