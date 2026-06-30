<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

/**
 * A prefixed (own-tables, D12) panel embedded into FakeProject: it overrides the
 * node table (project_drive_nodes), maps the node model to ProjectNode and pins
 * its own id strategy (coherent only because it has own tables).
 */
final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project')
            ->host(FakeProject::class)
            ->storageType('team')
            ->tablePrefix('project_drive')
            ->idStrategy('uuid7')
            ->models([
                'nodes' => ProjectNode::class,
            ]);
    }

    public function booted(Panel $panel): void
    {
        self::$bootedId = $panel->getId();
    }

    public static ?string $bootedId = null;
}
