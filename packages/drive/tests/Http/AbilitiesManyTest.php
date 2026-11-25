<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
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

    $small = abilityQueries(fn (): array => $nodes->abilitiesMany(array_slice($ids, 0, 5)));
    $large = abilityQueries(fn (): array => $nodes->abilitiesMany($ids));

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

    $small = abilityQueries(fn (): array => $nodes->abilitiesMany(array_slice($ids, 0, 2)));
    $large = abilityQueries(fn (): array => $nodes->abilitiesMany($ids));

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
    $context = DriveContext::for($drive->model(), Actor::user(V1::user(1)), 'abilities-many');
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
    expect(array_map(strval(...), array_keys($many)))->toBe([$visible->id, $editable->id])
        ->and($many[$visible->id]->toArray())->toBe($nodes->abilities($visible->id)->toArray())
        ->and($many[$editable->id]->toArray())->toBe($nodes->abilities($editable->id)->toArray())
        ->and($many[$visible->id]->toArray()['edit'])->toBeFalse()
        ->and($many[$editable->id]->toArray()['edit'])->toBeTrue()
        ->and($nodes->abilitiesMany([]))->toBe([]);
});

it('projects the profile create gate consistently in HTTP and a batch', function (bool $allow): void {
    $drive = V1::drive(userId: 1);
    $folder = V1::folder($drive, 'folder');
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->permissionDriver('gate'));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    Gate::define('vaulter.manage', static fn (): bool => true);
    $calls = 0;
    Gate::define('vaulter.create', static function () use ($allow, &$calls): bool {
        $calls++;

        return $allow;
    });
    $nodes = $drive->actingAs(V1::user(1))->nodes();
    $abilities = $nodes->abilitiesMany([$drive->model()->root_node_id, $folder->id]);
    expect($calls)->toBe(1);
    foreach ($abilities as $set) {
        expect($set->flags['create'])->toBe($allow);
    }
    expect($nodes->abilities($folder)->flags['create'])->toBe($allow);
    $this->actingAs(V1::user(1));
    $this->getJson(V1::url('nodes/'.$folder->id))->assertOk()->assertJsonPath('data.abilities.create', $allow);
    $this->postJson(V1::url('drives/'.$drive->model()->getKey().'/folders'), ['name' => 'child', 'parent_id' => $folder->id])
        ->assertStatus($allow ? 201 : 403);
})->with([true, false]);

it('reads the drive once for the abilities of one node, not once per ability', function (): void {
    $drive = V1::drive(userId: 1, role: RoleRegistry::WRITER);
    $folder = V1::folder($drive, 'single');
    $nodes = $drive->actingAs(V1::user(1))->nodes();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $abilities = $nodes->abilities($folder->id);
    $driveReads = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/from [`"]?v_drives[`"]?/', $query['query']) === 1);
    DB::disableQueryLog();

    // The View authorization and abilities() each read the drive once; the profile gate of each ability reuses it.
    expect($abilities->flags['edit'])->toBeTrue()
        ->and(count($driveReads))->toBeLessThanOrEqual(3);
});

it('decides on a caller-supplied drive only when it is the node\'s own drive', function (): void {
    $drive = V1::drive(userId: 1, role: RoleRegistry::WRITER);
    $folder = DriveTestHelpers::model(V1::folder($drive, 'own'));
    $archived = V1::drive(userId: 1, role: RoleRegistry::WRITER);
    $archived->archive();
    $context = DriveContext::for($drive->model(), Actor::user(V1::user(1)), 'supplied-drive');
    $guard = app(OperationGuard::class);

    // Another drive (here archived) must not stand in for the node's own, active drive.
    $guard->authorize($context, Ability::Edit, $folder, OperationKind::Mutation, $archived->model()->fresh());

    expect($guard->abilities($context, $folder, $archived->model()->fresh())->flags['edit'])->toBeTrue();
});
