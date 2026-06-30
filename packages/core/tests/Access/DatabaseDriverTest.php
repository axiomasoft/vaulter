<?php

declare(strict_types=1);

use Vaulter\Access\AccessResolver;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Exceptions\Access\MissingHostRoleDriverException;
use Vaulter\Tests\Access\AccessTestHelpers;

it('authorises purely from grants with no host roles (standalone database lane)', function (): void {
    config()->set('vaulter.drivers.permission', 'database');

    expect(app(HostRoleDriver::class))->toBeInstanceOf(DatabasePermissionResolver::class);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'standalone');
    $actor = AccessTestHelpers::user();

    // No grant ⇒ denied; a grant ⇒ allowed. Nothing but the ACL decides.
    expect(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeFalse();

    AccessTestHelpers::grant($folder, GrantLevel::Reader, scope: GrantScope::Item);

    expect(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeTrue();
});

it('lets the database lane create in a workspace by default', function (): void {
    config()->set('vaulter.drivers.permission', 'database');

    $storage = AccessTestHelpers::storage();

    expect(app(AccessResolver::class)->canCreateInWorkspace(AccessTestHelpers::user(), $storage))->toBeTrue();
});

it('subjectsFor returns the actor itself in the pure-DB lane', function (): void {
    $driver = new DatabasePermissionResolver;
    $actor = AccessTestHelpers::user(id: 7);

    $subjects = $driver->subjectsFor($actor);

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(7);
});

it('fails loud when the open null driver is selected in production', function (): void {
    config()->set('vaulter.drivers.permission', 'null');

    // Simulate production: the manager rejects the open null driver.
    $manager = new HostRoleDriverManager(container: app(), isProduction: true);

    expect(fn (): mixed => $manager->driver())
        ->toThrow(MissingHostRoleDriverException::class);
});

it('allows the open null driver outside production', function (): void {
    config()->set('vaulter.drivers.permission', 'null');

    $manager = new HostRoleDriverManager(container: app(), isProduction: false);

    // Open in non-prod: every actor gets full access via the override.
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'open');

    app()->instance(HostRoleDriverManager::class, $manager);
    app()->forgetInstance(AccessResolver::class);

    expect(app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();
});

it('throws on an unknown driver key', function (): void {
    config()->set('vaulter.drivers.permission', 'does-not-exist');

    $manager = new HostRoleDriverManager(container: app(), isProduction: false);

    expect(fn (): mixed => $manager->driver())
        ->toThrow(MissingHostRoleDriverException::class);
});
