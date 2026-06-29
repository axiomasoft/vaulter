<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

/**
 * Re-declares the 'default' panel id so an explicit registration overrides the
 * built-in default (registered first). Used to prove default-first precedence.
 */
final class OverrideDefaultStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('default')
            ->storageType('overridden');
    }
}
