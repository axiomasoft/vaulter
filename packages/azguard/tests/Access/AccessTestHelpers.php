<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\Access;

use Illuminate\Support\Carbon;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;

/**
 * Builders for the M2a ACL tests: a storage with a known tree, a subject user, and
 * a one-liner grant writer. Keeps the table-driven tests focused on the algorithm,
 * not on row plumbing.
 */
final class AccessTestHelpers
{
    /**
     * A fresh storage (and its root node) for a unique host entity.
     */
    public static function storage(): Drive
    {
        static $next = 0;
        $next++;

        $storage = new Drive;
        $storage->forceFill([
            'profile' => 'default',
            'owner_type' => 'project',
            'owner_id' => $next,
            'name' => 'ACL fixture drive',
            'disk' => 'local',
            'used_bytes' => 0,
        ])->save();

        $root = new Node;
        $root->forceFill([
            'drive_id' => $storage->id,
            'parent_id' => null,
            'kind' => NodeKind::Folder,
            'name' => '',
            'size_bytes' => 0,
        ])->save();

        $storage->forceFill(['root_node_id' => $root->id])->save();

        return $storage->refresh();
    }

    /**
     * A persisted test user with a fixed id (the grant subject / actor).
     */
    public static function user(int $id = 1): TestUser
    {
        $user = new TestUser;
        $user->forceFill(['id' => $id]);
        $user->exists = true;

        return $user;
    }

    /**
     * Create a folder node directly under a parent (or the storage root), bypassing
     * the Action so a test can shape an arbitrary tree without name-conflict noise.
     */
    public static function folder(Drive $storage, ?Node $parent, string $name, bool $inheritGrants = true): Node
    {
        $model = config('vaulter-drive.models.node');

        /** @var Node $node */
        $node = new $model;
        $node->forceFill([
            'drive_id' => $storage->id,
            'parent_id' => $parent?->id ?? $storage->root_node_id,
            'kind' => NodeKind::Folder,
            'name' => $name,
            'inherit_grants' => $inheritGrants,
        ])->save();

        return $node->refresh();
    }

    /**
     * Write a grant row directly (the resolver reads these). $subjectId defaults to
     * the canonical test user (id 1).
     */
    public static function grant(
        Node $node,
        string $role,
        GrantScope $scope = GrantScope::Subtree,
        int|string $subjectId = 1,
        ?string $subjectType = null,
        ?Carbon $expiresAt = null,
    ): NodeGrant {
        $subjectType ??= (new TestUser)->getMorphClass();

        $model = config('vaulter-drive.models.grant');

        /** @var NodeGrant $grant */
        $grant = new $model;
        $grant->forceFill([
            'node_id' => $node->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'role_key' => $role,
            'role_version' => 1,
            'scope' => $scope->value,
            'expires_at' => $expiresAt,
        ])->save();

        return $grant->refresh();
    }
}
