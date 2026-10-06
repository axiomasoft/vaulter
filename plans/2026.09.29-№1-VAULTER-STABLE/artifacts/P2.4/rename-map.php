<?php

declare(strict_types=1);

return [
    'Vaulter\Access\Drivers\AzgardPermissionDriver' => 'Vaulter\Azgard\AzgardPermissionDriver',
    'Vaulter\Contracts\Access\AzgardGuard' => 'Vaulter\Azgard\Contracts\AzgardGuard',
    'Vaulter\Support\AzgardConfig' => 'Vaulter\Azgard\AzgardPermissionMap',
    'Vaulter\Testing\FakeAzgardGuard' => 'Vaulter\Azgard\Testing\FakeAzgardGuard',
    'Vaulter\Filament\Resources\StorageResource' => 'Vaulter\Filament\Resources\DriveResource',
    'Vaulter\Filament\Resources\StorageResource\Pages\ListStorages' => 'Vaulter\Filament\Resources\DriveResource\Pages\ListDrives',
    'Vaulter\Filament\Resources\StorageResource\Pages\ViewStorage' => 'Vaulter\Filament\Resources\DriveResource\Pages\ViewDrive',
];
