<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The document aggregate schema (D34): v_documents holds the working state 1:1 with
 * its node, v_document_revisions the immutable checkpoints. Counters are signed
 * BIGINT that never go negative; a document has one aggregate and unique checkpoint
 * numbers; the 0.2 table and node pointers are gone.
 */
function aggregateRow(string $table, array $values): int|string
{
    return DB::table($table)->insertGetId($values);
}

it('creates the document aggregate tables with every column and no 0.2 leftovers', function (): void {
    expect(Schema::hasColumns('v_documents', [
        'id', 'tenant_id', 'node_id', 'type', 'schema_version', 'current_revision_id', 'revision_count',
        'collab_epoch', 'state_version', 'checkpoint_state_version', 'content', 'content_hash', 'content_size',
        'yjs_state', 'yjs_state_size', 'last_checkpoint_at', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('v_document_revisions', [
            'id', 'tenant_id', 'document_id', 'number', 'schema_version', 'content', 'content_hash', 'content_size',
            'yjs_state', 'yjs_state_size', 'state_version', 'label', 'is_autosave', 'keep_forever', 'status',
            'restored_from_revision_id', 'created_by', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('v_node_document_versions'))->toBeFalse();

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
        ->and(array_column(Schema::getIndexes('v_documents'), 'name'))->toContain('v_documents_node_uniq', 'v_documents_type_idx');
});

it('keeps one aggregate per node, unique checkpoint numbers and nonnegative counters', function (): void {
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'doc');
    $document = aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1]);
    aggregateRow('v_document_revisions', ['document_id' => $document, 'number' => 1, 'schema_version' => 1]);
    $row = DB::table('v_documents')->where('id', $document)->first();

    expect([$row->collab_epoch, $row->state_version, $row->checkpoint_state_version, $row->revision_count])->toBe([0, 0, 0, 0])
        ->and(fn () => DB::transaction(fn () => aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => aggregateRow('v_document_revisions', ['document_id' => $document, 'number' => 1, 'schema_version' => 1])))->toThrow(QueryException::class);

    foreach (['collab_epoch', 'state_version', 'checkpoint_state_version'] as $column) {
        expect(fn () => DB::transaction(fn () => DB::table('v_documents')->where('id', $document)->update([$column => -1])))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('v_document_revisions')->where('document_id', $document)->update(['state_version' => -1])))->toThrow(QueryException::class)
        ->and(DB::table('v_documents')->where('id', $document)->update(['state_version' => PHP_INT_MAX]))->toBe(1)
        ->and(DB::table('v_documents')->where('id', $document)->value('state_version'))->toBe(PHP_INT_MAX);
});
