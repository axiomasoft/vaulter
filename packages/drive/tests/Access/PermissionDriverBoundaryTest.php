<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Configuration\AccessOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Exceptions\Access\MissingPermissionDriverException;
use Vaulter\Drive\Testing\NullPermissionDriver;

class ActorOnlyBoundaryDriver extends DatabasePermissionDriver implements NonEnumeratingPermissionDriver {}

it('retains actor-only classification without naming an optional bridge', function (): void {
    $manager = app(PermissionDriverManager::class);

    expect($manager->enumeratesRoleSubjects(new ActorOnlyBoundaryDriver))->toBeFalse()
        ->and($manager->enumeratesRoleSubjects(new class extends ActorOnlyBoundaryDriver {}))->toBeFalse()
        ->and($manager->enumeratesRoleSubjects(new class extends ActorOnlyBoundaryDriver
        {
            public function subjectsFor(Authorizable $actor): array
            {
                return [...parent::subjectsFor($actor), new Subject(type: 'role', id: 9)];
            }
        }))->toBeTrue()
        ->and($manager->enumeratesRoleSubjects(new NullPermissionDriver))->toBeFalse();
});

it('rejects the open testing driver in production through a neutral contract', function (): void {
    config()->set('vaulter-drive.access.permission_drivers.open', NullPermissionDriver::class);
    app()->forgetInstance(DriveOptions::class);
    app()->forgetInstance(AccessOptions::class);
    $manager = new PermissionDriverManager(accessOptions: app(AccessOptions::class), container: app(), isProduction: true);

    expect(fn (): PermissionDriver => $manager->driver('open'))->toThrow(MissingPermissionDriverException::class);
});

it('rejects an uninstalled bridge driver as unknown', function (): void {
    $manager = app(PermissionDriverManager::class);

    expect(fn () => $manager->driver('azguard'))->toThrow(MissingPermissionDriverException::class);
});
