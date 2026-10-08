<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Vaulter\Azgard\Configuration\AzgardConfig;
use Vaulter\Enums\Access\Ability;

/**
 * Typed accessors over vaulter-azgard — the AzGuard host-role lane's
 * Ability→permission map. Unlike the Gate/Spatie maps (keyed by role), AzGuard is
 * action-centric, so the {@see AzgardPermissionDriver}
 * maps a requested {@see Ability} to an AzGuard permission key (e.g. View →
 * documents.view) and asks the {@see AzgardGuard} that permission.
 * Plain strings — cache-safe, no closures.
 */
final readonly class AzgardPermissionMap
{
    public function __construct(private AzgardConfig $config) {}

    /** @return array<string, string> */
    public function permissionMap(): array
    {
        return $this->config->permissions();
    }

    public function createPermission(): string
    {
        return $this->config->createPermission();
    }

    public function permissionFor(Ability $ability): ?string
    {
        return $this->permissionMap()[$ability->value] ?? null;
    }
}
