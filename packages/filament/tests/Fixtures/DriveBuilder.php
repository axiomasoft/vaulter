<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Access\GrantStoreRepository;

/**
 * Builders for the Filament resource tests: a fresh storage + a folder under its
 * root, a known acting user, and a one-liner grant writer (through the core store
 * repository, the same row the AccessResolver reads).
 */
final class DriveBuilder
{
    /**
     * A fresh storage (and its auto-provisioned root) for a unique host entity.
     */
    public static function storage(): Drive
    {
        static $next = 0;
        $next++;

        $host = new TestHost;
        $host->forceFill(['id' => $next]);
        $host->exists = true;

        return Vaulter::drive($host)->model();
    }

    /**
     * A folder node directly under the storage root (via the core Drive Action).
     */
    public static function folder(Drive $storage, string $name = 'Docs'): Node
    {
        return Vaulter::drive($storage)->asSystem('test fixture')->asSystem('test fixture')->folders()->create(name: $name);
    }

    /**
     * The acting panel admin (the grant subject / actor).
     */
    public static function user(int $id = 1): TestUser
    {
        $user = new TestUser;
        $user->forceFill(['id' => $id]);
        $user->exists = true;

        return $user;
    }

    /**
     * Grant a user a role on a node (the row the AccessResolver reads), written via
     * the core store repository so the test does not hand-roll the schema.
     */
    public static function grant(
        Node $node,
        TestUser $user,
        string $role = RoleRegistry::OWNER,
        GrantScope $scope = GrantScope::Subtree,
    ): NodeGrant {
        return app(GrantStoreRepository::class)->grant(
            node: $node,
            data: new GrantInput(
                subject: Subject::ofModel($user),
                role: $role,
                scope: $scope,
            ),
        );
    }
}
