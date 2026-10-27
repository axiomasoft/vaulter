<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Documents\State\LegacyCollaborationBinding;
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
        'collab_state', 'collab_state_size', 'last_checkpoint_at', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('v_document_revisions', [
            'id', 'tenant_id', 'document_id', 'number', 'schema_version', 'content', 'content_hash', 'content_size',
            'collab_state', 'collab_state_size', 'state_version', 'label', 'is_autosave', 'keep_forever', 'status',
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
    aggregateRow('v_document_revisions', ['document_id' => $document, 'type' => 'vaulter/rich-text', 'number' => 1, 'schema_version' => 1]);
    $row = DB::table('v_documents')->where('id', $document)->first();

    expect([$row->collab_epoch, $row->state_version, $row->checkpoint_state_version, $row->revision_count])->toBe([0, 0, 0, 0])
        ->and(fn () => DB::transaction(fn () => aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => aggregateRow('v_document_revisions', ['document_id' => $document, 'type' => 'vaulter/rich-text', 'number' => 1, 'schema_version' => 1])))->toThrow(QueryException::class);

    foreach (['collab_epoch', 'state_version', 'checkpoint_state_version'] as $column) {
        expect(fn () => DB::transaction(fn () => DB::table('v_documents')->where('id', $document)->update([$column => -1])))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('v_document_revisions')->where('document_id', $document)->update(['state_version' => -1])))->toThrow(QueryException::class)
        ->and(DB::table('v_documents')->where('id', $document)->update(['state_version' => PHP_INT_MAX]))->toBe(1)
        ->and(DB::table('v_documents')->where('id', $document)->value('state_version'))->toBe(PHP_INT_MAX);
});

it('requires complete nonblank integer stored bindings on heads and revisions while allowing null checkpoints', function (): void {
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'binding');
    $document = aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1]);
    $revision = aggregateRow('v_document_revisions', ['document_id' => $document, 'type' => 'vaulter/rich-text', 'number' => 1, 'schema_version' => 1]);
    foreach (['v_documents' => $document, 'v_document_revisions' => $revision] as $table => $id) {
        DB::table($table)->where('id', $id)->update(['collab_protocol' => 'yjs', 'collab_state_format' => 'yjs-update-v1']);
        expect(DB::table($table)->where('id', $id)->value('collab_state'))->toBeNull();
        foreach ([['collab_protocol' => null], ['collab_state_format' => null], ['collab_protocol' => ''], ['collab_state_format' => " \t\n"], ['collab_protocol' => "\t\r"], ['schema_version' => 0], ['schema_version' => 1.5]] as $invalid) {
            expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update($invalid)))->toThrow(QueryException::class);
        }
        DB::table($table)->where('id', $id)->update(['collab_protocol' => null, 'collab_state_format' => null]);
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['collab_state' => 'unbound bytes'])))->toThrow(QueryException::class);
    }
});

it('requires every pending binding component and integer schema with nonblank identities', function (): void {
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'pending');
    $id = aggregateRow('v_documents', ['node_id' => $node->getKey(), 'type' => 'vaulter/rich-text', 'schema_version' => 1]);
    $binding = ['pending_protocol' => 'yjs', 'pending_state_format' => 'yjs-update-v1', 'pending_schema_version' => 1, 'pending_generation' => 0, 'pending_through_seq' => 1];
    DB::table('v_documents')->where('id', $id)->update($binding);
    foreach (array_keys($binding) as $column) {
        expect(fn () => DB::transaction(fn () => DB::table('v_documents')->where('id', $id)->update([$column => null])))->toThrow(QueryException::class);
    }
    foreach ([['pending_protocol' => " \t\n"], ['pending_state_format' => "\r\t"], ['pending_schema_version' => 1.5], ['pending_schema_version' => 0], ['pending_generation' => 0.5], ['pending_through_seq' => 1.5]] as $invalid) {
        expect(fn () => DB::transaction(fn () => DB::table('v_documents')->where('id', $id)->update($invalid)))->toThrow(QueryException::class);
    }
});

it('reports only unambiguous historical Yjs provenance without mutating facts', function (): void {
    $fact = ['source' => 'checkpoint', 'type' => 'vaulter/rich-text', 'protocol' => 'yjs', 'state_format' => 'yjs-update-v1', 'schema_version' => 1, 'generation' => 7];
    $evaluate = static fn ($type, $schema, $bytes, $pending, $facts) => LegacyCollaborationBinding::evaluate($type, $schema, 7, $bytes, $pending, $facts);
    foreach ([['bytes', false, [$fact]], [null, true, [array_replace($fact, ['source' => 'journal'])]], [null, false, [array_replace($fact, ['source' => 'seed'])]]] as [$bytes, $pending, $facts]) {
        $copy = $facts;
        $result = $evaluate('vaulter/rich-text', 1, $bytes, $pending, $facts);
        expect($result['accepted'])->toBeTrue()->and($result['binding']->schemaVersion)->toBe(1)->and($facts)->toBe($copy);
    }
    foreach ([['unknown/type', 1, 'bytes', false, [$fact]], ['vaulter/rich-text', null, 'bytes', false, [$fact]], ['vaulter/rich-text', 1, null, true, []], ['vaulter/rich-text', 1, null, false, [$fact]], ['vaulter/rich-text', 1, 'bytes', true, [$fact]], ['vaulter/rich-text', 1, 'bytes', false, [$fact, array_replace($fact, ['protocol' => 'acme-ot'])]], ['vaulter/rich-text', 1, 'bytes', false, [array_replace($fact, ['schema_version' => 2])]]] as [$type, $schema, $bytes, $pending, $facts]) {
        $copy = $facts;
        $result = $evaluate($type, $schema, $bytes, $pending, $facts);
        expect($result['accepted'])->toBeFalse()->and($result['binding'])->toBeNull()->and($result['reason'])->toBeString()->and($facts)->toBe($copy);
    }
});
