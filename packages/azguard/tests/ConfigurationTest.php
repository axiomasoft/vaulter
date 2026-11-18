<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\AzGuard\AzGuardPermissionMap;
use Vaulter\AzGuard\Configuration\AzGuardOptions;
use Vaulter\Drive\Enums\Access\Ability;

it('compiles immutable settings and permission maps from the repository', function (): void {
    $repository = new Repository;
    $config = AzGuardOptions::fromArray(($repository)->get('vaulter-azguard', []));
    $map = new AzGuardPermissionMap($config);

    expect($config->panelId())->toBeNull()
        ->and($map->permissionFor(Ability::Edit))->toBe('documents.update')
        ->and($map->createPermission())->toBe('documents.create');

    $repository->set('vaulter-azguard.panel_id', 'host');
    $repository->set('vaulter-azguard.permissions', ['edit' => 'host.write']);
    $repository->set('vaulter-azguard.create_permission', 'host.create');

    expect($config->panelId())->toBeNull();
    $config = AzGuardOptions::fromArray($repository->get('vaulter-azguard', []));
    $map = new AzGuardPermissionMap($config);

    expect($config->panelId())->toBe('host')
        ->and($map->permissionFor(Ability::Edit))->toBe('host.write')
        ->and($map->permissionFor(Ability::View))->toBeNull()
        ->and($map->createPermission())->toBe('host.create');
});

it('filters malformed map entries and falls back for malformed settings', function (): void {
    $repository = new Repository(['vaulter-azguard' => [
        'panel_id' => [],
        'permissions' => ['edit' => 'host.write', 'view' => '', 'manage' => false, 2 => 'invalid'],
        'create_permission' => '',
    ]]);
    $config = AzGuardOptions::fromArray(($repository)->get('vaulter-azguard', []));

    expect($config->panelId())->toBeNull()
        ->and($config->permissions())->toBe(['edit' => 'host.write'])
        ->and($config->createPermission())->toBe('documents.create');

    $repository->set('vaulter-azguard.permissions', false);
    $config = AzGuardOptions::fromArray($repository->get('vaulter-azguard', []));
    expect($config->permissions()['view'])->toBe('documents.view');

    $repository->set('vaulter-azguard.permissions', []);
    $config = AzGuardOptions::fromArray($repository->get('vaulter-azguard', []));
    expect($config->permissions()['restore'])->toBe('documents.delete');
});

it('rejects unknown configuration keys before runtime wiring', function (): void {
    expect(fn (): AzGuardOptions => AzGuardOptions::fromArray(['unexpected' => true]))
        ->toThrow(InvalidConfiguration::class);
});
