<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Vaulter\Filament\VaulterPlugin;

arch('all package code declares strict types')
    ->expect('Vaulter\Filament')
    ->toUseStrictTypes();

arch('the plugin implements the Filament plugin contract')
    ->expect(VaulterPlugin::class)
    ->toImplement(Plugin::class);

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

// T6 — filament requires ONLY core in production; it never depends on drive,
// documents or media (require-dev pulls drive/media for TEST fixtures only,
// see tests/TestCase.php).
arch('filament never depends on drive, documents or media (T6 — filament requires only core)')
    ->expect('Vaulter\Filament')
    ->not->toUse(['Vaulter\Drive', 'Vaulter\Documents', 'Vaulter\Media']);
