<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

/** The default profile inherits global policies over the shared schema. */
final class DefaultProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->key('default');
    }
}
