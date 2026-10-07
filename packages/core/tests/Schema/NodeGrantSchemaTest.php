<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;

/**
 * The v_node_grants schema (master plan §2.6): the table, its columns, and the
 * live-row grant-unique guard (one allow + one deny per subject/scope per node,
 * soft-delete-aware).
 */
it('creates the v_node_grants table with every column', function (): void {
    expect(Schema::hasTable('v_node_grants'))->toBeTrue()
        ->and(Schema::hasColumns('v_node_grants', [
            'id', 'tenant_id', 'node_id', 'subject_type', 'subject_id', 'level',
            'permissions', 'effect', 'scope',
            'expires_at', 'granted_by',
        ]))->toBeTrue();
});

it('rejects a duplicate live grant on the same (node, subject, scope, effect)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'uniq');

    AccessTestHelpers::grant($folder, GrantLevel::Reader, scope: GrantScope::Item);

    // SAVEPOINT the violating grant: on PostgreSQL the UNIQUE violation would otherwise
    // abort the enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(fn (): mixed => DB::transaction(
        fn (): NodeGrant => AccessTestHelpers::grant($folder, GrantLevel::Writer, scope: GrantScope::Item),
    ))->toThrow(UniqueConstraintViolationException::class);
});

it('allows an allow AND a deny for the same subject/scope (distinct effect)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'dual');

    AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Allow, scope: GrantScope::Item);
    AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);

    expect(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(2);
});

it('re-grants after a hard delete', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'resurrect');

    $grant = AccessTestHelpers::grant($folder, GrantLevel::Reader, scope: GrantScope::Item);
    $grant->delete();

    // A new live grant for the same key is permitted once the old one is soft-deleted.
    $fresh = AccessTestHelpers::grant($folder, GrantLevel::Owner, scope: GrantScope::Item);

    expect($fresh->level)->toBe(GrantLevel::Owner)
        ->and(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(1);
});
