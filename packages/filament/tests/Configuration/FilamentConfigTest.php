<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Filament\Configuration\FilamentOptions;

it('compiles immutable navigation and resource toggles with safe malformed defaults', function (): void {
    $repository = new Repository;
    $config = FilamentOptions::fromArray(($repository)->get('vaulter-filament', []));

    expect($config->navigationGroup())->toBe('Vaulter')
        ->and($config->resourceEnabled('drive'))->toBeTrue();

    $repository->set('vaulter-filament.navigation_group', 'Files');
    $repository->set('vaulter-filament.resources.drive', false);
    $repository->set('vaulter-filament.resources.node', 'false');

    expect($config->navigationGroup())->toBe('Vaulter')->and($config->resourceEnabled('drive'))->toBeTrue();
    $config = FilamentOptions::fromArray($repository->get('vaulter-filament', []));

    expect($config->navigationGroup())->toBe('Files')
        ->and($config->resourceEnabled('drive'))->toBeFalse()
        ->and($config->resourceEnabled('node'))->toBeTrue();
});

it('rejects unknown configuration keys before runtime wiring', function (): void {
    expect(fn (): FilamentOptions => FilamentOptions::fromArray(['resources' => ['unexpected' => true]]))
        ->toThrow(InvalidConfiguration::class);
});
