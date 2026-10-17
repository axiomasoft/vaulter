<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Support\Facades\Gate;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;

#[Internal]
final class ProfileCreateGate
{
    public static function install(bool $allow): void
    {
        config()->set('vaulter.access.permission_driver', 'database');
        $registry = new ProfileRegistry;
        $registry->register((new Profile)->key('default')->permissionDriver('gate'));
        $registry->freeze();
        app()->instance(ProfileRegistry::class, $registry);
        Gate::define('vaulter.manage', static fn (): bool => true);
        Gate::define('vaulter.create', static fn ($actor, array $context): bool => $allow && $context['profile'] === 'default');
    }
}
