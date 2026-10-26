<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Schema;

use Illuminate\Support\Facades\Schema;

/**
 * Shared column/index assertions for the six M1a Drive tables, so each id
 * strategy can prove the base migrations apply and emit the same surface. The
 * tables are resolved through app(\Vaulter\Configuration\DatabaseConfig::class)->table() so the prefix/rename map is honoured.
 */
final class DriveSchemaAssertions
{
    /**
     * @return array{0: string, 1: list<string>}[] entity table => required columns
     */
    public static function columnExpectations(): array
    {
        return [
            ['v_drives', [
                'id', 'profile', 'tenant_id', 'owner_type', 'owner_id', 'name',
                'root_node_id', 'disk', 'quota_bytes', 'used_bytes',
                'status', 'created_by',
                'created_at', 'updated_at',
            ]],
            ['v_nodes', [
                'id', 'profile', 'tenant_id', 'drive_id', 'parent_id', 'kind', 'name',
                'name_key', 'mime_type', 'extension', 'size_bytes',
                'inherit_grants', 'shortcut_target_id', 'properties',
                'content_modified_at', 'content_modified_by', 'trashed_at', 'trashed_by',
                'trashed_from_parent_id', 'created_by', 'updated_by',
                'created_at', 'updated_at',
            ]],
            ['v_blobs', [
                'id', 'tenant_id', 'storage_id', 'hash_algo', 'sha256', 'size_bytes',
                'disk', 'storage_key', 'status', 'ref_count', 'dedup_scope', 'etag',
                'md5', 'crc32c', 'chunked', 'encryption_key_id', 'first_seen_at',
                'committed_at', 'delete_after_at',
            ]],
            ['v_blob_references', [
                'id', 'blob_id', 'owner_type', 'reference_key', 'created_at',
            ]],
            ['v_files', [
                'id', 'tenant_id', 'node_id', 'current_revision_id', 'mime_type',
                'size_bytes', 'revision_count', 'created_by',
                'created_at', 'updated_at',
            ]],
            ['v_file_revisions', [
                'id', 'tenant_id', 'file_id', 'number', 'blob_id',
                'manifest_blob_id', 'sha256', 'size_bytes', 'mime_type',
                'original_name', 'status', 'keep_forever', 'created_by',
                'created_at', 'committed_at', 'superseded_at', 'client_modified_at',
            ]],
            ['v_file_revision_blobs', [
                'file_revision_id', 'blob_id', 'role', 'ordinal', 'offset_bytes',
                'length_bytes',
            ]],
            ['v_node_grants', [
                'id', 'tenant_id', 'node_id', 'subject_type', 'subject_id', 'role_key',
                'role_version', 'scope',
                'expires_at', 'granted_by', 'created_at',
                'updated_at',
            ]],
        ];
    }

    /**
     * Assert every Drive table exists and carries every required column.
     */
    public static function assertAllTablesAndColumns(): void
    {
        foreach (self::columnExpectations() as [$table, $columns]) {
            expect(Schema::hasTable($table))->toBeTrue("table {$table} should exist");
            expect(Schema::hasColumns($table, $columns))
                ->toBeTrue("table {$table} should carry all M1a columns");
        }
    }
}
