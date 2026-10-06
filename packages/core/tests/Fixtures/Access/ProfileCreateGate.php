<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Support\Facades\Gate;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;

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
