<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The v_node_grants schema: the table, its columns, and the
 * grant-unique guard (one grant per subject/scope per node).
 */
it('creates the v_node_grants table with every column', function (): void {
    expect(Schema::hasTable('v_node_grants'))->toBeTrue()
        ->and(Schema::hasColumns('v_node_grants', [
            'id', 'tenant_id', 'node_id', 'subject_type', 'subject_id', 'role_key',
            'role_version', 'scope',
            'expires_at', 'granted_by',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('v_node_grants', ['level']))->toBeFalse()
        ->and(Schema::hasColumns('v_node_grants', ['permissions']))->toBeFalse()
        ->and(Schema::hasColumns('v_node_grants', ['effect']))->toBeFalse();
});

it('rejects a duplicate grant on the same (node, subject, scope)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'uniq');

    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    // SAVEPOINT the violating grant: on PostgreSQL the UNIQUE violation would otherwise
    // abort the enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(fn (): mixed => DB::transaction(
        fn (): NodeGrant => AccessTestHelpers::grant($folder, RoleRegistry::WRITER, scope: GrantScope::Item),
    ))->toThrow(UniqueConstraintViolationException::class);
});

it('allows an item and a subtree grant for the same subject (distinct scope)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'dual');

    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Subtree);

    expect(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(2);
});

it('re-grants after a hard delete', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'resurrect');

    $grant = AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);
    $grant->delete();

    // A new grant for the same key is permitted once the old one is deleted.
    $fresh = AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);

    expect($fresh->role_key)->toBe(RoleRegistry::OWNER)
        ->and(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(1);
});
