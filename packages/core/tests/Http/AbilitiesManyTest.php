<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;
use Vaulter\Tests\Tenancy\MutableTenantResolver;
use Vaulter\ValueObjects\Access\Actor;

/**
 * A page of nodes gets its abilities from one batch evaluation: the number of queries
 * does not grow with the page, and every node gets exactly what abilities() says.
 */
uses(RefreshDatabase::class);

function abilityQueries(callable $call): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $call();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('costs the same number of queries for 5 and for 50 nodes', function (): void {
    $drive = V1::drive(userId: 1, role: RoleRegistry::READER);
    $ids = [];
    foreach (range(1, 50) as $i) {
        $ids[] = V1::folder($drive, 'n'.$i)->id;
    }
    $nodes = $drive->actingAs(V1::user(1))->nodes();

    $small = abilityQueries(fn () => $nodes->abilitiesMany(array_slice($ids, 0, 5)));
    $large = abilityQueries(fn () => $nodes->abilitiesMany($ids));

    expect($large)->toBe($small)->toBeLessThan(15);
});

it('stays one batch under strict tenancy', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->instance(TenantResolver::class, new MutableTenantResolver);
    $drive = V1::drive(userId: 1, role: RoleRegistry::READER);
    $ids = [];
    foreach (range(1, 20) as $i) {
        $ids[] = V1::folder($drive, 't'.$i)->id;
    }
    $nodes = $drive->actingAs(V1::user(1))->nodes();

    $small = abilityQueries(fn () => $nodes->abilitiesMany(array_slice($ids, 0, 2)));
    $large = abilityQueries(fn () => $nodes->abilitiesMany($ids));

    expect($large)->toBe($small)
        ->and($nodes->abilitiesMany($ids))->toHaveCount(20);
    MutableTenantResolver::clearFixtureStamps();
});

it('keeps visible() one batch under strict tenancy', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->instance(TenantResolver::class, new MutableTenantResolver);
    $drive = V1::drive(userId: 1, role: RoleRegistry::READER);
    $parent = V1::folder($drive, 'parent');
    $nodes = [];
    foreach (range(1, 20) as $i) {
        $nodes[] = DriveTestHelpers::model(V1::folder($drive, 'v'.$i, $parent->id));
    }
    $context = new OperationContext($drive->model(), Actor::user(V1::user(1)), 'abilities-many');
    $guard = app(OperationGuard::class);

    $small = abilityQueries(fn () => $guard->visible($context, array_slice($nodes, 0, 2)));
    $large = abilityQueries(fn () => $guard->visible($context, $nodes));

    expect($large)->toBe($small)
        ->and($guard->visible($context, $nodes))->toHaveCount(20);
    MutableTenantResolver::clearFixtureStamps();
});

it('matches abilities() per node and leaves out nodes the actor cannot view', function (): void {
    $drive = V1::drive(userId: 99);
    $visible = V1::folder($drive, 'visible');
    $editable = V1::folder($drive, 'editable');
    $hidden = V1::folder($drive, 'hidden');
    AccessTestHelpers::grant(DriveTestHelpers::model($visible), RoleRegistry::READER, subjectId: 1);
    AccessTestHelpers::grant(DriveTestHelpers::model($editable), RoleRegistry::WRITER, subjectId: 1);
    $nodes = $drive->actingAs(V1::user(1))->nodes();

    $many = $nodes->abilitiesMany([$visible, $editable->id, $hidden->id, 'no-such-node']);

    // Numeric ids become integer array keys in PHP; lookups by the string id still work.
    expect(array_map('strval', array_keys($many)))->toBe([$visible->id, $editable->id])
        ->and($many[$visible->id]->toArray())->toBe($nodes->abilities($visible->id)->toArray())
        ->and($many[$editable->id]->toArray())->toBe($nodes->abilities($editable->id)->toArray())
        ->and($many[$visible->id]->toArray()['edit'])->toBeFalse()
        ->and($many[$editable->id]->toArray()['edit'])->toBeTrue()
        ->and($nodes->abilitiesMany([]))->toBe([]);
});
