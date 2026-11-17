<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Panels;

use Packman\Attributes\Internal;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileProvider;

/** A shared-schema team profile with local grants and share links disabled. */
#[Internal]
final class TeamProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('team')

            ->grants(false)
            ->shareLinks(false);
    }
}
