<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The document aggregate schema: v_documents holds the working state 1:1 with
 * its node, v_document_revisions the immutable checkpoints. Counters are signed
 * BIGINT that never go negative; a document has one aggregate and unique checkpoint
 * numbers; the 0.2 table and node pointers are gone.
 */
function aggregateRow(string $table, array $values): int|string
{
    return DB::table($table)->insertGetId($values);
}

/**
 * An integer column never holds a fraction: the CHECK refuses one where a column could store it, and MySQL rounds it
 * into the INT column, so what it stores is still an integer.
 */
function aggregateKeepsInteger(string $table, int|string $id, string $column, float $fraction, string $key = 'id'): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        expect(fn () => app(VaulterDatabase::class)->transaction(fn () => DB::table($table)->where($key, $id)->update([$column => $fraction])))->toThrow(QueryException::class);

        return;
    }
    $before = DB::table($table)->where($key, $id)->value($column);
    DB::table($table)->where($key, $id)->update([$column => $fraction]);
    expect(DB::table($table)->where($key, $id)->value($column))->toBe((int) round($fraction));
    DB::table($table)->where($key, $id)->update([$column => $before]);
}

it('creates the document aggregate tables with every column and no 0.2 leftovers', function (): void {
    expect(Schema::hasColumns('v_documents', [
        'id', 'tenant_id', 'node_id', 'type', 'schema_version', 'current_revision_id', 'revision_count',
        'collab_epoch', 'state_version', 'checkpoint_state_version', 'write_mode', 'last_checkpoint_at', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('v_document_revisions', [
            'id', 'tenant_id', 'document_id', 'number', 'type', 'collab_epoch', 'schema_version', 'state_version', 'label', 'is_autosave', 'keep_forever', 'status',
            'restored_from_revision_id', 'created_by', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('v_node_document_versions'))->toBeFalse();

    foreach (['v_documents', 'v_document_revisions'] as $table) {
        foreach (['content', 'content_hash', 'content_size', 'collab_protocol', 'collab_state_format', 'collab_state', 'collab_state_size', 'pending_generation'] as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse($table.'.'.$column);
        }
    }
    foreach (['v_document_payloads' => 'document_id', 'v_document_revision_payloads' => 'revision_id'] as $table => $key) {
        expect(Schema::hasColumns($table, [$key, 'content', 'content_hash', 'content_size']))->toBeTrue()
            ->and(array_find(Schema::getIndexes($table), static fn (array $index): bool => $index['primary'])['columns'])->toBe([$key]);
    }
    foreach (['v_document_sync_states' => 'document_id', 'v_document_revision_sync_states' => 'revision_id'] as $table => $key) {
        expect(Schema::hasColumns($table, [$key, 'collab_protocol', 'collab_state_format', 'collab_state', 'collab_state_size']))->toBeTrue()
            ->and(array_find(Schema::getIndexes($table), static fn (array $index): bool => $index['primary'])['columns'])->toBe([$key]);
    }
    expect(Schema::hasColumn('v_document_revision_sync_states', 'pending_generation'))->toBeFalse()
        ->and(Schema::hasColumns('v_document_sync_states', ['pending_generation', 'pending_through_seq', 'pending_protocol', 'pending_state_format', 'pending_schema_version']))->toBeTrue();

    foreach (['node_id', 'version_no', 'snapshot_label', 'format', 'extra_data', 'updated_at'] as $column) {
        expect(Schema::hasColumn('v_document_revisions', $column))->toBeFalse($column);
    }
    foreach (['current_version_id', 'file_id', 'deleted_at'] as $column) {
        expect(Schema::hasColumn('v_nodes', $column))->toBeFalse($column);
    }

    $indexes = array_column(Schema::getIndexes('v_document_revisions'), null, 'name');
    expect($indexes['v_document_revisions_no_uniq']['columns'])->toBe(['document_id', 'number'])
        ->and($indexes['v_document_revisions_id_doc_uniq']['columns'])->toBe(['id', 'document_id'])
        ->and($indexes['v_document_revisions_history_idx']['columns'])->toBe(['document_id', 'is_autosave', 'created_at'])
        ->and(array_column(Schema::getIndexes('v_documents'), 'name'))->toContain('v_documents_node_uniq', 'v_documents_type_idx', 'v_documents_head_fk_idx')
        ->and(array_column(Schema::getIndexes('v_document_sync_states'), 'name'))->toContain('v_document_sync_states_pending_idx');
});

it('keeps one aggregate per node, unique checkpoint numbers and nonnegative counters', function (): void {
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'doc');
    $document = aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1]);
    aggregateRow('v_document_revisions', ['document_id' => $document, 'type' => 'vaulter/rich-text', 'number' => 1, 'schema_version' => 1]);
    $row = DB::table('v_documents')->where('id', $document)->first();

    expect($row->write_mode)->toBe('snapshot')
        ->and(fn () => app(VaulterDatabase::class)->transaction(fn () => DB::table('v_documents')->where('id', $document)->update(['write_mode' => 'JOURNAL'])))->toThrow(QueryException::class)
        ->and([$row->collab_epoch, $row->state_version, $row->checkpoint_state_version, $row->revision_count])->toBe([0, 0, 0, 0])
        ->and(fn () => app(VaulterDatabase::class)->transaction(fn (): int|string => aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1])))->toThrow(QueryException::class)
        ->and(fn () => app(VaulterDatabase::class)->transaction(fn (): int|string => aggregateRow('v_document_revisions', ['document_id' => $document, 'type' => 'vaulter/rich-text', 'number' => 1, 'schema_version' => 1])))->toThrow(QueryException::class);

    foreach (['collab_epoch', 'state_version', 'checkpoint_state_version'] as $column) {
        expect(fn () => app(VaulterDatabase::class)->transaction(fn () => DB::table('v_documents')->where('id', $document)->update([$column => -1])))->toThrow(QueryException::class);
    }
    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => DB::table('v_document_revisions')->where('document_id', $document)->update(['state_version' => -1])))->toThrow(QueryException::class)
        ->and(DB::table('v_documents')->where('id', $document)->update(['state_version' => PHP_INT_MAX]))->toBe(1)
        ->and(DB::table('v_documents')->where('id', $document)->value('state_version'))->toBe(PHP_INT_MAX);
});

