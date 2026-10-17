<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Surface;

/*
 * The supported surface of core (D27, I13), read from the #[Api], #[Spi] and #[Internal] attributes of packages/core/src. The
 * hand-listed public-API golden of 0.x is gone: this manifest is generated, and the repository-wide proof (every package, the I13
 * scan) lives in tests/Repo/Arch. Refresh: scripts/php84 php scripts/inventory/inventory.php surface --write
 */

require_once dirname(__DIR__, 4).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 4).'/scripts/inventory/lib/Surface.php';

it('keeps the committed surface manifest of core equal to the attributes of its code', function (): void {
    $repo = Repo::discover();
    $manifest = Surface::manifests(Surface::read($repo))['core'];

    expect($repo->read(Surface::snapshotPath('core')))->toBe($manifest)
        ->and($manifest)->toContain('Vaulter\Kernel api since 1.0')
        ->and($manifest)->not->toContain('\Facades\Vaulter api')
        ->and($manifest)->not->toContain('\VaulterManager api')
        ->and($manifest)->toContain('Vaulter\Attributes\Api api since 1.0');
});

it('marks every type of core with exactly one surface attribute', function (): void {
    $types = array_filter(Surface::read(Repo::discover()), static fn (array $type): bool => $type['package'] === 'core');

    expect($types)->not->toBe([])
        ->and(array_column(array_filter($types, static fn (array $type): bool => $type['surface'] === 'undecided'), 'fqcn'))->toBe([]);
});
