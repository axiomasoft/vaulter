<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Testing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Api;
use Vaulter\Azgard\Contracts\AzgardGuard;

/**
 * A configurable in-memory {@see AzgardGuard} so consumers (and this package's own
 * tests) can exercise the {@see AzgardPermissionDriver}
 * WITHOUT installing AzGuard (the src/Testing seam). It
 * lives in src/Testing/ (not tests/) so hosts can reference it from their own suites.
 *
 * It models the two AzGuard answers as plain data:
 *   - a super-admin flag (AzGuard's super-admin role: every check is allowed);
 *   - a set of (entity-key, permission) scoped grants (the per-resource lane);
 *   - a set of (contextType, contextId, permission) workspace grants (the
 *     hasPermissionIn lane the resolver actually drives, since AccessScope is node-blind).
 *
 * Grant a permission "globally" by passing it to a workspace check the resolver runs;
 * grant SuperAdmin to allow every check.
 */
#[Api]
final class FakeAzgardGuard implements AzgardGuard
{
    /**
     * @param bool                                    $superAdmin      the super-admin role
     * @param list<array{string, string}>             $scopedGrants    [entityKey, permission] pairs for the per-resource lane
     * @param list<array{string, int|string, string}> $workspaceGrants [contextType, contextId, permission] triples
     */
    public function __construct(
        private readonly bool $superAdmin = false,
        private readonly array $scopedGrants = [],
        private readonly array $workspaceGrants = [],
    ) {}

    public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool
    {
        if ($this->superAdmin) {
            return true;
        }

        $key = $this->entityKey($entity);

        foreach ($this->scopedGrants as [$grantedEntity, $grantedPermission]) {
            if ($grantedEntity === $key && $grantedPermission === $permission) {
                return true;
            }
        }

        return false;
    }

    public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool
    {
        if ($this->superAdmin) {
            return true;
        }

        foreach ($this->workspaceGrants as [$grantedType, $grantedId, $grantedPermission]) {
            if (
                $grantedType === $contextType
                && (string) $grantedId === (string) $contextId
                && $grantedPermission === $permission
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fresh guard granting one workspace permission (the common "this actor may X in
     * this workspace" fixture), kept terse for table-driven tests.
     */
    public static function grantingWorkspace(string $contextType, int|string $contextId, string $permission): self
    {
        return new self(workspaceGrants: [[$contextType, $contextId, $permission]]);
    }

    /**
     * A fresh super-admin guard (the override fixture).
     */
    public static function superAdmin(): self
    {
        return new self(superAdmin: true);
    }

    /**
     * The stable key a scoped grant is matched on — the host's entity reduced to a
     * morph-style "<class>:<key>" string when it exposes getMorphClass()/getKey(),
     * else its spl object hash. Keeps the per-resource lane checkable without coupling
     * to a concrete model type.
     */
    private function entityKey(object $entity): string
    {
        if (method_exists($entity, 'getMorphClass') && method_exists($entity, 'getKey')) {
            /** @var string $morph */
            $morph = $entity->getMorphClass();
            /** @var int|string|null $key */
            $key = $entity->getKey();

            return $morph.':'.(string) $key;
        }

        return spl_object_hash($entity);
    }
}
