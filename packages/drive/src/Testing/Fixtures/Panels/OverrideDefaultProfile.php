<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Panels;

use Packman\Attributes\Internal;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileProvider;

/** A duplicate default key used to prove boot-time rejection. */
#[Internal]
final class OverrideDefaultProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('default');
    }
}
