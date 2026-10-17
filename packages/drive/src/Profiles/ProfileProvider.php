<?php

declare(strict_types=1);

namespace Vaulter\Drive\Profiles;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/** A config-cache-safe provider of a named drive policy. */
#[Spi(SpiKind::Implement)]
abstract class ProfileProvider
{
    abstract public function profile(Profile $profile): Profile;

    public function resolve(): Profile
    {
        return $this->profile(new Profile);
    }
}
