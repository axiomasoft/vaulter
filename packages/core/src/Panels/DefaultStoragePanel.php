<?php

declare(strict_types=1);

namespace Vaulter\Panels;

/**
 * The built-in default panel: shared-schema v_* tables (no prefix), the private
 * storage type. Every install ships with this panel registered so the core works
 * out of the box with zero migrations (D11).
 */
final class DefaultStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('default')
            ->storageType('private');
    }
}
