<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Kernel;

/**
 * The permission drivers a host can select by key: the contributions of the active modules to the
 * {@see PermissionDriver} slot (database, gate, spatie, null; azgard from its module), overlaid by the
 * host's own `vaulter.access.permission_drivers` map, where the host wins on the same key.
 */
#[Internal]
final readonly class PermissionDriverCatalog
{
    public function __construct(private AccessConfig $access, private Kernel $kernel) {}

    /** @return array<string, string> key => class */
    public function classes(): array
    {
        $classes = [];
        foreach ($this->kernel->extensions()->contributions(PermissionDriver::class) as $contribution) {
            if ($contribution->key !== null) {
                $classes[$contribution->key] = $contribution->implementation;
            }
        }

        return [...$classes, ...$this->access->permissionDrivers()];
    }

    public function has(string $key): bool
    {
        return isset($this->classes()[$key]);
    }

    public function class(string $key): ?string
    {
        return $this->classes()[$key] ?? null;
    }
}
