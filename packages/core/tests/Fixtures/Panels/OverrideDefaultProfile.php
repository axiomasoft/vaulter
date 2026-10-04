<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

/**
 * Re-declares the 'default' panel id so an explicit registration overrides the
 * built-in default (registered first). Used to prove default-first precedence.
 */
final class OverrideDefaultProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->id('default');
    }
}