it('requires complete payloads and optional, internally consistent sync rows on heads and revisions', function (): void {
    $db = app(VaulterDatabase::class);
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'binding');
    $document = aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1]);
    $revision = aggregateRow('v_document_revisions', ['document_id' => $document, 'type' => 'vaulter/rich-text', 'number' => 1, 'schema_version' => 1]);

    foreach ([
        ['v_documents', 'v_document_payloads', 'v_document_sync_states', 'document_id', $document],
        ['v_document_revisions', 'v_document_revision_payloads', 'v_document_revision_sync_states', 'revision_id', $revision],
    ] as [$head, $payload, $sync, $key, $id]) {
        $owner = [$key => $id];
        expect(fn () => $db->transaction(fn () => DB::table($head)->where('id', $id)->update(['schema_version' => 0])))->toThrow(QueryException::class);
        aggregateKeepsInteger($head, $id, 'schema_version', 1.5);

        DB::table($payload)->insert($owner);
        expect(fn () => $db->transaction(fn () => DB::table($payload)->where($owner)->update(['content_size' => 0])))->toThrow(QueryException::class)
            ->and(fn () => $db->transaction(fn () => DB::table($payload)->where($owner)->update(['content' => '{}', 'content_hash' => str_repeat('a', 64), 'content_size' => -1])))->toThrow(QueryException::class);

        expect(fn () => $db->transaction(fn () => DB::table($sync)->insert($owner)))->toThrow(QueryException::class);
        DB::table($sync)->insert($owner + ['collab_protocol' => 'yjs', 'collab_state_format' => 'yjs-update-v1']);
        expect(DB::table($sync)->where($owner)->value('collab_state'))->toBeNull();
        foreach ([['collab_protocol' => null], ['collab_state_format' => null], ['collab_protocol' => ''], ['collab_state_format' => " \t\n"], ['collab_protocol' => "\t\r"],
            ['collab_state' => 'bytes'], ['collab_state_size' => 5], ['collab_state' => 'bytes', 'collab_state_size' => 4],
            ['collab_protocol' => null, 'collab_state_format' => null, 'collab_state' => 'state', 'collab_state_size' => 5],
        ] as $invalid) {
            expect(fn () => $db->transaction(fn () => DB::table($sync)->where($owner)->update($invalid)))->toThrow(QueryException::class);
        }
        expect(DB::table($sync)->where($owner)->update(['collab_state' => 'bytes', 'collab_state_size' => 5]))->toBe(1);
    }

    DB::table('v_documents')->where('id', $document)->delete();
    foreach (['v_document_payloads', 'v_document_sync_states', 'v_document_revision_payloads', 'v_document_revision_sync_states'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

it('requires every pending binding component and permits a pending-only sync row', function (): void {
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'pending');
    $id = aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1]);
    $table = 'v_document_sync_states';
    $row = DB::table($table)->where('document_id', $id);
    $binding = ['pending_protocol' => 'yjs', 'pending_state_format' => 'yjs-update-v1', 'pending_schema_version' => 1, 'pending_generation' => 0, 'pending_through_seq' => 1];
    DB::table($table)->insert(['document_id' => $id, ...$binding]);
    foreach (array_keys($binding) as $column) {
        expect(fn () => app(VaulterDatabase::class)->transaction(fn () => $row->update([$column => null])))->toThrow(QueryException::class);
    }
    foreach ([['pending_protocol' => " \t\n"], ['pending_state_format' => "\r\t"], ['pending_schema_version' => 0], ['pending_generation' => -1], ['pending_through_seq' => 0]] as $invalid) {
        expect(fn () => app(VaulterDatabase::class)->transaction(fn () => $row->update($invalid)))->toThrow(QueryException::class);
    }
    foreach (['pending_schema_version' => 1.5, 'pending_generation' => 0.5, 'pending_through_seq' => 1.5] as $column => $fraction) {
        aggregateKeepsInteger($table, $id, $column, $fraction, 'document_id');
    }
});
