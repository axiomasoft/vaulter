<?php

declare(strict_types=1);

use Vaulter\Configuration\AccessConfig;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\MissingPermissionDriverException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

it('authorises purely from grants with no host roles (standalone database lane)', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');

    expect(app(PermissionDriver::class))->toBeInstanceOf(DatabasePermissionDriver::class);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'standalone');
    $actor = AccessTestHelpers::user();

    // No grant ⇒ denied; a grant ⇒ allowed. Nothing but the ACL decides.
    expect(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeFalse();

    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    expect(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeTrue();
});

it('lets the database lane create in a workspace by default', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');

    $storage = AccessTestHelpers::storage();

    expect(app(AccessResolver::class)->canCreateIn(AccessTestHelpers::user(), $storage))->toBeTrue();
});

it('subjectsFor returns the actor itself in the pure-DB lane', function (): void {
    $driver = new DatabasePermissionDriver;
    $actor = AccessTestHelpers::user(id: 7);

    $subjects = $driver->subjectsFor($actor);

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(7);
});

it('fails loud when the open null driver is selected in production', function (): void {
    config()->set('vaulter.access.permission_driver', 'null');

    // Simulate production: the manager rejects the open null driver.
    $manager = new PermissionDriverManager(accessConfig: app(AccessConfig::class), container: app(), isProduction: true);

    expect(fn (): mixed => $manager->driver())
        ->toThrow(MissingPermissionDriverException::class);
});

it('allows the open null driver outside production', function (): void {
    config()->set('vaulter.access.permission_driver', 'null');

    $manager = new PermissionDriverManager(accessConfig: app(AccessConfig::class), container: app(), isProduction: false);

    // Open in non-prod: every actor gets full access via the override.
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'open');

    app()->instance(PermissionDriverManager::class, $manager);
    app()->forgetInstance(AccessResolver::class);

    expect(app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();
});

it('throws on an unknown driver key', function (): void {
    config()->set('vaulter.access.permission_driver', 'does-not-exist');

    $manager = new PermissionDriverManager(accessConfig: app(AccessConfig::class), container: app(), isProduction: false);

    expect(fn (): mixed => $manager->driver())
        ->toThrow(MissingPermissionDriverException::class);
});
