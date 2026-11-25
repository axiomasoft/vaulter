<?php

declare(strict_types=1);

use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Configuration\SharingOptions;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Support\PanelActivity;
use Vaulter\Filament\Support\PanelDrives;
use Vaulter\Filament\Support\PanelGrants;
use Vaulter\Filament\Support\PanelNodes;
use Vaulter\Filament\Support\PanelShares;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Filament\Tests\Fixtures\MutableTenantResolver;

/*
 * The Storage/NodeGrant/NodeShare/Activity tables list only what the
 * acting admin's drive handles list: the drive entry point decides which drives are
 * openable, the handle's operations decide which grants, links and activity entries are
 * shown. Each pair below is red-before-fix: a zero-grant actor must see no row of any owner.
 */

/**
 * @param  list<array<string, mixed>> $rows
 * @return list<string>
 */
function panelIds(array $rows, string $column = 'id'): array
{
    return array_values(array_map(static fn (array $row): string => (string) $row[$column], $rows));
}

it('DriveResource lists a storage the acting admin can reach and hides one it cannot', function (): void {
    $owner = DriveBuilder::user(1);
    $reachable = DriveBuilder::storage();
    $unreachable = DriveBuilder::storage();

    $this->actingAs($owner);
    DriveBuilder::grant($reachable->rootNode()->first(), $owner, role: RoleRegistry::OWNER);

    $ids = PanelDrives::ids();

    expect($ids)->toContain((string) $reachable->getKey())
        ->and($ids)->not->toContain((string) $unreachable->getKey());

    // A stranger with ZERO grants anywhere sees NEITHER storage.
    $this->actingAs(DriveBuilder::user(99));

    expect(PanelDrives::ids())->toBe([]);
});

it('NodeGrantResource lists a grant on a reachable node and hides one on an unreachable node', function (): void {
    $owner = DriveBuilder::user(1);
    $storage = DriveBuilder::storage();
    $reachable = DriveBuilder::folder($storage, 'Owned');
    $unreachable = DriveBuilder::folder($storage, 'NotOwned');

    $this->actingAs($owner);
    DriveBuilder::openDrive($storage, $owner);
    $ownGrant = DriveBuilder::grant($reachable, $owner, role: RoleRegistry::OWNER);

    // A grant that belongs to a DIFFERENT subject on a node the acting owner
    // cannot manage at all.
    $stranger = DriveBuilder::user(2);
    $foreignGrant = DriveBuilder::grant($unreachable, $stranger, role: RoleRegistry::OWNER);

    $ids = panelIds(PanelGrants::rows());

    expect($ids)->toContain((string) $ownGrant->getKey())
        ->and($ids)->not->toContain((string) $foreignGrant->getKey());

    // A zero-grant stranger sees NEITHER grant row.
    $this->actingAs(DriveBuilder::user(99));

    expect(PanelGrants::rows())->toBe([]);
});

it('NodeShareResource lists a share on a reachable node and hides one on an unreachable node', function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $reachable = DriveBuilder::folder($storage, 'ShareHost');
    DriveBuilder::grant($reachable, $owner, role: RoleRegistry::OWNER);

    NodeShareResource::createFromData([
        'node_id' => $reachable->getKey(),
        'level' => RoleRegistry::READER,
        'permissions' => 1,
    ], app(SharingOptions::class));

    // A stranger with zero grants anywhere sees NO share row.
    $this->actingAs(DriveBuilder::user(99));

    expect(PanelShares::rows())->toBe([]);

    // The owning admin still sees their own share.
    $this->actingAs($owner);

    $rows = PanelShares::rows();

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['node_id'])->toBe((string) $reachable->getKey())
        ->and($rows[0]['status'])->toBe(PanelShares::LIVE)
        ->and($rows[0])->not->toHaveKey('token');
});

it('ActivityResource lists activity targeting a reachable node and hides a foreign owner\'s activity', function (): void {
    $owner = DriveBuilder::user(1);
    $storage = DriveBuilder::storage();
    $reachable = DriveBuilder::folder($storage, 'Loud');

    $foreignStorage = DriveBuilder::storage();
    $foreign = DriveBuilder::folder($foreignStorage, 'AlsoLoud'); // writes a foreign activity row

    $this->actingAs($owner);
    DriveBuilder::grant($reachable, $owner, role: RoleRegistry::OWNER);

    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $owner, role: RoleRegistry::READER, scope: GrantScope::Item);

    $targetIds = panelIds(PanelActivity::rows(), 'target_id');

    expect($targetIds)->toContain((string) $reachable->getKey())
        ->and($targetIds)->not->toContain((string) $foreign->getKey());

    // A zero-grant stranger sees NO activity at all.
    $this->actingAs(DriveBuilder::user(99));

    expect(PanelActivity::rows())->toBe([]);
});

it('isolates database resources from a sibling profile with an open candidate lane', function (string $mode): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    config()->set('vaulter-drive.features.share_links', true);
    $owner = DriveBuilder::user(1);
    $stranger = DriveBuilder::user(2);
    $visible = DriveBuilder::storage();
    $hidden = DriveBuilder::storage();
    $open = DriveBuilder::storage();
    $hiddenNode = DriveBuilder::folder($hidden, 'hidden-mixed');
    $openNode = DriveBuilder::folder($open, 'open-mixed');
    DriveBuilder::grant($visible->rootNode()->first(), $owner);
    $hiddenGrant = DriveBuilder::grant($hiddenNode, $stranger);
    DriveBuilder::openDrive($hidden, $stranger);
    $this->actingAs($stranger);
    NodeShareResource::createFromData(['node_id' => $hiddenNode->id, 'level' => 'reader', 'permissions' => 1], app(SharingOptions::class));
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
    expect(PanelDrives::ids())->toContain((string) $visible->id)->not->toContain((string) $hidden->id, (string) $open->id)
        ->and(panelIds(PanelGrants::rows()))->not->toContain((string) $hiddenGrant->id)
        ->and(panelIds(PanelShares::rows(), 'node_id'))->not->toContain((string) $hiddenNode->id)
        ->and(panelIds(PanelActivity::rows(), 'target_id'))->not->toContain((string) $hiddenNode->id);
    $rowIds = array_map(static fn (array $row): string => $row['id'], PanelNodes::rows());
    // The sibling profile's open candidate lane no longer lists its drive: the handle
    // (not the candidate superset) decides, and it denies the root View there.
    expect($rowIds)->toContain((string) $visible->root_node_id)->not->toContain((string) $hiddenNode->id, (string) $openNode->id);
})->with(['gate', 'disabled']);

it('Filament node grant share and activity listings stay in the current tenant', function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    $actor = DriveBuilder::user(1);
    $this->actingAs($actor);
    $a = Vaulter::drive(DriveBuilder::storage())->asSystem('fixture');
    $nodeA = DriveBuilder::folder($a->model(), 'Tenant A');
    DriveBuilder::openDrive($a->model(), $actor);
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
        ->and(panelIds(PanelDrives::rows()))->toBe([(string) $b->model()->id])
        ->and(panelIds(PanelGrants::rows()))->not->toContain((string) $grantA->id)
        ->and(panelIds(PanelShares::rows()))->toContain($shareB->link->id)->not->toContain($shareA->link->id)
        ->and(panelIds(PanelActivity::rows(), 'drive_id'))->toContain((string) $b->model()->id)->not->toContain((string) $driveAId);
});
