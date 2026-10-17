<?php

declare(strict_types=1);

namespace Vaulter\Drive\Profiles;

use Vaulter\Attributes\Internal;

/** The default profile inherits global policies over the shared schema. */
#[Internal]
final class DefaultProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('default');
    }
}
