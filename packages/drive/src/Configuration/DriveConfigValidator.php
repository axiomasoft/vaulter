<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Drive\Access\PermissionDriverCatalog;
use Vaulter\Drive\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;

/**
 * The validation of the `vaulter-drive.*` sections: the permission drivers, the swappable models, the sharing token
 * generator, the profiles and the scanner. It runs at the boot of the drive module and again, listing every failure,
 * under `vaulter:doctor` (the kernel validates its own sections in {@see ConfigValidator}).
 */
#[Internal]
final readonly class DriveConfigValidator
{
    /** The swappable models a drive validates; the upload models are checked by the drive registrar. */
    private const array MODELS = [
        'drive',
        'node',
        'file',
        'scan_obligation', 'scan_request', 'scan_evidence', 'scan_policy_state', 'scan_backfill_run',
        'file_revision',
        'file_revision_blob',
        'grant',
        'node_share',
        'activity',
    ];

    public function __construct(private Repository $repository) {}

    public function validate(DriveConfig $config, Application $app): void
    {
        $access = $config->access();
        $key = $access->permissionDriver();
        $kernel = $app->make(Kernel::class);
        $drivers = (new PermissionDriverCatalog($access, $kernel))->classes();
        if (!isset($drivers[$key])) {
            throw new InvalidConfigurationException('[Vaulter] Unknown '.AccessConfig::PERMISSION_DRIVER_KEY.' "'.$key.'".');
        }
        ConfigValidator::assertImplements(AccessConfig::PERMISSION_DRIVERS_KEY.'.'.$key, $drivers[$key], PermissionDriver::class);
        if ($app->environment('production') && is_a($drivers[$key], NonProductionPermissionDriver::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] Non-production permission driver "'.$key.'" is forbidden in production.');
        }
        $config->changes()->retentionDays();
        $config->changes()->maxPageSize();
        $config->sharing()->tokenGenerator();
        foreach (self::MODELS as $model) {
            $config->model($model);
        }

        try {
            if ($app->resolved(ProfileRegistry::class)) {
                $registry = $app->make(ProfileRegistry::class);
                // Standalone validation may use a different Repository than the booted app.
                if ($config !== $app->make(DriveConfig::class)) {
                    $registry = ProfileRegistry::fromConfig($config->profileProviders(), $config->defaultProfile(), $app);
                }
            } else {
                $registry = ProfileRegistry::fromConfig($config->profileProviders(), $config->defaultProfile(), $app);
                $app->instance(ProfileRegistry::class, $registry);
            }
            $policy = new ProfilePolicy($config, $this->repository, $registry, new ScanConfig($this->repository), $kernel);
            foreach ($registry->all() as $profile) {
                $policy->validate($profile);
                $profileKey = $profile->getPermissionDriver() ?? $access->permissionDriver();
                ConfigValidator::assertImplements(AccessConfig::PERMISSION_DRIVERS_KEY.'.'.$profileKey, $drivers[$profileKey] ?? '', PermissionDriver::class);
                if ($app->environment('production') && is_a($drivers[$profileKey], NonProductionPermissionDriver::class, true)) {
                    throw new InvalidConfigurationException('[Vaulter] Non-production profile permission driver "'.$profileKey.'" is forbidden in production.');
                }
            }
            (new ScanConfig($this->repository))->scanner();
        } catch (InvalidProfileException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), previous: $exception);
        }
    }
}
