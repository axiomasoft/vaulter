<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\AzGuard\AzGuardPermissionMap;
use Vaulter\AzGuard\Configuration\AzGuardConfig;
use Vaulter\Drive\Enums\Access\Ability;

it('reads each typed setting and the permission map live from the repository', function (): void {
    $repository = new Repository;
    $config = new AzGuardConfig($repository);
    $map = new AzGuardPermissionMap($config);

    expect($config->panelId())->toBeNull()
        ->and($map->permissionFor(Ability::Edit))->toBe('documents.update')
        ->and($map->createPermission())->toBe('documents.create');

    $repository->set('vaulter-azguard.panel_id', 'host');
    $repository->set('vaulter-azguard.permissions', ['edit' => 'host.write']);
    $repository->set('vaulter-azguard.create_permission', 'host.create');

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
    $config = new AzGuardConfig($repository);

    expect($config->panelId())->toBeNull()
        ->and($config->permissions())->toBe(['edit' => 'host.write'])
        ->and($config->createPermission())->toBe('documents.create');

    $repository->set('vaulter-azguard.permissions', false);
    expect($config->permissions()['view'])->toBe('documents.view');

    $repository->set('vaulter-azguard.permissions', []);
    expect($config->permissions()['restore'])->toBe('documents.delete');
});
