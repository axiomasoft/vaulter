<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Facades\Vaulter;
use Vaulter\Testing\NullPermissionDriver;

// The fragments below are copied into the guides byte for byte.
it('selects the permission driver by its config key (permission drivers guide)', function (): void {
    $config = [
        // [recipe:permission-driver-select]
        // config/vaulter.php
        'access' => [
            'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
        ],
        // [/recipe]
    ];
    expect($config['access']['permission_driver'])->toBe('database');

    config()->set('vaulter.access.permission_driver', 'gate');
    app()->forgetInstance(PermissionDriverManager::class);

    expect(Vaulter::permissions()->driver())->toBeInstanceOf(GatePermissionDriver::class);

    config()->set('vaulter.access.permission_driver', $config['access']['permission_driver']);
    app()->forgetInstance(PermissionDriverManager::class);

    expect(Vaulter::permissions()->driver())->toBeInstanceOf(DatabasePermissionDriver::class);
});

it('names a PermissionDriver implementation for every documented key (permission drivers guide)', function (): void {
    $config = [
        // [recipe:permission-driver-map]
        // config/vaulter.php
        'access' => ['permission_drivers' => [
            'database' => DatabasePermissionDriver::class,
            'gate' => GatePermissionDriver::class,
            'spatie' => SpatiePermissionDriver::class,
            'null' => NullPermissionDriver::class, // non-prod only
            // 'tenant' => \App\Vaulter\TenantRoleDriver::class,
        ]],
        // [/recipe]
    ];

    foreach ($config['access']['permission_drivers'] as $key => $class) {
        expect(is_subclass_of($class, PermissionDriver::class))->toBeTrue("{$key} → {$class}")
            ->and(config('vaulter.access.permission_drivers.'.$key))->toBe($class);
    }
});
