<?php

declare(strict_types=1);

use DocVault\Filament\DocVaultPlugin;
use Filament\Contracts\Plugin;

arch('all package code declares strict types')
    ->expect('DocVault\Filament')
    ->toUseStrictTypes();

arch('the plugin implements the Filament plugin contract')
    ->expect(DocVaultPlugin::class)
    ->toImplement(Plugin::class);

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
