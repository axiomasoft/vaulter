<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

/**
 * A shared-schema panel (zero migrations): no prefix, no table map — it resolves
 * the default v_* tables, just with its own storage type and grants disabled.
 */
final class TeamProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->id('team')
            ->storageType('public')
            ->withoutGrants()
            ->feature('share_links', true);
    }
}
