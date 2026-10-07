<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\Access\ProfileCreateGate;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The tus 1.0.0 protocol-correctness surface (§2.8 / P3.4 / B7): status codes and
 * discovery the P0.3 offset-lock did NOT own — offset-mismatch → 409 (not the bare
 * 302/422 a plain ValidationException renders to), Content-Type → 415, missing/wrong
 * Tus-Resumable → 412, OPTIONS discovery, and metadata whitelist + length caps. All
 * five behaviours are red-before this item (`plans/AUDIT-VLT/findings/P1.4-uploads.md`
 * F-P1.4-02/05/06/07/08).
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function protocolStorage(): Drive
{
    return Vaulter::for(DriveTestHelpers::host())->model();
}

it('offset-mismatch PATCH → 409 + Upload-Offset, НЕ 302/422 (F-P1.4-02, red-before: 302/422)', function (): void {
    $storage = protocolStorage();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'conflict.bin',
        'total_size_bytes' => 20,
    ])->json('data.upload_id');

    // First chunk lands at [0,5); the server is now at offset 5.
    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        '12345',
    )->assertNoContent();

    // A PATCH claiming offset 0 again with DIFFERENT bytes than what is already
    // staged there is a genuine desync — 409, carrying the authoritative offset.
    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        'xxxxx',
    )
        ->assertStatus(409)
        ->assertHeader('Upload-Offset', '5')
        ->assertHeader('Tus-Resumable', '1.0.0');
});

it('идемпотентный повтор совпадающего диапазона → 204, не 409 (F-P1.4-02)', function (): void {
    $storage = protocolStorage();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'retry.bin',
        'total_size_bytes' => 20,
    ])->json('data.upload_id');

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        '12345',
    )->assertNoContent();

    // A client retry of the SAME first chunk (it never saw the 204) — identical
    // bytes at the same range is an idempotent retransmit, not a conflict.
    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        '12345',
    )
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '5');
});

it('PATCH с неверным Content-Type → 415, НЕ 204 (F-P1.4-05, red-before: 204)', function (): void {
    $storage = protocolStorage();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'badtype.bin',
        'total_size_bytes' => 5,
    ])->json('data.upload_id');

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'text/plain', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        'abcde',
    )->assertStatus(415);
});

it('HEAD/PATCH без Tus-Resumable → 412 (F-P1.4-06, red-before: проходит)', function (): void {
    $storage = protocolStorage();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'noversion.bin',
        'total_size_bytes' => 5,
    ])->json('data.upload_id');

    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$uploadId)
        ->assertStatus(412)
        ->assertHeader('Tus-Version', '1.0.0');

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
        'abcde',
    )->assertStatus(412);
});

it('HEAD/PATCH с чужой версией Tus-Resumable → 412 (F-P1.4-06)', function (): void {
    $storage = protocolStorage();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'wrongversion.bin',
        'total_size_bytes' => 5,
    ])->json('data.upload_id');

    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$uploadId, server: ['HTTP_TUS_RESUMABLE' => '0.2.2'])
        ->assertStatus(412);
});

it('OPTIONS анонсирует Tus-Version/Tus-Extension/Tus-Max-Size (F-P1.4-07, red-before: нет роута)', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1_000_000);

    $this->call('OPTIONS', 'api/vaulter/resumable-uploads/any-literal-handle')
        ->assertNoContent()
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Tus-Version', '1.0.0')
        ->assertHeader('Tus-Extension', 'creation,termination')
        ->assertHeader('Tus-Max-Size', '1000000');
});

it('OPTIONS не анонсирует checksum-расширение (не реализовано, U1.5/U1.6)', function (): void {
    $this->call('OPTIONS', 'api/vaulter/resumable-uploads/any-literal-handle')
        ->assertHeader('Tus-Extension', 'creation,termination');
});

it('метаданные с неизвестным ключом → отброшены, с известным — сохранены (F-P1.4-08, red-before: принимаются)', function (): void {
    $storage = protocolStorage();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'meta.bin',
        'total_size_bytes' => 5,
        'metadata' => [
            'filename' => 'meta.bin',
            'not_a_real_key' => 'should be dropped',
        ],
    ])->json('data.upload_id');

    $session = UploadSession::query()
        ->where('upload_id', $uploadId)
        ->firstOrFail();

    expect(UploadTestHelpers::clientMetadata($session))->toBe(['filename' => 'meta.bin']);
});

it('значение метаданных сверх лимита длины — усекается, не отвергается (F-P1.4-08)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.metadata.max_value_length', 8);
    $storage = protocolStorage();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'meta-long.bin',
        'total_size_bytes' => 5,
        'metadata' => ['filename' => 'a-very-long-filename-indeed.bin'],
    ])->json('data.upload_id');

    $session = UploadSession::query()
        ->where('upload_id', $uploadId)
        ->firstOrFail();

    expect($session->metadata['filename'])->toBe('a-very-l')
        ->and(strlen((string) $session->metadata['filename']))->toBe(8);
});

it('gates tus initiation through the profile driver', function (bool $allow): void {
    $drive = protocolStorage();
    ProfileCreateGate::install($allow);
    $this->postJson('api/vaulter/storages/'.$drive->id.'/resumable-uploads', ['name' => 'profile.bin', 'total_size_bytes' => 5])
        ->assertStatus($allow ? 201 : 403);
})->with([true, false]);
