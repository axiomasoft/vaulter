<?php

declare(strict_types=1);

namespace Vaulter\Drive\Security;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Drive\Access\PermissionDriverCatalog;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Kernel;

/** The default and every profile permission driver must be production-grade; unknown drivers are a structural error elsewhere. */
#[Internal]
final readonly class PermissionDriverInvariant implements ProductionInvariant
{
    public function __construct(private DriveConfig $config, private Application $app) {}

    public function id(): string
    {
        return 'drive.permission-driver';
    }

    public function violation(): ?string
    {
        $access = $this->config->access();
        $drivers = (new PermissionDriverCatalog($access, $this->app->make(Kernel::class)))->classes();
        $keys = [$access->permissionDriver() => true];
        if ($this->app->resolved(ProfileRegistry::class)) {
            foreach ($this->app->make(ProfileRegistry::class)->all() as $profile) {
                $keys[$profile->getPermissionDriver() ?? $access->permissionDriver()] = true;
            }
        }
        ksort($keys);
        foreach (array_keys($keys) as $key) {
            if (isset($drivers[$key]) && is_a($drivers[$key], NonProductionPermissionDriver::class, true)) {
                return sprintf('Non-production permission driver "%s" is forbidden in production; set vaulter-drive.access.permission_driver (and profile drivers) to a production driver.', $key);
            }
        }

        return null;
    }
}
