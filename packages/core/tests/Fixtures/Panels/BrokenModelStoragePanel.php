<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

/**
 * A panel that references a non-existent model FQCN — used to prove PanelRegistry
 * validation fires at BOOT (F-P3.2-01), not on the first request that touches the
 * panel. Never registered by the base TestCase; only BrokenPanelBootTestCase
 * config('vaulter.panels')-s it in.
 */
final class BrokenModelStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        /** @var array<string, class-string> $map */
        $map = ['nodes' => 'App\\Does\\Not\\Exist'];

        return $panel
            ->id('broken')
            ->storageType('private')
            ->models($map);
    }
}
