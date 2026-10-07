<?php

declare(strict_types=1);

namespace Vaulter\Tests\Drive;

use Vaulter\Drive\DriveHandle;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Panels\FakeProject;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Shared builders for the Drive slice tests (M1c): a host entity with an id (not
 * persisted — the storage only stores its morph) and a canonical Vaulter::drive handle.
 */
final class DriveTestHelpers
{
    /**
     * A host entity with a fixed id, distinct per call so each test gets its own
     * storage (the owner-unique index forbids two storages for one owner+panel).
     */
    public static function host(?int $id = null): FakeProject
    {
        static $next = 0;
        $next++;

        $project = new FakeProject;
        $project->forceFill(['id' => $id ?? $next]);
        $project->exists = true;

        return $project;
    }

    /**
     * Open a Drive handle for a fresh host entity.
     */
    public static function drive(?int $id = null): DriveHandle
    {
        return Vaulter::drive(self::host($id))->asSystem('test fixture');
    }

    /** A real actor with an explicit root grant for audited mutation fixtures. */
    public static function actor(DriveHandle $drive, int|string $id): TestUser
    {
        $user = new TestUser;
        $user->forceFill(['id' => $id]);
        $user->exists = true;
        $root = $drive->asSystem('test fixture')->root();
        if (!NodeGrant::query()
            ->where('node_id', $root->getKey())
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $id)
            ->where('scope', 'subtree')
            ->where('effect', 'allow')
            ->exists()) {
            AccessTestHelpers::grant(
                node: $root,
                level: GrantLevel::Owner,
                subjectId: $id,
                subjectType: $user->getMorphClass(),
            );
        }

        return $user;
    }

    /**
     * An in-memory read stream over the given bytes.
     *
     * @return resource
     */
    public static function streamFor(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
