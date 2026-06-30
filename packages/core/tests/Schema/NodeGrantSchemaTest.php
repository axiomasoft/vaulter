<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Models\NodeGrant;
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
            'permissions', 'effect', 'scope', 'inherited', 'inherited_from_node_id',
            'expires_at', 'granted_by', 'extra_data', 'deleted_at',
        ]))->toBeTrue();
});

it('rejects a duplicate live grant on the same (node, subject, scope, effect)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'uniq');

    AccessTestHelpers::grant($folder, GrantLevel::Reader, scope: GrantScope::Item);

    expect(fn (): mixed => AccessTestHelpers::grant($folder, GrantLevel::Writer, scope: GrantScope::Item))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows an allow AND a deny for the same subject/scope (distinct effect)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'dual');

    AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Allow, scope: GrantScope::Item);
    AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);

    expect(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(2);
});

it('re-grants after a soft-delete (the partial unique exempts trashed rows)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'resurrect');

    $grant = AccessTestHelpers::grant($folder, GrantLevel::Reader, scope: GrantScope::Item);
    $grant->delete();

    // A new live grant for the same key is permitted once the old one is soft-deleted.
    $fresh = AccessTestHelpers::grant($folder, GrantLevel::Owner, scope: GrantScope::Item);

    expect($fresh->level)->toBe(GrantLevel::Owner)
        ->and(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(1);
});
