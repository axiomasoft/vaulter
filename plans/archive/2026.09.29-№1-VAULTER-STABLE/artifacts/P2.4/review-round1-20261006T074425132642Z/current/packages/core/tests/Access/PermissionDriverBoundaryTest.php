<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Exceptions\Access\MissingPermissionDriverException;
use Vaulter\Testing\NullPermissionDriver;

class ActorOnlyBoundaryDriver extends DatabasePermissionDriver implements NonEnumeratingPermissionDriver
{
    public function subjectsFor(Authorizable $actor): array
    {
        return parent::subjectsFor($actor);
    }
}

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
    config()->set('vaulter.permission_drivers.open', NullPermissionDriver::class);
    $manager = new PermissionDriverManager(container: app(), isProduction: true);

    expect(fn () => $manager->driver('open'))->toThrow(MissingPermissionDriverException::class);
});

it('rejects an uninstalled bridge driver as unknown', function (): void {
    $manager = app(PermissionDriverManager::class);

    expect(fn () => $manager->driver('azgard'))->toThrow(MissingPermissionDriverException::class);
});
