<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

/** A config-cache-safe provider of a named drive policy. */
abstract class ProfileProvider
{
    abstract public function profile(Profile $profile): Profile;

    public function resolve(): Profile
    {
        return $this->profile(new Profile);
    }
}
