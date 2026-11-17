<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\PermissionDriverCatalog;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;

/**
 * Semantic runtime validator for `vaulter-drive.*` sections (driver catalog, profiles, scanner queue ordering).
 */
#[Internal]
final readonly class DriveOptionsValidator
{
    public function __construct(private Repository $repository) {}

    public function validate(DriveOptions $options, Application $app): void
    {
        $access = $options->access;
        $key = $access->permissionDriver;
        $kernel = $app->make(Kernel::class);
        $drivers = new PermissionDriverCatalog($access, $kernel)->classes();
        if (!isset($drivers[$key])) {
            throw new InvalidConfigurationException('[Vaulter] Unknown '.AccessOptions::PERMISSION_DRIVER_KEY.' "'.$key.'".');
        }
        self::assertImplements(AccessOptions::PERMISSION_DRIVERS_KEY.'.'.$key, $drivers[$key], PermissionDriver::class);

        try {
            if ($app->resolved(ProfileRegistry::class)) {
                $registry = $app->make(ProfileRegistry::class);
                if (!$app->resolved(DriveOptions::class) || $options !== $app->make(DriveOptions::class)) {
                    $registry = ProfileRegistry::fromConfig($options->profiles->providers, $options->profiles->default, $app);
                }
            } else {
                $registry = ProfileRegistry::fromConfig($options->profiles->providers, $options->profiles->default, $app);
                $app->instance(ProfileRegistry::class, $registry);
            }

            $policy = new ProfilePolicy($options, $this->repository, $registry, $options->scan, $kernel);
            foreach ($registry->all() as $profile) {
                $policy->validate($profile);
                $profileKey = $profile->getPermissionDriver() ?? $access->permissionDriver;
                self::assertImplements(AccessOptions::PERMISSION_DRIVERS_KEY.'.'.$profileKey, $drivers[$profileKey] ?? '', PermissionDriver::class);
            }
        } catch (InvalidProfileException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), previous: $exception);
        }
    }

    public function validateScanWork(DriveOptions $options, bool $requireBroker = false): void
    {
        $scan = $options->uploads->scan;
        $connection = $this->repository->get('queue.default');
        if (!is_string($connection) || $connection === '') {
            throw new InvalidConfigurationException('Queue connection must be configured.');
        }

        $driver = $this->repository->get('queue.connections.'.$connection.'.driver');
        $retry = $this->repository->get('queue.connections.'.$connection.'.retry_after', $requireBroker ? null : 180);
        if ($requireBroker && (!is_string($driver) || in_array($driver, ['sync', 'null'], true))) {
            throw new InvalidConfigurationException('Scanning requires an asynchronous queue connection.');
        }

        if (
            $scan->scannerTimeoutSeconds >= 90
            || $scan->leaseSeconds <= 90
            || ($requireBroker && (!is_int($retry) || $retry < 180 || $retry <= $scan->leaseSeconds))
        ) {
            throw new InvalidConfigurationException('Scanner deadline, job timeout, lease and broker retry_after must be ordered.');
        }
    }

    /** @param class-string $contract */
    private static function assertImplements(string $path, mixed $class, string $contract): void
    {
        if (!is_string($class) || $class === '' || (!class_exists($class) && !interface_exists($class)) || !is_a($class, $contract, true)) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Configuration %s must reference a class implementing %s.', $path, $contract));
        }
    }
}
