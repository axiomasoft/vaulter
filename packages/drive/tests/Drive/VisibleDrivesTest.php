<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

/*
 * Vaulter::drives() lists the drives whose root the actor may view and decides that before the page is cut
 * (D10, architecture#operation-chains «Чтение»); Vaulter::findDriveById() is the id lookup of a listed drive.
 */

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $this->actor = AccessTestHelpers::user();
    $this->actingAs($this->actor);
});

/** @return list<string> */
function listedDriveIds(object $page): array
{
    return array_map(static fn ($snapshot): string => $snapshot->id, $page->items);
}

it('lists the drives whose root the actor may view and nothing else', function (): void {
    $visible = AccessTestHelpers::storage();
    $hidden = AccessTestHelpers::storage();
    AccessTestHelpers::grant($visible->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);

    $page = Vaulter::drives($this->actor);

    expect(listedDriveIds($page))->toBe([(string) $visible->getKey()])
        ->and($page->nextCursor)->toBeNull()
        ->and($page->items[0]->name)->toBe($visible->name)
        ->and(listedDriveIds($page))->not->toContain((string) $hidden->getKey());
});

it('lists nothing for an actor without any grant', function (): void {
    AccessTestHelpers::storage();

    expect(Vaulter::drives(AccessTestHelpers::user(99))->items)->toBe([]);
});

it('does not list a drive the actor reaches only through a deeper node', function (): void {
    $drive = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($drive, null, 'Inner');
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER);

    expect(Vaulter::drives($this->actor)->items)->toBe([]);
});

it('cuts the page after the visibility decision, so hidden drives take no slot', function (): void {
    $visible = [];
    for ($i = 0; $i < 5; $i++) {
        // A hidden drive between every two visible ones.
        AccessTestHelpers::storage();
        $drive = AccessTestHelpers::storage();
        AccessTestHelpers::grant($drive->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);
        $visible[] = (string) $drive->getKey();
    }

    $first = Vaulter::drives($this->actor, new ListOptions(perPage: 2));
    expect(listedDriveIds($first))->toBe(array_slice($visible, 0, 2))
        ->and($first->nextCursor)->not->toBeNull();

    $second = Vaulter::drives($this->actor, new ListOptions(perPage: 2, cursor: $first->nextCursor));
    expect(listedDriveIds($second))->toBe(array_slice($visible, 2, 2))
        ->and($second->nextCursor)->not->toBeNull();

    $third = Vaulter::drives($this->actor, new ListOptions(perPage: 2, cursor: $second->nextCursor));
    expect(listedDriveIds($third))->toBe(array_slice($visible, 4))
        ->and($third->nextCursor)->toBeNull();
});

it('reports no next page when the last visible drive fills the page exactly', function (): void {
    foreach (range(1, 2) as $_) {
        AccessTestHelpers::grant(AccessTestHelpers::storage()->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);
    }
    AccessTestHelpers::storage(); // hidden, after the last visible one

    $page = Vaulter::drives($this->actor, new ListOptions(perPage: 2));

    expect($page->items)->toHaveCount(2)
        ->and($page->nextCursor)->toBeNull();
});

it('walks past a full chunk of hidden candidates to a visible drive', function (): void {
    foreach (range(1, 101) as $_) {
        AccessTestHelpers::storage();
    }
    $drive = AccessTestHelpers::storage();
    AccessTestHelpers::grant($drive->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);

    expect(listedDriveIds(Vaulter::drives($this->actor, new ListOptions(perPage: 1))))->toBe([(string) $drive->getKey()]);
});

it('refuses a malformed cursor and options a drive list does not support', function (): void {
    expect(fn () => Vaulter::drives($this->actor, new ListOptions(cursor: 'not-a-cursor')))->toThrow(InvalidCursorException::class)
        ->and(fn () => Vaulter::drives($this->actor, new ListOptions(cursor: rtrim(strtr(base64_encode('{"k":[]}'), '+/', '-_'), '='))))->toThrow(InvalidCursorException::class)
        ->and(fn () => Vaulter::drives($this->actor, new ListOptions(sort: NodeSort::Name)))->toThrow(InvalidArgumentException::class);
});

it('keeps the cost of a further listed drive bounded', function (): void {
    $grantNew = static fn () => AccessTestHelpers::grant(AccessTestHelpers::storage()->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Vaulter::drives($this->actor, new ListOptions(perPage: 50));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $grantNew();
    $queries(); // warm the per-request registries and caches
    $one = $queries();
    $grantNew();
    $two = $queries();
    foreach (range(1, 3) as $_) {
        $grantNew();
    }
    $five = $queries();

    // The root check is the only per-drive work: a bounded handful of queries, not one more query per row of anything.
    expect($two - $one)->toBeGreaterThan(0)->toBeLessThanOrEqual(12)
        ->and(($five - $two) / 3)->toBeLessThanOrEqual(12);
});

it('finds a drive by key and answers null for an unknown key', function (): void {
    $drive = AccessTestHelpers::storage();

    $handle = Vaulter::findDriveById($drive->getKey());

    expect($handle)->not->toBeNull()
        ->and($handle->snapshot()->id)->toBe((string) $drive->getKey())
        ->and(Vaulter::findDriveById('999999'))->toBeNull()
        ->and(Vaulter::findDriveById('not-a-key'))->toBeNull();
});

it('keeps the lookup and the listing inside the current tenant', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);

    $resolver->tenant = 'A';
    $a = AccessTestHelpers::storage();
    AccessTestHelpers::grant($a->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);
    $resolver->tenant = 'B';
    $b = AccessTestHelpers::storage();
    AccessTestHelpers::grant($b->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);

    expect(listedDriveIds(Vaulter::drives($this->actor)))->toBe([(string) $b->getKey()])
        ->and(Vaulter::findDriveById($a->getKey()))->toBeNull()
        ->and(Vaulter::findDriveById($b->getKey()))->not->toBeNull();
});
