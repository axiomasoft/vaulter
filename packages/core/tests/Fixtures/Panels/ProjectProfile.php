<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;
use Vaulter\Testing\Fixtures\Panels\FakeProject;

/** A profile restricted to the project owner model. */
final class ProjectProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('project')
            ->owners(FakeProject::class);
    }
}
