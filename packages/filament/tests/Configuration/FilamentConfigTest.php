<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Filament\Configuration\FilamentConfig;

it('reads navigation and resource toggles live with safe malformed defaults', function (): void {
    $repository = new Repository;
    $config = new FilamentConfig($repository);

    expect($config->navigationGroup())->toBe('Vaulter')
        ->and($config->resourceEnabled('drive'))->toBeTrue();

    $repository->set('vaulter-filament.navigation_group', 'Files');
    $repository->set('vaulter-filament.resources.drive', false);
    $repository->set('vaulter-filament.resources.node', 'false');

    expect($config->navigationGroup())->toBe('Files')
        ->and($config->resourceEnabled('drive'))->toBeFalse()
        ->and($config->resourceEnabled('node'))->toBeTrue();
});
