<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

/**
 * A shared-schema panel (zero migrations): no prefix, no table map — it resolves
 * the default v_* tables, just with its own storage type and grants disabled.
 */
final class TeamStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('team')
            ->storageType('public')
            ->withoutGrants()
            ->feature('share_links', true);
    }
}
