<?php

declare(strict_types=1);

namespace Vaulter\Tests\Access;

use Illuminate\Support\Carbon;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\MintedShareLink;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

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
        return Vaulter::drive(DriveTestHelpers::host())->model();
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
    public static function folder(Drive $storage, Node|NodeSnapshot|null $parent, string $name, bool $inheritGrants = true): Node
    {
        $model = config('vaulter.models.node');

        /** @var Node $node */
        $node = new $model;
        $node->forceFill([
            'drive_id' => $storage->id,
            'parent_id' => $parent?->id ?? $storage->root_node_id,
            'kind' => NodeKind::Folder,
            'name' => $name,
            'inherit_grants' => $inheritGrants,
        ]);
        app(VaulterDatabase::class)->write(static fn (): bool => $node->save());

        return $node->refresh();
    }

    /** The stored share-link row behind a minted link or a link snapshot, read fresh. */
    public static function shareRow(MintedShareLink|ShareLinkSnapshot|string $link): NodeShare
    {
        $id = is_string($link) ? $link : ($link instanceof MintedShareLink ? $link->link->id : $link->id);
        $model = config('vaulter.models.node_share');

        return $model::query()->findOrFail($id);
    }

    /** The stored grant row behind a grant snapshot, read fresh. */
    public static function grantRow(GrantSnapshot|string $grant): NodeGrant
    {
        $model = config('vaulter.models.grant');

        return $model::query()->findOrFail(is_string($grant) ? $grant : $grant->id);
    }

    /**
     * Write a grant row directly (the resolver reads these). $subjectId defaults to
     * the canonical test user (id 1).
     */
    public static function grant(
        Node|NodeSnapshot $node,
        string $role,
        GrantScope $scope = GrantScope::Subtree,
        int|string $subjectId = 1,
        ?string $subjectType = null,
        ?Carbon $expiresAt = null,
        int $roleVersion = 1,
    ): NodeGrant {
        $subjectType ??= (new TestUser)->getMorphClass();

        $model = config('vaulter.models.grant');

        /** @var NodeGrant $grant */
        $grant = new $model;
        $grant->forceFill([
            'node_id' => $node->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'role_key' => $role,
            'role_version' => $roleVersion,
            'scope' => $scope->value,
            'expires_at' => $expiresAt,
        ]);
        app(VaulterDatabase::class)->write(static fn (): bool => $grant->save());

        return $grant->refresh();
    }
}
