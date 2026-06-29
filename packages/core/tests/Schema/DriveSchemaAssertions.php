<?php

declare(strict_types=1);

namespace Vaulter\Tests\Schema;

use Illuminate\Support\Facades\Schema;

/**
 * Shared column/index assertions for the six M1a Drive tables, so each id
 * strategy can prove the base migrations apply and emit the same surface. The
 * tables are resolved through Config::table() so the prefix/rename map is honoured.
 */
final class DriveSchemaAssertions
{
    /**
     * @return array{0: string, 1: list<string>}[] entity table => required columns
     */
    public static function columnExpectations(): array
    {
        return [
            ['v_storages', [
                'id', 'panel', 'tenant_id', 'owner_type', 'owner_id', 'name', 'slug',
                'root_node_id', 'disk', 'storage_type', 'quota_bytes', 'used_bytes',
                'status', 'default_node_permissions', 'extra_data', 'created_by',
                'deleted_at', 'created_at', 'updated_at',
            ]],
            ['v_nodes', [
                'id', 'panel', 'tenant_id', 'storage_id', 'parent_id', 'kind', 'name',
                'mime_type', 'extension', 'size_bytes', 'current_version_id', 'file_id',
                'position', 'is_pinned', 'inherit_grants', 'shortcut_target_id',
                'owner_id', 'extra_data', 'trashed_at', 'trashed_by',
                'trashed_from_parent_id', 'created_by', 'updated_by', 'deleted_at',
                'created_at', 'updated_at',
            ]],
            ['v_blobs', [
                'id', 'tenant_id', 'storage_id', 'hash_algo', 'sha256', 'size_bytes',
                'disk', 'storage_key', 'status', 'ref_count', 'dedup_scope', 'etag',
                'md5', 'crc32c', 'chunked', 'encryption_key_id', 'first_seen_at',
                'committed_at', 'delete_after_at', 'extra_data',
            ]],
            ['v_files', [
                'id', 'tenant_id', 'node_id', 'current_version_id', 'mime_type',
                'size_bytes', 'version_count', 'extra_data', 'created_by',
                'deleted_at', 'created_at', 'updated_at',
            ]],
            ['v_file_versions', [
                'id', 'tenant_id', 'file_id', 'version_no', 'blob_id',
                'manifest_blob_id', 'content_sha256', 'size_bytes', 'mime_type',
                'original_name', 'status', 'keep_forever', 'extra_data', 'created_by',
                'created_at', 'committed_at',
            ]],
            ['v_file_version_blobs', [
                'file_version_id', 'blob_id', 'role', 'ordinal', 'offset_bytes',
                'length_bytes',
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
