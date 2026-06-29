<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Models\Concerns\HasVaulterPanel;

/**
 * A concrete per-panel node model: panel-aware, so getTable() resolves the
 * "project" panel's prefixed table (project_drive_nodes) and the key strategy
 * comes from the panel in own-tables mode.
 */
final class ProjectNode extends NodeFixture
{
    use HasVaulterPanel;

    protected string $vaulterPanel = 'project';

    protected string $vaulterEntity = 'nodes';
}
