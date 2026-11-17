<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Drive;

use Packman\Attributes\Internal;
use RuntimeException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Database\WriteScope;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Fixtures\Panels\FakeProject;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Shared builders for the Drive slice tests: a host entity with an id (not
 * persisted — the storage only stores its morph) and a canonical Vaulter::drive handle.
 */
#[Internal]
final class DriveTestHelpers
{
    private static int $next = 0;

    /**
     * A host entity with a fixed id, distinct per call so each test gets its own
     * storage (the owner-unique index forbids two storages for one owner+panel).
     */
    public static function host(?int $id = null): FakeProject
    {
        self::$next++;

        $project = new FakeProject;
        $project->forceFill(['id' => $id ?? self::$next]);
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
            ->where('node_id', $root->id)
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $id)
            ->where('scope', 'subtree')
            ->exists()) {
            AccessTestHelpers::grant(
                node: $root,
                role: RoleRegistry::OWNER,
                subjectId: $id,
                subjectType: $user->getMorphClass(),
            );
        }

        return $user;
    }

    /**
     * For tests that drive internal handlers and repositories directly: Vaulter's own entry points
     * (Operations, controllers, commands, jobs) open the write scope, these calls do not.
     */
    public static function insideOperation(): void
    {
        app(WriteScope::class)->enter();
    }

    /** The stored node row behind a snapshot (internal Actions and fixtures still take models). */
    public static function model(Node|NodeSnapshot|string $node): Node
    {
        if ($node instanceof Node) {
            return $node;
        }

        /** @var class-string<Node> $class */
        $class = config('vaulter-drive.models.node');

        return $class::query()->findOrFail(is_string($node) ? $node : $node->id);
    }

    /**
     * An in-memory read stream over the given bytes.
     *
     * @return resource
     */
    public static function streamFor(string $bytes): mixed
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open a memory stream.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /**
     * Every row of the write-side tables, ordered by each table's real key, to compare a state before and after an
     * operation that must not write. The keys are spelled out: SQLite reads an unknown quoted column as a string
     * literal, so `order by "id"` on a table without one only fails on the server engines.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function writeTablesSnapshot(): array
    {
        $keys = [
            'v_nodes' => ['id'], 'v_files' => ['id'], 'v_file_revisions' => ['id'],
            'v_file_revision_blobs' => ['file_revision_id', 'blob_id', 'role', 'ordinal'], 'v_blobs' => ['id'],
            'v_drives' => ['id'], 'v_activity' => ['id'], 'v_outbox' => ['outbox_seq'],
        ];
        $db = app(VaulterDatabase::class)->connection();
        $snapshot = [];
        foreach ($keys as $table => $columns) {
            $query = $db->table($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $rows = [];
            foreach ($query->get() as $row) {
                $columns = [];
                foreach (get_object_vars($row) as $column => $value) {
                    $columns[(string) $column] = $value;
                }
                $rows[] = $columns;
            }
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }
}
