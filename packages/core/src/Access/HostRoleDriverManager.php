<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Illuminate\Contracts\Container\Container;
use Vaulter\Contracts\HostRoleDriver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Exceptions\MissingHostRoleDriverException;
use Vaulter\Support\Config;
use Vaulter\Testing\NullRoleDriver;

/**
 * Resolves the active {@see HostRoleDriver} from config (H.2), keyed by the driver
 * key so a per-panel resolved-state never leaks across panels (H.1). It is the
 * single seam the AccessResolver obtains its role lane through, and the place the
 * null-driver fail-loud guard (D6″) lives: the open NullRoleDriver is rejected in
 * production, so a missing/null driver can never silently open a prod install.
 *
 * The default key is 'database' — the standalone {@see DatabasePermissionResolver}.
 */
final class HostRoleDriverManager
{
    /**
     * @var array<string, HostRoleDriver>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly bool $isProduction,
    ) {}

    /**
     * The driver for the given key (default: the configured driver). Memoised per
     * key (H.1). Throws in production when the key resolves to the open null
     * driver, or when an unknown key has no FQCN.
     */
    public function driver(?string $key = null): HostRoleDriver
    {
        $key ??= Config::permissionDriver();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $class = $this->classFor($key);

        if (is_a($class, NullRoleDriver::class, allow_string: true) && $this->isProduction) {
            throw MissingHostRoleDriverException::nullDriverInProduction($key);
        }

        /** @var HostRoleDriver $driver */
        $driver = $this->container->make($class);

        return $this->resolved[$key] = $driver;
    }

    /**
     * @return class-string<HostRoleDriver>
     */
    private function classFor(string $key): string
    {
        $drivers = Config::permissionDrivers();

        if (isset($drivers[$key])) {
            return $drivers[$key];
        }

        throw MissingHostRoleDriverException::unknownDriver($key, array_keys($drivers));
    }
}
