<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Node;
use Vaulter\Filament\Support\PanelNodes;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

/*
 * The node table is fed by the authorized handle's listings (PanelNodes), never by an
 * Eloquent query over the node table. These pin what the rows contain under different
 * actors; the table itself is rendered in NodeResourceExactVisibilityTest.
 */

/** @return list<string> */
function panelNodeIds(bool $trashed = false): array
{
    $rows = array_filter(PanelNodes::rows(), static fn (array $row): bool => ($row['trashed_at'] !== null) === $trashed);

    return array_values(array_map(static fn (array $row): string => $row['id'], $rows));
}

it('lists no node when no admin is acting', function (): void {
    $storage = DriveBuilder::storage();
    DriveBuilder::folder($storage, 'Hidden');

    expect(PanelNodes::rows())->toBe([]);
});

it('lists the nodes of a drive whose root the admin can view and nothing of a drive it cannot', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $inside = DriveBuilder::folder($storage, 'Inside');
    $foreign = DriveBuilder::folder(DriveBuilder::storage(), 'Foreign');
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $admin, role: RoleRegistry::READER);

    expect(panelNodeIds())->toContain((string) $inside->getKey())->not->toContain((string) $foreign->getKey());

    $this->actingAs(DriveBuilder::user(99));

    expect(PanelNodes::rows())->toBe([]);
});

it('keeps live and trashed nodes apart within the listed rows', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $live = DriveBuilder::folder($storage, 'Live');
    $trashed = DriveBuilder::folder($storage, 'Trashed');
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $admin);

    Vaulter::drive($storage)->actingAs($admin)->nodes()->trash(node: $trashed->getKey());

    expect(panelNodeIds())->toContain((string) $live->getKey())->not->toContain((string) $trashed->getKey())
        ->and(panelNodeIds(trashed: true))->toContain((string) $trashed->getKey())->not->toContain((string) $live->getKey());
});

it('does not offer a folder or anything inside it as its own move destination', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $parent = DriveBuilder::folder($storage, 'Parent');
    $child = Vaulter::drive($storage)->asSystem('test fixture')->folders()->create(name: 'Child', parent: $parent->getKey());
    $sibling = DriveBuilder::folder($storage, 'Sibling');
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $admin);

    $destinations = array_map(strval(...), array_keys(PanelNodes::destinations((string) $storage->getKey(), (string) $parent->getKey())));

    expect($destinations)->toContain((string) $sibling->getKey())
        ->and($destinations)->not->toContain((string) $parent->getKey())
        ->and($destinations)->not->toContain($child->id);
});

it('keeps purged nodes out of the rows', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $live = DriveBuilder::folder($storage, 'Live');
    $deleted = DriveBuilder::folder($storage, 'Deleted');
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $admin);

    Vaulter::drive($storage)->actingAs($admin)->nodes()->trash(node: $deleted->getKey());
    Vaulter::drive($storage)->actingAs($admin)->trash()->purge(node: $deleted->getKey());

    expect(array_merge(panelNodeIds(), panelNodeIds(trashed: true)))->toContain((string) $live->getKey())->not->toContain((string) $deleted->getKey());
});

it('omits a node behind an inheritance boundary the admin cannot pass', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $parent = DriveBuilder::folder($storage, 'Parent');
    $child = Node::query()->findOrFail(Vaulter::drive($storage)->asSystem('test fixture')->folders()->create(name: 'Walled', parent: $parent->getKey())->id);
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $admin, role: RoleRegistry::READER);
    $child->forceFill(['inherit_grants' => false])->save();

    expect(panelNodeIds())->toContain((string) $parent->getKey())->not->toContain((string) $child->getKey());
});

it('carries the handle abilities on each row so actions follow them', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Folder');
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $admin, role: RoleRegistry::READER);
    DriveBuilder::grant($folder, $admin, role: RoleRegistry::WRITER, scope: GrantScope::Item);

    $row = DriveBuilder::row($folder);

    expect($row['abilities']['edit'])->toBeTrue()
        ->and($row['abilities']['delete'])->toBeFalse();
});
