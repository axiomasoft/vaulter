<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

/**
 * A prefixed (own-tables, D12) panel embedded into FakeProject: it overrides the
 * node table (project_drive_nodes), maps the node model to ProjectNode and pins
 * its own id strategy (coherent only because it has own tables).
 */
final class ProjectProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->id('project')
            ->host(FakeProject::class);
    }
}
