<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Vaulter\Attributes\Internal;
use Vaulter\AzGuard\Configuration\AzGuardConfig;
use Vaulter\Drive\Enums\Access\Ability;

/**
 * Typed accessors over vaulter-azguard — the AzGuard host-role lane's
 * Ability→permission map. Unlike the Gate/Spatie maps (keyed by role), AzGuard is
 * action-centric, so the {@see AzGuardPermissionDriver}
 * maps a requested {@see Ability} to an AzGuard permission key (e.g. View →
 * documents.view) and asks the {@see AzGuardGuard} that permission.
 * Plain strings — cache-safe, no closures.
 */
#[Internal]
final readonly class AzGuardPermissionMap
{
    public function __construct(private AzGuardConfig $config) {}

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
