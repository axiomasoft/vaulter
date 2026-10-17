<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Panels;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileProvider;
use Vaulter\Testing\Fixtures\Panels\FakeProject;

/** A profile restricted to the project owner model. */
#[Internal]
final class ProjectProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('project')
            ->owners(FakeProject::class);
    }
}
