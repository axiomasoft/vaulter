<?php

declare(strict_types=1);

use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

/*
 * Access-aware Filament table (master plan P1 #3). NodeResource::getEloquentQuery()
 * narrows the admin table to the acting admin's grant-reachable CANDIDATE set — the
 * SAME candidate-SQL the core API listing uses, so the two surfaces are CONSISTENT.
 * The narrowing is a deliberate SUPERSET (it hides nodes with no grant-reachability
 * but does NOT apply the exact deny-veto in the listing SQL — deferred to the H.4
 * read-model); per-ROW actions stay exactly gated by the AccessResolver.
 *
 * The full Livewire page render hits a known Filament-5/Testbench snag (see the note
 * in NodeResourceTest), so these pin the load-bearing query behaviour directly:
 * getEloquentQuery() under different actors, the Trashed-filter query closures still
 * flipping live/trashed WITHIN the accessible set, and the repository seam the query
 * delegates to.
 */

it('returns an empty table query when no admin is acting', function (): void {
    $storage = DriveBuilder::storage();
    DriveBuilder::folder($storage, 'Hidden');

    // No actingAs — a panel never lists nodes for nobody.
    $ids = NodeResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([]);
});

it('narrows the table to the acting admin candidate set', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $granted = DriveBuilder::folder($storage, 'Granted');
    $ungranted = DriveBuilder::folder($storage, 'Ungranted');

    // A subtree grant on 'Granted' makes it (and any descendant) a candidate; the
    // sibling 'Ungranted' has no grant and must NOT appear in the admin table.
    DriveBuilder::grant($granted, $admin, level: GrantLevel::Reader);

    $ids = NodeResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($granted->getKey())
        ->and($ids)->not->toContain($ungranted->getKey());
});

it('flips between live and trashed WITHIN the accessible set via the Trashed filter', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $live = DriveBuilder::folder($storage, 'Live');
    $trashed = DriveBuilder::folder($storage, 'Trashed');

    // Both are candidates (Owner subtree grants), so the access narrowing keeps both;
    // the Trashed filter then selects by trashed_at within that accessible set.
    DriveBuilder::grant($live, $admin);
    DriveBuilder::grant($trashed, $admin);

    Vaulter::forStorage($storage)->trash(node: $trashed, actorId: $admin->getKey());

    $base = NodeResource::getEloquentQuery();

    // The "Live only" branch (the filter's false/blank query) keeps the live node only.
    $liveIds = (clone $base)->whereNull('trashed_at')->pluck('id')->all();
    expect($liveIds)->toContain($live->getKey())
        ->and($liveIds)->not->toContain($trashed->getKey());

    // The "Trashed only" branch (the filter's true query) keeps the trashed node only —
    // proving the access narrowing did NOT pre-impose a live/trashed filter.
    $trashedIds = (clone NodeResource::getEloquentQuery())->whereNotNull('trashed_at')->pluck('id')->all();
    expect($trashedIds)->toContain($trashed->getKey())
        ->and($trashedIds)->not->toContain($live->getKey());
});

it('keeps force-deleted tombstones out of the resource table', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $live = DriveBuilder::folder($storage, 'Live');
    $deleted = DriveBuilder::folder($storage, 'Deleted');

    DriveBuilder::grant($live, $admin);
    DriveBuilder::grant($deleted, $admin);

    Vaulter::forStorage($storage)->trash(node: $deleted, actorId: $admin->getKey());
    Vaulter::forStorage($storage)->forceDelete(node: $deleted, actorId: $admin->getKey());

    $ids = NodeResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($live->getKey())
        ->and($ids)->not->toContain($deleted->getKey());
});

it('constrains a raw builder to the item anchor only via the repository seam', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'ItemOnly');
    $child = Vaulter::forStorage($storage)->createFolder(name: 'Inside', parent: $folder);

    // ITEM scope = that node only; the child is not a candidate.
    DriveBuilder::grant($folder, $admin, level: GrantLevel::Reader, scope: GrantScope::Item);

    $ids = app(NodeReadRepository::class)
        ->constrainToAccessible(Node::query(), $admin)
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$folder->getKey()])
        ->and($ids)->not->toContain($child->getKey());
});

it('lists a deny-vetoed candidate in the table query (superset), per the listing decision', function (): void {
    $admin = DriveBuilder::user();
    $this->actingAs($admin);

    $storage = DriveBuilder::storage();
    $parent = DriveBuilder::folder($storage, 'Parent');
    $child = Vaulter::forStorage($storage)->createFolder(name: 'Vetoed', parent: $parent);

    // A subtree allow on the parent makes the child a candidate; a nearer deny revokes
    // the child's EXACT access. The candidate SUPERSET still LISTS the child (the deny
    // is enforced on the per-row action, never in the listing SQL).
    DriveBuilder::grant($parent, $admin, level: GrantLevel::Reader);
    DriveBuilder::grant($child, $admin, level: GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);

    $ids = NodeResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($child->getKey());
});
