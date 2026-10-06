<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

/** A duplicate default key used to prove boot-time rejection. */
final class OverrideDefaultProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('default');
    }
}
