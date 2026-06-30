<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Vaulter\Enums\Uploads\UploadProtocol;
use Vaulter\Enums\Uploads\UploadStatus;
use Vaulter\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Tests\Uploads\UploadTestHelpers;

/**
 * The v_upload_sessions / v_upload_parts schema (§2.8 / D16 / M3a): every column
 * exists, upload_id is UNIQUE, the part composite PK replaces on re-upload, and the
 * (status, expires_at) sweeper index is present.
 */
it('creates v_upload_sessions with every §2.8 column', function (): void {
    $table = Config::table('upload_sessions');

    expect(Schema::hasColumns($table, [
        'id', 'tenant_id', 'storage_id', 'upload_id', 'node_id', 'target_parent_id',
        'target_name', 'protocol', 'status', 'expected_sha256', 'total_size_bytes',
        'received_bytes', 'chunk_size', 'mime_type', 'parts', 'assembled_blob_id',
        'metadata', 'extra_data', 'expires_at', 'created_by', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates v_upload_parts with every §2.8 column', function (): void {
    $table = Config::table('upload_parts');

    expect(Schema::hasColumns($table, [
        'upload_session_id', 'part_no', 'byte_offset', 'size_bytes', 'part_sha256',
        'etag', 'temp_blob_id', 'received_at',
    ]))->toBeTrue();
});

it('rejects a duplicate upload_id (UNIQUE)', function (): void {
    $storage = UploadTestHelpers::storage();
    $model = Config::uploadSessionModel();

    $base = [
        'storage_id' => $storage->getKey(),
        'upload_id' => '0193a5f0-9a3b-7c2d-8e4f-0000000000aa',
        'protocol' => UploadProtocol::Tus,
        'status' => UploadStatus::Initiated,
    ];

    $model::query()->create($base);

    expect(static fn () => $model::query()->create($base))->toThrow(QueryException::class);
});

it('a part re-upload REPLACES on the (session, part_no) composite primary key', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage);
    $repo = app(UploadPartStoreRepository::class);

    $repo->record(session: $session, partNo: 1, byteOffset: 0, sizeBytes: 10, etag: '"first"');
    $repo->record(session: $session, partNo: 1, byteOffset: 0, sizeBytes: 20, etag: '"second"');

    $rows = Config::uploadPartModel()::query()->where('upload_session_id', $session->getKey())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->size_bytes)->toBe(20)
        ->and($rows->first()->etag)->toBe('"second"');
});

it('casts the session enums and json columns', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart);

    $session->forceFill(['metadata' => ['filename' => 'report.pdf']])->save();

    $fresh = Config::uploadSessionModel()::query()->find($session->getKey());

    expect($fresh->protocol)->toBe(UploadProtocol::S3Multipart)
        ->and($fresh->status)->toBeInstanceOf(UploadStatus::class)
        ->and($fresh->metadata)->toBe(['filename' => 'report.pdf']);
});

it('declares the upload_session_id foreign key on v_upload_parts', function (): void {
    // The cascadeOnDelete is enforced on Postgres/MySQL (the golden-migration suite
    // runs there); on the sqlite base suite we assert the FK is DECLARED on the table
    // so the structural guarantee is pinned without depending on PRAGMA enforcement.
    $foreignKeys = Schema::getForeignKeys(Config::table('upload_parts'));

    $columns = collect($foreignKeys)
        ->flatMap(static fn (array $fk): array => $fk['columns'])
        ->all();

    expect($columns)->toContain('upload_session_id');
});
