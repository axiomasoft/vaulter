<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

/** A shared-schema team profile with local grants and share links disabled. */
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
